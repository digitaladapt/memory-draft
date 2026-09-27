<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A keyword record: the identity of a key plus its revision counter.
 *
 * The revision is a per-key monotonic counter, bumped on every *effective*
 * write. It is the concurrency control for the read-modify-write cycle:
 *
 *   1. caller asks for `context-shuttle`, receives `revision: 8`
 *   2. caller writes, passing `revision: 8`
 *   3. if nothing else wrote in between the number still matches and the write
 *      applies cleanly; if something else wrote, the number is stale
 *
 * A stale revision is **not** rejected. That was the key design decision: the
 * caller is a language model that may have been reasoning from what it read, and
 * silently dropping its write loses information that the other writer did not
 * have. Instead the sentence is stored *flagged as backfilled* and ranked below
 * current knowledge — kept, but marked as written against an older picture.
 *
 * `revision` is optional on write. A caller that did not read first passes
 * nothing and is treated as current (see {@see WriteIntent}).
 */
final class MemoryKey
{
    public function __construct(
        public readonly int $id,
        public readonly string $key,
        /** Aggressive canonical form; the tier-A match identity. */
        public readonly string $matchKey,
        public readonly int $revision,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * The row shape this object was built from.
     *
     * Kept so the repository can hand a resolved key back to code that still
     * works in arrays, without every caller re-deriving the column names.
     *
     * @return array{id: int, key: string, match_key: string, revision: int, created_at: string, updated_at: string}
     */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'match_key' => $this->matchKey,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            key: (string) $row['key'],
            matchKey: (string) $row['match_key'],
            revision: (int) $row['revision'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
