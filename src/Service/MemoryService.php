<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Dto\RememberItem;
use App\Domain\KeyNormalizer;
use App\Domain\MatchKind;
use App\Domain\MemoryKey;
use App\Domain\Sentence;
use App\Domain\SentenceSplitter;
use App\Domain\Tier;
use App\Domain\WriteIntent;
use App\Infrastructure\Storage\MemoryRepository;

/**
 * The memory application service.
 *
 * Two design commitments shape everything here:
 *
 * 1. **A miss is loud and helpful.** A keyword store fails silently when the
 *    caller guesses the wrong key, and silent failure is the dangerous kind
 *    because the caller proceeds confidently without the fact. So recall
 *    escalates match strength, resolves spelling variants in the *same round
 *    trip*, and falls back to explicit suggestions rather than an empty result.
 *
 * 2. **Nothing is silently discarded.** Trimming demotes to cold. A write from a
 *    stale revision is kept and flagged as backfilled rather than rejected.
 *    `replace` retires history rather than destroying it. The only deletion is
 *    past the cold cap, and it is reported when it happens.
 */
final class MemoryService
{
    /**
     * Sentences returned when a caller does not ask for a specific depth.
     *
     * Sized to cover a typical hot window rather than the old minimum of 2, so
     * the default answer to "what do I know about X?" is the key's current
     * knowledge rather than a two-sentence prefix of it. A caller that wants
     * fewer says so; a caller that did not read the docs should not be shown a
     * third of the answer. Pinned sentences are returned in addition to this,
     * whatever it is set to.
     */
    public const DEFAULT_DEPTH = 12;

    public function __construct(
        private readonly MemoryRepository $repository,
    ) {
    }

    /**
     * Look up one or more keywords.
     *
     * Each keyword resolves independently, so one miss does not spoil the batch.
     *
     * @param list<array{key: string, depth?: int|null, includeCold?: bool}> $queries
     *
     * @return array{hits: list<array<string, mixed>>, misses: list<array<string, mixed>>}
     */
    public function recall(array $queries): array
    {
        $hits = [];
        $misses = [];

        foreach ($queries as $query) {
            $depth = max(1, $query['depth'] ?? self::DEFAULT_DEPTH);
            $includeCold = $query['includeCold'] ?? false;
            $found = $this->repository->findKey($query['key']);

            if (null === $found) {
                $misses[] = [
                    'key' => $query['key'],
                    'suggestions' => array_map(
                        static fn (array $row): string => (string) $row['key'],
                        $this->repository->suggest($query['key']),
                    ),
                ];

                continue;
            }

            $hits[] = $this->describe(
                $found->key,
                $found->kind,
                $query['key'],
                $depth,
                $includeCold,
            );
        }

        return ['hits' => $hits, 'misses' => $misses];
    }

    /**
     * Write one or more keywords.
     *
     * @param list<RememberItem> $items
     *
     * @return array{results: list<array<string, mixed>>, purged: list<array<string, mixed>>}
     */
    public function remember(array $items, string $defaultMode = 'append'): array
    {
        $results = [];
        $purgedAll = [];
        $batch = $this->repository->nextBatch();

        $this->repository->begin();

        try {
            foreach ($items as $item) {
                $mode = '' !== $item->mode ? $item->mode : $defaultMode;
                $sentences = SentenceSplitter::normalize($item->sentences ?? []);

                // Resolve emptiness BEFORE touching the store: a write with
                // nothing in it must not bring a key into existence, or every
                // no-op call would leave an empty key behind as noise.
                if ([] === $sentences) {
                    $results[] = [
                        'key' => KeyNormalizer::slug($item->key),
                        'mode' => $mode,
                        'intent' => WriteIntent::Current->value,
                        'revision' => 0,
                        'added' => 0,
                        'renewed' => 0,
                        'promoted' => 0,
                        'retired' => [],
                        'evicted' => [],
                        'purged' => [],
                        'empty' => true,
                    ];

                    continue;
                }

                $keyRow = $this->repository->ensureKey($item->key);
                $key = MemoryKey::fromRow($keyRow);

                $intent = $item->intent($key->revision);
                $backfilled = WriteIntent::Backfill === $intent;

                $result = [
                    'key' => $key->key,
                    'mode' => $mode,
                    'intent' => $intent->value,
                    'revision' => $key->revision,
                    'added' => 0,
                    'renewed' => 0,
                    'promoted' => 0,
                    'retired' => [],
                    'evicted' => [],
                    'purged' => [],
                    // Reaching here means the batch had content: the empty
                    // case returned earlier, before any key was created.
                    'empty' => false,
                ];

                if ('replace' === $mode && !$backfilled) {
                    $result['retired'] = $this->formatRows($this->repository->retireHot($key->id));
                }

                // One revision per logical batch. Bumping even for a backfill
                // records that the key was touched, while the sentences
                // themselves are flagged so ranking still prefers current
                // knowledge.
                $newRevision = $this->repository->bumpRevision($key->id);
                $result['revision'] = $newRevision;

                foreach ($sentences as $sentence) {
                    $outcome = $this->repository->upsertSentence(
                        keyId: $key->id,
                        text: (string) $sentence,
                        batch: $batch,
                        revision: $newRevision,
                        pinned: $item->pin,
                        backfilled: $backfilled,
                    );
                    ++$result[$outcome];
                }

                $result['evicted'] = $this->formatRows($this->repository->trimHot($key->id));
                $purged = $this->formatRows($this->repository->pruneCold($key->id));
                $result['purged'] = $purged;

                foreach ($purged as $row) {
                    $purgedAll[] = ['key' => $key->key] + $row;
                }

                // Record the spelling the caller actually used, so a repeated
                // near-miss resolves directly next time instead of re-scanning.
                if ($key->key !== $item->key) {
                    $this->repository->rememberAlias($key->id, $item->key);
                }

                $results[] = $result;
            }

            $this->repository->commit();
        } catch (\Throwable $exception) {
            $this->repository->rollback();

            throw $exception;
        }

        return ['results' => $results, 'purged' => $purgedAll];
    }

