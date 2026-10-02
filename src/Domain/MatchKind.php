<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a requested keyword related to the key that answered it.
 *
 * Ordered from strongest to weakest. A `Slug` match is *auto-resolved* — it
 * returns content in the same round trip, claiming the alias rather than making
 * the caller ask again with a corrected spelling.
 */
enum MatchKind: string
{
    /** The caller typed the canonical key exactly. */
    case Exact = 'exact';

    /** Same key, different spelling ("ContextShuttle" -> "context-shuttle"). */
    case Slug = 'slug';

    /** Recorded earlier as an alias of this key, so it resolves directly. */
    case Alias = 'alias';

    /**
     * The key was not asked for by name at all — it is in the answer because it
     * was recently written (see {@see \App\Service\MemoryService::recall()}).
     *
     * Its own case rather than `Exact`, because it answers a different question.
     * `Exact` means "you named this and were right"; this means "you asked for
     * whatever is recent, and this is what was". Reporting it as `exact` would
     * invite the caller to believe it had asked for this key.
     */
    case Recent = 'recent';

    /** No match; a suggestion is attached instead. */
    case Miss = 'miss';

    /** True when this match returned real content. */
    public function isHit(): bool
    {
        return self::Miss !== $this;
    }
}
