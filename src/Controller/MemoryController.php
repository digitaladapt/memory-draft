<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Dto\KeysQuery;
use App\Domain\Dto\RecallRequest;
use App\Domain\Dto\RememberRequest;
use App\Service\MemoryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The memory store over HTTP.
 *
 * One action per verb the `memory:*` commands already expose, so the command
 * line and the wire stay the same service underneath — the controllers are a
 * transport, not a second implementation. Every rule (a miss is explicit, a
 * stale write is kept and flagged, trimming demotes) is enforced in
 * {@see MemoryService} and holds identically for both callers; nothing is
 * re-decided here.
 *
 * Two deliberate consequences of that:
 *
 * - A recall that matches nothing is a **200**, not a 404. The service reports
 *   misses in the body with suggestions, so that a lookup which happens to miss
 *   is not indistinguishable from a lookup that could not be served. A 404 would
 *   collapse the two, and the caller would lose the suggestions with it.
 * - `forget` answers 404 when nothing matched, because there the absence is the
 *   point: a delete that deletes nothing is a caller mistake worth surfacing.
 *
 * Request bodies are bound with `#[MapRequestPayload]` and the query string
 * with `#[MapQueryString]`, per AGENTS.md, rather than decoded by hand. That is
 * only worth anything because the DTOs carry real constraints — a blank key or a
 * `mode` outside `append|replace` is a 422 before the service is entered.
 */
final class MemoryController extends AbstractController
{
    public function __construct(
        private readonly MemoryService $memory,
    ) {
    }

    /**
     * Look up one or more keywords.
     *
     * The response separates `hits` from `misses`. A hit carries its revision,
     * which is the token the caller echoes back on write; a miss carries
     * near-miss suggestions so the caller can recover instead of concluding the
     * store knows nothing.
     *
     * An entry may instead ask for the **latest** keys — `{"latest": true}` for
     * the service's default count, or `{"latest": N}` for an explicit one. Those
     * hits carry `"match": "recent"` rather than a match kind, because nothing
     * was matched against: the key is in the answer for when it was written. The
     * response then carries a `latest` block whose first line says what was
     * shown, so a default can never be mistaken for a deliberate recall.
     */
    #[Route('/api/recall', name: 'api_recall', methods: ['POST'])]
    public function recall(
        // `mapWhenEmpty` matters: without it an empty body would be mapped to a
        // default instance and validated *after* the fact, so `{}` would sail
        // through as a successful no-op recall. With it, an empty payload is
        // denormalized to `queries: []` and rejected by `Count(min: 1)` — a 422,
        // never a silent success.
        #[MapRequestPayload(acceptFormat: 'json', mapWhenEmpty: true)] RecallRequest $payload,
    ): JsonResponse {
        $queries = array_map(
            static fn ($query): array => [
                'key' => $query->key,
                'depth' => $query->depth,
                'includeCold' => $query->includeCold,
                // Only present when the entry actually asked for recency, so the
                // service's "an entry is a named lookup unless it says otherwise"
                // rule reads the same on the wire as it does in the API — the
                // distinction survives the transport rather than being flattened
                // into `false` and re-derived.
            ] + ($query->isLatest() ? ['latest' => $query->latestCount()] : []),
            $payload->queries,
        );

        return new JsonResponse($this->memory->recall($queries));
    }

    /**
     * Store one or more keywords.
     *
     * The whole batch is one transaction, so a rejected item rejects the batch
     * rather than half-writing it. The response reports what happened per item —
     * including any sentences demoted to cold or purged — so a write never
     * quietly stops guaranteeing something.
     */
    #[Route('/api/remember', name: 'api_remember', methods: ['POST'])]
    public function remember(
        // See `recall` for why `mapWhenEmpty`. A write with nothing in it must
        // not answer 200: `memory:remember` with no sentences is rejected at the
        // console for the same reason, and the wire should not be laxer.
        #[MapRequestPayload(acceptFormat: 'json', mapWhenEmpty: true)] RememberRequest $payload,
    ): JsonResponse {
        return new JsonResponse($this->memory->remember($payload->items));
    }

    /**
     * Enumerate the keyspace.
     *
     * The highest-value read in the service: the failure mode being designed
     * against is the caller guessing key names, and this replaces guessing with
     * looking.
     */
    #[Route('/api/keys', name: 'api_keys', methods: ['GET'])]
    public function keys(
        // `mapWhenEmpty` so `GET /api/keys` with no query string is the useful
        // default (everything, 200) rather than a 404. And 422 rather than the
        // attribute's default 404 for a *bad* limit: a malformed parameter is a
        // bad request, and reporting it as "not found" would send the caller
        // hunting for a route that exists.
        #[MapQueryString(mapWhenEmpty: true, validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        KeysQuery $query = new KeysQuery(),
    ): JsonResponse {
        return new JsonResponse(
            $this->memory->keys($query->pattern, $query->limit),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Store-wide counts and the active trimming budgets.
     */
    #[Route('/api/stats', name: 'api_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        return new JsonResponse(
            $this->memory->stats(),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Permanently delete a key.
     *
     * Its own route rather than a flag on a write, mirroring `memory:forget`
     * being its own command: the only destructive operation in the API should
     * not be reachable by mistyping a write. `key` is a path segment, not a
     * query parameter, because it names the resource being destroyed.
     *
     * The key is resolved through the same escalating match as a read, so
     * `ContextShuttle` deletes what `context-shuttle` names. That is intentional:
     * the store's whole identity model says those are one key, and a delete that
     * silently found nothing because of a hyphen would be the worst case of all.
     */
    #[Route('/api/keys/{key}', name: 'api_forget', methods: ['DELETE'], requirements: ['key' => '.+'])]
    public function forget(string $key): JsonResponse
    {
        $result = $this->memory->forget($key);

        if (0 === $result['deleted']) {
            return new JsonResponse(
                [
                    'error' => \sprintf('No key matched "%s".', $key),
                    'deleted' => 0,
                ],
                Response::HTTP_NOT_FOUND,
            );
        }

        return new JsonResponse($result);
    }
}
