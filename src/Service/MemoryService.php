<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Dto\RecallQuery;
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

    /**
     * Recent keys returned when a caller asks for `latest` without a count.
     *
     * Eight covers two or three sessions on a lively day. Five would push a
     * quiet conversation under a single ordinary day of mail-summary writes, and
     * the whole point of a recency read is that yesterday's conversation is
     * still inside the window. It is a service constant rather than a client
     * default so that tuning it needs no client release — which is only true if
     * callers can say `latest: true`.
     */
    public const LATEST_COUNT = 8;

    /**
     * Sentences returned per key on a recency read.
     *
     * Three is enough for a "where were we" read; anything longer belongs in a
     * recall by name, where the caller has actually asked about the subject.
     *
     * This is a default, not a separate dial: an entry may override it with the
     * same `depth` field a named entry uses, so the request shape stays one
     * field wide. The count is what a recency question has an opinion about —
     * "how much recent" — while `depth` remains what it always was, "how much of
     * each".
     */
    public const LATEST_DEPTH = 3;

    /**
     * Upper bound on a recency read's depth.
     *
     * The recency response is destined for a context window, so its bound is
     * context-window-shaped. {@see MemoryRepository::allKeys()}'s 1000 ceiling is
     * sized for *discovery* — a caller reading a pattern out of the store — and
     * is far too large to multiply by a key count here.
     */
    public const LATEST_DEPTH_MAX = 12;

    public function __construct(
        private readonly MemoryRepository $repository,
    ) {
    }

    /**
     * Look up one or more keywords.
     *
     * Each keyword resolves independently, so one miss does not spoil the batch.
     *
     * An entry whose `latest` is not `false` asks a different question — *what
     * was written most recently?* — instead of naming a key. That expansion runs
     * **after** every named entry, for two reasons: the answer reads
     * predictably (the keys you asked for, then the recent ones) regardless of
     * where the recency entry sat in the batch, and a key that is both named and
     * recent is attributed to the named occurrence, which is the stronger claim.
     *
     * @param list<array{key?: string, depth?: int|null, includeCold?: bool, latest?: bool|int}> $queries
     *
     * @return array{hits: list<array<string, mixed>>, misses: list<array<string, mixed>>, latest?: array<string, mixed>}
     */
    public function recall(array $queries): array
    {
        $hits = [];
        $misses = [];
        $latest = null;
        $latestDepth = null;
        $named = [];

        foreach ($queries as $query) {
            // Resolved before the key is read, because `latest` changes what
            // "this entry" means: it is not a lookup that happened to omit a
            // key, it is a different question.
            $spec = $query['latest'] ?? false;
            if (false !== $spec) {
                // First one wins. The DTO refuses a second over the wire; a
                // direct caller passing two gets the first rather than a
                // silently merged count.
                $latest ??= $spec;
                // The entry's own `depth`, if it gave one, is how deep the
                // expansion goes — the same field a named entry uses, so there
                // is no second dial to learn.
                $latestDepth ??= $query['depth'] ?? null;

                continue;
            }

            $key = $query['key'] ?? '';
            $depth = max(1, $query['depth'] ?? self::DEFAULT_DEPTH);
            $includeCold = $query['includeCold'] ?? false;
            $found = $this->repository->findKey($key);

            if (null === $found) {
                $misses[] = [
                    'key' => $key,
                    'suggestions' => array_map(
                        static fn (array $row): string => (string) $row['key'],
                        $this->repository->suggest($key),
                    ),
                ];

                continue;
            }

            // Tracked by canonical key, not by what was typed: `soul` and
            // `Soul` are one key here, and the recency expansion must not add it
            // back a second time under the other spelling.
            $named[$found->key->key] = true;

            $hits[] = $this->describe(
                $found->key,
                $found->kind,
                $key,
                $depth,
                $includeCold,
            );
        }

        if (null !== $latest) {
            [$expanded, $meta] = $this->recent($latest, $latestDepth, $named, [] !== $hits);
            $hits = array_merge($hits, $expanded);

            return ['hits' => $hits, 'misses' => $misses, 'latest' => $meta];
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
                        // Tri-state: null means "no instruction about the
                        // pin", which on a renewal preserves the existing one.
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
     * The recency expansion: the most recently written keys, newest first.
     *
     * Recency is free — `allKeys()` already orders `updated_at DESC` and every
     * effective write stamps it — so this is a reuse of the keyspace read rather
     * than a new query, and needs no migration.
     *
     * The unit of "what changed" is the **key**, not the sentence. One chatty
     * key must not own the answer: `email-summary` is routinely past revision
     * 40, and a "N newest sentences overall" design would let it take every slot
     * forever. So breadth comes from N keys, and depth from D sentences each.
     *
     * Pinned sentences come along as they always do — exempt from `depth` — which
     * here is a feature rather than a leak: a key whose durable facts are pinned
     * describes itself well on a recency read.
     *
     * @param bool|int            $spec     true for the service default, or an explicit count
     * @param int|null            $depth    the entry's own depth, if it asked for one
     * @param array<string, true> $named    canonical keys already answered by name
     * @param bool                $hadNamed whether the batch named any key at all
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function recent(bool|int $spec, ?int $depth, array $named, bool $hadNamed): array
    {
        // Clamped rather than trusted. The wire validates both dials, but the
        // service is also called directly (and from the console), where a `0`
        // count would make the collection loop below unreachable-by-equality and
        // quietly return every key in the store.
        $count = min(RecallQuery::LATEST_MAX, max(1, \is_bool($spec) ? self::LATEST_COUNT : $spec));
        $depth = min(self::LATEST_DEPTH_MAX, max(1, $depth ?? self::LATEST_DEPTH));

        // Over-fetched by the number of named keys so that removing them still
        // leaves `count` slots to fill. A named key that is not recent consumes
        // nothing; one that is, costs a slot that this fetch already paid for.
        $fetch = $count + \count($named);

        $rows = [];
        foreach ($this->repository->allKeys('', $fetch) as $row) {
            $key = MemoryKey::fromRow($row);
            if (isset($named[$key->key])) {
                continue;
            }

            $rows[] = $key;
            if (\count($rows) === $count) {
                break;
            }
        }

        $hits = [];
        foreach ($rows as $key) {
            $hits[] = $this->describe($key, MatchKind::Recent, null, $depth, false);
        }

        // `available` excludes the keys this batch already returned by name, so
        // `included < count` means exactly one thing — the store had fewer keys
        // than were asked for — rather than being confounded by de-duplication.
        $available = max(0, $this->repository->stats()['keys'] - \count($named));

        $meta = [
            'note' => $this->recencyNote($count, \is_bool($spec), $hadNamed, $rows, \count($hits), $available),
            'count' => $count,
            'depth' => $depth,
            'included' => \count($hits),
            'available' => $available,
            'scope' => Tier::Hot->value,
        ];

        if ([] !== $rows) {
            $meta['newest'] = SentenceSplitter::humanizeAge($rows[0]->updatedAt);
            $meta['oldest'] = SentenceSplitter::humanizeAge($rows[\count($rows) - 1]->updatedAt);
        }

        return [$hits, $meta];
    }

    /**
     * The first line of a recency response, authored by the service.
     *
     * The failure mode of a default is a caller mistaking it for a deliberate
     * recall, so the answer states plainly that it was a recency read, how many
     * keys it covers, and whether that number was the caller's or the service's.
     * Written here rather than by each transport so the console and the wire
     * cannot disagree about what happened.
     *
     * @param list<MemoryKey> $rows
     */
    private function recencyNote(
        int $count,
        bool $defaulted,
        bool $hadNamed,
        array $rows,
        int $included,
        int $available,
    ): string {
        $lead = $hadNamed ? 'Also showing the' : 'No keys given — showing the';
        $dial = $defaulted ? ' (the service default)' : '';
        $keys = 1 === $count ? 'most recently written key' : 'most recently written keys';

        if ([] === $rows) {
            return \sprintf(
                '%s %d %s%s. The store holds no keys%s.',
                $lead,
                $count,
                $keys,
                $dial,
                $hadNamed ? ' beyond the ones named' : '',
            );
        }

        return \sprintf(
            '%s %d %s%s: %d included of %d available, newest %s, oldest %s.',
            $lead,
            $count,
            $keys,
            $dial,
            $included,
            $available,
            SentenceSplitter::humanizeAge($rows[0]->updatedAt),
            SentenceSplitter::humanizeAge($rows[\count($rows) - 1]->updatedAt),
        );
    }

    /**
     * Build the hit payload for a resolved key.
     *
     * The revision is included on every hit because it is the token the caller
     * echoes back on write; a caller that never sees it can never supply it.
     *
     * `$requested` is null on a recency hit, and that is how `resolved_from` is
     * suppressed: nothing was requested by name, so claiming a spelling was
     * resolved would invent a rationale the caller never gave.
     *
     * @return array<string, mixed>
     */
    private function describe(
        MemoryKey $key,
        MatchKind $kind,
        ?string $requested,
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
            // The one place a caller *needs* staleness at a glance: a recency
            // read is an answer about time, and "5h ago" on the key itself is
            // the cheapest way to see that the newest thing in the store is
            // already yesterday. Useful on an ordinary recall for the same
            // reason the per-sentence ages are.
            'last_written' => SentenceSplitter::humanizeAge($key->updatedAt),
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
        if ($kind->isHit() && null !== $requested && $requested !== $key->key) {
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