    /**
     * Explicitly delete a key's sentences.
     *
     * The only destructive verb in the API, and deliberately a separate call so
     * that nothing routine can reach it by accident.
     *
     * @return array{key: string, deleted: int}
     */
    public function forget(string $key): array
    {
        $found = $this->repository->findKey($key);
        if (null === $found) {
            return ['key' => $key, 'deleted' => 0];
        }

        $repository = $this->repository;
        $repository->begin();

        try {
            $deleted = $repository->deleteKey($found->key->id);
            $repository->commit();
        } catch (\Throwable $exception) {
            $repository->rollback();

            throw $exception;
        }

        return ['key' => $found->key->key, 'deleted' => $deleted];
    }

    /**
     * The keyspace, so a caller can discover what it is able to ask for.
     *
     * This is the highest-value read in the whole service: without it the caller
     * is guessing key names, and guessing is exactly what a keyword store
     * punishes.
     *
     * @return list<array<string, mixed>>
     */
    public function keys(string $pattern = '', int $limit = 200): array
    {
        $out = [];
        foreach ($this->repository->allKeys($pattern, $limit) as $row) {
            $key = MemoryKey::fromRow($row);
            $counts = $this->repository->counts($key->id);
            $out[] = [
                'key' => $key->key,
                'revision' => $key->revision,
                'hot' => $counts['hot'],
                'cold' => $counts['cold'],
                // Reported so a caller can see which keys are mostly pinned
                // without recalling each one: pinned sentences are exempt from
                // both the hot cap and `depth`, so their count is the reason a
                // key can hold more than the cap suggests.
                'pinned' => $counts['pinned'],
                'backfilled' => $counts['backfilled'],
                'aliases' => $this->repository->aliasesFor($key->id),
                'last_written' => SentenceSplitter::humanizeAge($key->updatedAt),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        return $this->repository->stats() + [
            'caps' => [
                'hot_per_key' => $this->repository->hotCap(),
                'cold_per_key' => $this->repository->coldCap(),
            ],
        ];
    }

    /**
     * Build the hit payload for a resolved key.
     *
     * The revision is included on every hit because it is the token the caller
     * echoes back on write; a caller that never sees it can never supply it.
     *
     * @return array<string, mixed>
     */
    private function describe(
        MemoryKey $key,
        MatchKind $kind,
        string $requested,
        int $depth,
        bool $includeCold,
    ): array {
        $tiers = [Tier::Hot];
        if ($includeCold) {
            $tiers[] = Tier::Cold;
        }

        $sentences = $this->repository->sentences($key->id, $tiers, $depth);
        $counts = $this->repository->counts($key->id);

        $payload = [
            'key' => $key->key,
            'match' => $kind->value,
            'revision' => $key->revision,
            'hot' => $counts['hot'],
            'cold' => $counts['cold'],
            // Pinned rows are always returned regardless of `depth`, so the
            // count explains a `shown` larger than the requested depth.
            'pinned' => $counts['pinned'],
            'backfilled' => $counts['backfilled'],
            'shown' => \count($sentences),
            'entries' => array_map(
                static fn (Sentence $sentence): array => [
                    'text' => $sentence->text,
                    'age' => SentenceSplitter::humanizeAge($sentence->createdAt),
                    'tier' => $sentence->tier->value,
                    'pinned' => $sentence->pinned,
                    'backfilled' => $sentence->backfilled,
                ],
                $sentences,
            ),
        ];

        // A resolved spelling variant is reported rather than hidden, so the
        // caller can adopt the canonical key and stop relying on resolution.
        if ($kind->isHit() && $requested !== $key->key) {
            $payload['resolved_from'] = $requested;
        }

        // Alternate spellings recorded earlier. Surfacing them lets the caller
        // adopt the canonical key instead of relying on resolution each time.
        $aliases = $this->repository->aliasesFor($key->id);
        if ([] !== $aliases) {
            $payload['aliases'] = $aliases;
        }

        return $payload;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function formatRows(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'text' => (string) $row['text'],
                'age' => SentenceSplitter::humanizeAge((string) $row['created_at']),
            ],
            $rows
        );
    }
}
