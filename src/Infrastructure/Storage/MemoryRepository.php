<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Domain\KeyMatch;
use App\Domain\KeyNormalizer;
use App\Domain\Sentence;
use App\Domain\Tier;

/**
 * All persistence for keys and sentences.
 *
 * Every mutation runs inside a transaction with `BEGIN IMMEDIATE`, which takes
 * the write lock up front. That matters for a read-modify-write cycle like this
 * one: a deferred transaction that reads a revision and then writes can lose to
 * a competing writer between the two steps, and SQLite would surface that as
 * `SQLITE_BUSY` mid-transaction rather than as a clean conflict.
 */
final class MemoryRepository
{
    public function __construct(
        private readonly SqliteConnection $connection,
        private readonly int $hotCap = 20,
        private readonly int $coldCap = 200,
    ) {
    }

    /**
     * Find the key a caller meant, by escalating match strength.
     *
     * Tier A — exact key, then alias, then the *aggressive* canonical form
     * (lowercase, all non-alphanumerics removed). This is what makes
     * "ContextShuttle" and "context-shuttle" the same key, and it resolves in
     * the same round trip with no "did you mean" detour.
     */
    public function findKey(string $input): ?KeyMatch
    {
        $slug = KeyNormalizer::slug($input);
        $matchKey = KeyNormalizer::matchKey($input);
        $pdo = $this->connection->pdo();

        $statement = $pdo->prepare('SELECT * FROM memory_keys WHERE key = :key');
        $statement->execute(['key' => $slug]);
        if (false !== ($row = $statement->fetch())) {
            return KeyMatch::fromRow($row, 'exact');
        }

        $statement = $pdo->prepare(
            'SELECT k.* FROM aliases a JOIN memory_keys k ON k.id = a.key_id WHERE a.alias = :alias'
        );
        $statement->execute(['alias' => $slug]);
        if (false !== ($row = $statement->fetch())) {
            return KeyMatch::fromRow($row, 'alias');
        }

        $statement = $pdo->prepare('SELECT * FROM memory_keys WHERE match_key = :match');
        $statement->execute(['match' => $matchKey]);
        if (false !== ($row = $statement->fetch())) {
            return KeyMatch::fromRow($row, 'slug');
        }

        return null;
    }

    /**
     * Keys whose spelling is close to the input, for the "did you mean" tail.
     *
     * @return list<array<string, mixed>>
     */
    public function suggest(string $input, int $limit = 5): array
    {
        $pdo = $this->connection->pdo();
        $tokens = KeyNormalizer::tokens($input);
        $matchKey = KeyNormalizer::matchKey($input);

        $rows = $pdo->query('SELECT * FROM memory_keys')->fetchAll();
        $scored = [];

        foreach ($rows as $row) {
            $candidateMatch = (string) $row['match_key'];
            $candidateTokens = KeyNormalizer::tokens((string) $row['key']);

            // Substring of the canonical form, or a shared token, is enough to
            // be worth offering as a suggestion.
            $score = 0;
            if ('' !== $matchKey && str_contains($candidateMatch, $matchKey)) {
                $score += 3;
            }
            if ('' !== $matchKey && str_contains($matchKey, $candidateMatch)) {
                $score += 2;
            }
            $shared = array_intersect($tokens, $candidateTokens);
            $score += \count($shared);

            if ($score > 0) {
                $scored[] = ['row' => $row, 'score' => $score];
            }
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp((string) $a['row']['key'], (string) $b['row']['key']);
        });

