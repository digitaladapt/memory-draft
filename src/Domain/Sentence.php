<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * A single stored sentence.
 *
 * Sentence granularity is what makes trimming exact: demoting to cold storage is
 * an `UPDATE ... SET tier` on a row, not a re-parse of a prose blob.
 */
final class Sentence
{
    public function __construct(
        public readonly int $id,
        public readonly string $keyword,
        public readonly string $text,
        public readonly string $createdAt,
        public readonly Tier $tier,
        public readonly bool $pinned,
        /** Monotonic write counter — ordering must not depend on clock resolution. */
        public readonly int $batch,
        /** Revision of the key when this sentence was written. */
        public readonly int $writtenRevision,
        /**
         * True when this sentence was written by a caller whose revision was
         * behind the key's — so it is kept, but ranked below current knowledge
         * rather than being rejected or demoted to cold.
         */
        public readonly bool $backfilled,
    ) {
    }
}