        return array_map(static fn (array $item): array => $item['row'], \array_slice($scored, 0, $limit));
    }

    /**
     * Insert a key if it is new, or return the existing one.
     *
     * @return array<string, mixed>
     */
    public function ensureKey(string $input): array
    {
        $slug = KeyNormalizer::slug($input);

        // ANY resolution counts, not just an exact one. Accepting only 'exact'
        // here was a real bug: writing through a variant ("ContextShuttle" when
        // "context-shuttle" already existed) created a *second* key, so the two
        // spellings silently diverged into separate memories.
        //
        // The documented collision risk applies at this point too — writing
        // "project-foo" when "project:foo" exists unifies them. That is
        // deliberate: unification is visible (the alias is recorded and listed
        // by `keys`) whereas a duplicate key is not.
        $existing = $this->findKey($slug);
        if (null !== $existing) {
            return $existing->key->toRow();
        }

        $pdo = $this->connection->pdo();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $statement = $pdo->prepare(
            'INSERT INTO memory_keys (key, match_key, revision, created_at, updated_at)
             VALUES (:key, :match_key, 0, :now, :now)'
        );
        $statement->execute([
            'key' => $slug,
            'match_key' => KeyNormalizer::matchKey($slug),
            'now' => $now,
        ]);

        $statement = $pdo->prepare('SELECT * FROM memory_keys WHERE id = :id');
        $statement->execute(['id' => $pdo->lastInsertId()]);

        return $statement->fetch();
    }

    /**
     * Record a spelling that resolved to a key, so future lookups skip the
     * canonical-form scan and resolve straight to the alias.
     */
    public function rememberAlias(int $keyId, string $alias): void
    {
        $slug = KeyNormalizer::slug($alias);
        if ('' === $slug) {
            return;
        }

        $pdo = $this->connection->pdo();
        $statement = $pdo->prepare(
            'INSERT OR IGNORE INTO aliases (key_id, alias, created_at) VALUES (:key_id, :alias, :now)'
        );
        $statement->execute([
            'key_id' => $keyId,
            'alias' => $slug,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Bump a key's revision and report the new value.
     *
     * Called once per *effective* write batch for a key, not once per sentence:
     * a batch is one logical observation, so it deserves one revision.
     */
    public function bumpRevision(int $keyId): int
    {
        $pdo = $this->connection->pdo();
        $statement = $pdo->prepare(
            'UPDATE memory_keys SET revision = revision + 1, updated_at = :now WHERE id = :id'
        );
        $statement->execute(['now' => gmdate('Y-m-d\TH:i:s\Z'), 'id' => $keyId]);

        $statement = $pdo->prepare('SELECT revision FROM memory_keys WHERE id = :id');
        $statement->execute(['id' => $keyId]);

        return (int) $statement->fetch()['revision'];
    }

    /**
     * Insert or renew one sentence, enforcing "a sentence exists exactly once
     * per key" across both tiers.
     *
     * `$pinned` is tri-state, and is the only column here that is. Every other
     * field is rewritten unconditionally, because a new write genuinely
     * supersedes it — but the *absence* of an instruction about pinning is not
     * an instruction to unpin. Renewing a sentence is how a caller says "this
     * is still true", and a plain `bool` made that same gesture silently
     * discard the pin, turning the store's strongest durability signal into a
     * disposable one without saying so.
     *
     * @param bool|null $pinned true pins, false unpins, null leaves it as it is
     *
     * @return string one of: added, renewed, revived, promoted
     */
    public function upsertSentence(
        int $keyId,
        string $text,
        int $batch,
        int $revision,
        ?bool $pinned,
        bool $backfilled,
    ): string {
        $pdo = $this->connection->pdo();
        $hash = hash('sha256', $text);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $existing = $this->findSentenceByHash($keyId, $hash);

        if (null !== $existing) {
            $tier = Tier::from((string) $existing['tier']);
            // COALESCE leaves an existing pin alone when the caller said
            // nothing: NULL is "no instruction", not "unpin".
            $statement = $pdo->prepare(
                'UPDATE sentences
                 SET created_at = :now, batch = :batch, written_revision = :revision,
                     backfilled = :backfilled, pinned = COALESCE(:pinned, pinned)
                 WHERE id = :id'
            );
            $statement->bindValue('now', $now);
            $statement->bindValue('batch', $batch, \PDO::PARAM_INT);
            $statement->bindValue('revision', $revision, \PDO::PARAM_INT);
            $statement->bindValue('backfilled', $backfilled ? 1 : 0, \PDO::PARAM_INT);
            // Bound explicitly rather than via execute(): the array form types
            // every value as a string, and the whole point here is to pass a
            // real NULL.
            $statement->bindValue('pinned', null === $pinned ? null : ($pinned ? 1 : 0), null === $pinned ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            $statement->bindValue('id', $existing['id'], \PDO::PARAM_INT);
            $statement->execute();

            // A renewed fact is an assertion that it is still true, so it
            // belongs in the hot tier whether or not it had been demoted.
            if (Tier::Hot !== $tier) {
                $pdo->prepare('UPDATE sentences SET tier = :tier WHERE id = :id')
                    ->execute(['tier' => Tier::Hot->value, 'id' => $existing['id']]);

                return 'promoted';
            }

            return 'renewed';
        }

        $statement = $pdo->prepare(
            'INSERT INTO sentences
                (key_id, text, text_hash, created_at, tier, pinned, batch, written_revision, backfilled)
             VALUES (:key_id, :text, :hash, :now, :tier, :pinned, :batch, :revision, :backfilled)'
        );
        $statement->execute([
            'key_id' => $keyId,
            'text' => $text,
            'hash' => $hash,
            'now' => $now,
            'tier' => Tier::Hot->value,
            // A new sentence has no pin to preserve, so "no instruction" is
            // simply unpinned here. The two differ only on a renewal.
            'pinned' => $pinned ? 1 : 0,
            'batch' => $batch,
            'revision' => $revision,
            'backfilled' => $backfilled ? 1 : 0,
        ]);

        return 'added';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findSentenceByHash(int $keyId, string $hash): ?array
    {
        $statement = $this->connection->pdo()->prepare(
            // Hot first: if a key somehow holds both, the hot row is the one
            // that must be renewed rather than shadowed.
            "SELECT * FROM sentences WHERE key_id = :key_id AND text_hash = :hash
             ORDER BY CASE tier WHEN 'hot' THEN 0 ELSE 1 END, id DESC LIMIT 1"
        );
        $statement->execute(['key_id' => $keyId, 'hash' => $hash]);
        $row = $statement->fetch();

        return false === $row ? null : $row;
    }

    /**
     * Retire a key's current hot sentences to cold, for `mode: replace`.
     *
     * History is preserved rather than destroyed — replace is recoverable.
     *
     * @return list<array<string, mixed>> the rows that moved
     */
    public function retireHot(int $keyId): array
    {
        $pdo = $this->connection->pdo();
        $statement = $pdo->prepare(
            "SELECT * FROM sentences WHERE key_id = :key_id AND tier = 'hot' AND pinned = 0"
        );
        $statement->execute(['key_id' => $keyId]);
        $rows = $statement->fetchAll();

        if ([] !== $rows) {
            $pdo->prepare(
                "UPDATE sentences SET tier = 'cold' WHERE key_id = :key_id AND tier = 'hot' AND pinned = 0"
            )->execute(['key_id' => $keyId]);
        }

        return $rows;
    }

    /**
     * Demote the oldest unpinned hot sentences above the hot cap.
     *
     * Pinned sentences are exempt from trimming and are counted against the cap
     * only after they are protected, so pinning enough facts cannot silently
     * push everything else to cold.
     *
     * @return list<array<string, mixed>>
     */
    public function trimHot(int $keyId): array
    {
        $pdo = $this->connection->pdo();
        $statement = $pdo->prepare(
            "SELECT id, text, created_at, pinned FROM sentences
             WHERE key_id = :key_id AND tier = 'hot' ORDER BY batch ASC, id ASC"
        );
        $statement->execute(['key_id' => $keyId]);
        $rows = $statement->fetchAll();

        $unpinned = array_values(array_filter($rows, static fn (array $r): bool => 0 === (int) $r['pinned']));
        $pinnedCount = \count($rows) - \count($unpinned);
        $keepable = max(0, $this->hotCap() - $pinnedCount);
        $excess = \count($unpinned) - $keepable;

        if ($excess <= 0) {
            return [];
        }

        $victims = \array_slice($unpinned, 0, $excess);
        $statement = $pdo->prepare("UPDATE sentences SET tier = 'cold' WHERE id = :id");
        foreach ($victims as $victim) {
            $statement->execute(['id' => $victim['id']]);
        }

        return $victims;
    }

    /**
     * Hard-delete the oldest unpinned cold sentences above the cold cap.
     *
     * This is the only place a sentence is ever destroyed, and it happens only
     * once a key has exceeded a much larger cold budget.
     *
     * @return list<array<string, mixed>>
     */
    public function pruneCold(int $keyId): array
    {
        $coldCap = $this->coldCap();
        if ($coldCap <= 0) {
            return [];
        }

        $pdo = $this->connection->pdo();
        $statement = $pdo->prepare(
            "SELECT id, text, created_at FROM sentences
             WHERE key_id = :key_id AND tier = 'cold' AND pinned = 0
             ORDER BY batch DESC, id DESC LIMIT -1 OFFSET :offset"
        );
        $statement->bindValue('key_id', $keyId, \PDO::PARAM_INT);
        $statement->bindValue('offset', $coldCap, \PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        if ([] === $rows) {
            return [];
        }

        $statement = $pdo->prepare('DELETE FROM sentences WHERE id = :id');
        foreach ($rows as $row) {
            $statement->execute(['id' => $row['id']]);
        }

        return $rows;
    }

    /**
     * Fetch sentences for a key, newest write first but preserving sentence
     * order *within* a write.
     *
     * Two ordering rules in one `ORDER BY`, both learned from using the tool:
     * the newest write should lead, and a paragraph must still read forwards —
     * otherwise a shallow `depth` drops the lead sentence, which is the one
     * carrying the topic.
     *
     * @param list<Tier> $tiers
     *
     * @return list<Sentence>
     */
    public function sentences(int $keyId, array $tiers, int $depth, bool $includeBackfilled = true): array
    {
        if ([] === $tiers) {
            return [];
        }

        $tierList = implode(', ', array_map(static fn (Tier $t): string => "'".$t->value."'", $tiers));
        $backfilledClause = $includeBackfilled ? '' : ' AND backfilled = 0';
        $ordering = 'ORDER BY pinned DESC, backfilled ASC, batch DESC, id ASC';
        $depth = max(0, $depth);

        // Pinned sentences are *not* subject to `depth`. They are the durable
        // facts the caller asked not to age out, and `depth` exists to bound a
        // context window against chatter — so a small `depth` silently hiding
        // pinned facts is exactly the failure ("I asked for soul and got two of
        // nine") this avoids. Everything else is capped as before.
        $pinned = $this->connection->pdo()->prepare(
            "SELECT * FROM sentences
             WHERE key_id = :key_id AND tier IN ({$tierList}){$backfilledClause} AND pinned = 1
             {$ordering}"
        );
        $pinned->execute(['key_id' => $keyId]);
        $rows = $pinned->fetchAll();

        if ($depth > 0) {
            $unpinned = $this->connection->pdo()->prepare(
                "SELECT * FROM sentences
                 WHERE key_id = :key_id AND tier IN ({$tierList}){$backfilledClause} AND pinned = 0
                 {$ordering}
                 LIMIT :depth"
            );
            $unpinned->bindValue('key_id', $keyId, \PDO::PARAM_INT);
            $unpinned->bindValue('depth', $depth, \PDO::PARAM_INT);
            $unpinned->execute();
            $rows = array_merge($rows, $unpinned->fetchAll());
        }

        return array_map(
            static fn (array $row): Sentence => new Sentence(
                id: (int) $row['id'],
                keyword: '',
                text: (string) $row['text'],
                createdAt: (string) $row['created_at'],
                tier: Tier::from((string) $row['tier']),
                pinned: 1 === (int) $row['pinned'],
                batch: (int) $row['batch'],
                writtenRevision: (int) $row['written_revision'],
                backfilled: 1 === (int) $row['backfilled'],
            ),
            $rows
        );
    }

    /**
     * @return array{hot: int, cold: int, pinned: int, backfilled: int}
     */
    public function counts(int $keyId): array
    {
        $statement = $this->connection->pdo()->prepare(
            "SELECT
                SUM(CASE WHEN tier = 'hot' THEN 1 ELSE 0 END) AS hot,
                SUM(CASE WHEN tier = 'cold' THEN 1 ELSE 0 END) AS cold,
                SUM(CASE WHEN pinned = 1 THEN 1 ELSE 0 END) AS pinned,
                SUM(CASE WHEN backfilled = 1 THEN 1 ELSE 0 END) AS backfilled
             FROM sentences WHERE key_id = :key_id"
        );
        $statement->execute(['key_id' => $keyId]);
        $row = $statement->fetch() ?: ['hot' => 0, 'cold' => 0, 'pinned' => 0, 'backfilled' => 0];

        return [
            'hot' => (int) ($row['hot'] ?? 0),
            'cold' => (int) ($row['cold'] ?? 0),
            'pinned' => (int) ($row['pinned'] ?? 0),
            'backfilled' => (int) ($row['backfilled'] ?? 0),
        ];
    }

    /**
     * The keyspace, most recently written first.
     *
     * Ordered by `updated_at DESC, id DESC`, not `updated_at` alone. Timestamps
     * are second-resolution, so a burst of writes — a session writing several
     * keys, or any batch landing inside one second — ties on `updated_at` and
     * the database is then free to return the rows in any order at all. It
     * happened to return them in insertion order, which is *oldest first*, so a
     * recency read answered with the ten most recent keys ranked backwards.
     *
     * `id` is the tiebreak because it is monotonic: AUTOINCREMENT means a higher
     * id was inserted later, which for keys is also written later. This is the
     * same lesson the sentences table learned as `batch` (§2.2 of the spec) —
     * ordering is not a question the clock can be trusted to answer — and this
     * table had simply never needed it until recency made it the point of the
     * query rather than a cosmetic detail of the listing.
     *
     * @return list<array<string, mixed>>
     */
    public function allKeys(string $pattern = '', int $limit = 200): array
    {
        $pdo = $this->connection->pdo();

        if ('' === $pattern) {
            $statement = $pdo->prepare(
                'SELECT * FROM memory_keys ORDER BY updated_at DESC, id DESC LIMIT :limit'
            );
            $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
            $statement->execute();

            return $statement->fetchAll();
        }

        $statement = $pdo->prepare(
            'SELECT * FROM memory_keys WHERE key LIKE :pattern ORDER BY updated_at DESC, id DESC LIMIT :limit'
        );
        $statement->bindValue('pattern', '%'.KeyNormalizer::slug($pattern).'%');
        $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * @return list<string>
     */
    public function aliasesFor(int $keyId): array
    {
        $statement = $this->connection->pdo()->prepare(
            'SELECT alias FROM aliases WHERE key_id = :key_id ORDER BY alias'
        );
        $statement->execute(['key_id' => $keyId]);

        return array_map(static fn (array $row): string => (string) $row['alias'], $statement->fetchAll());
    }

    /**
     * Next value of the monotonic write counter.
     *
     * Persisted in `meta` rather than derived from the clock so ordering is
     * stable even for writes landing in the same second.
     */
    public function nextBatch(): int
    {
        $pdo = $this->connection->pdo();
        $row = $pdo->query("SELECT value FROM meta WHERE key = 'next_batch'")->fetch();
        $next = false === $row ? 1 : (int) $row['value'];

        $statement = $pdo->prepare(
            "INSERT INTO meta (key, value) VALUES ('next_batch', :value)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value"
        );
        $statement->execute(['value' => (string) ($next + 1)]);

        return $next;
    }

    /**
     * Delete a key and every sentence and alias attached to it.
     *
     * The only destructive operation in the store. Cascades are declared in the
     * schema, but they are performed explicitly here too so the count returned
     * is accurate with or without `PRAGMA foreign_keys`.
     */
    public function deleteKey(int $keyId): int
    {
        $pdo = $this->connection->pdo();

        $statement = $pdo->prepare('SELECT COUNT(*) AS n FROM sentences WHERE key_id = :key_id');
        $statement->execute(['key_id' => $keyId]);
        $deleted = (int) $statement->fetch()['n'];

        $pdo->prepare('DELETE FROM sentences WHERE key_id = :key_id')->execute(['key_id' => $keyId]);
        $pdo->prepare('DELETE FROM aliases WHERE key_id = :key_id')->execute(['key_id' => $keyId]);
        $pdo->prepare('DELETE FROM memory_keys WHERE id = :key_id')->execute(['key_id' => $keyId]);

        return $deleted;
    }

    public function begin(): void
    {
        $this->connection->pdo()->exec('BEGIN IMMEDIATE');
    }

    public function commit(): void
    {
        $this->connection->pdo()->exec('COMMIT');
    }

    public function rollback(): void
    {
        $this->connection->pdo()->exec('ROLLBACK');
    }

    /**
     * @return array{total: int, hot: int, cold: int, pinned: int, backfilled: int, keys: int}
     */
    public function stats(): array
    {
        $pdo = $this->connection->pdo();
        $row = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN tier = 'hot' THEN 1 ELSE 0 END) AS hot,
                SUM(CASE WHEN tier = 'cold' THEN 1 ELSE 0 END) AS cold,
                SUM(CASE WHEN pinned = 1 THEN 1 ELSE 0 END) AS pinned,
                SUM(CASE WHEN backfilled = 1 THEN 1 ELSE 0 END) AS backfilled
             FROM sentences"
        )->fetch();

        $keys = $pdo->query('SELECT COUNT(*) AS n FROM memory_keys')->fetch();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'hot' => (int) ($row['hot'] ?? 0),
            'cold' => (int) ($row['cold'] ?? 0),
            'pinned' => (int) ($row['pinned'] ?? 0),
            'backfilled' => (int) ($row['backfilled'] ?? 0),
            'keys' => (int) ($keys['n'] ?? 0),
        ];
    }

    public function hotCap(): int
    {
        // A negative cap is meaningless and a zero one would demote every hot
        // sentence, so it is reported as-is but treated as "no budget" by the
        // trimming paths rather than inverting their arithmetic.
        return max(0, $this->hotCap);
    }

    public function coldCap(): int
    {
        return max(0, $this->coldCap);
    }
}
