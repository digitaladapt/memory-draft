<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Which tiers a `revise` selection is allowed to reach into.
 *
 * A scope, not a {@see Tier}: `Hot` and `Cold` name one storage tier each, while
 * this additionally names *both at once*. Keeping them apart means a caller
 * cannot accidentally ask for a sentence to be "moved to any", which a widened
 * `Tier` would have made expressible.
 */
enum TierScope: string
{
    /**
     * Only the tier recall shows by default.
     *
     * The default because it is the view the caller has actually read, and so the
     * only set of sentences it can name from memory. Reaching into cold storage
     * is an act to intend.
     */
    case Hot = 'hot';

    /** Only demoted sentences. */
    case Cold = 'cold';

    /** Both tiers. The only scope in which a `demote` can find nothing to do. */
    case Any = 'any';

    /**
     * @return list<Tier>
     */
    public function tiers(): array
    {
        return match ($this) {
            self::Hot => [Tier::Hot],
            self::Cold => [Tier::Cold],
            self::Any => [Tier::Hot, Tier::Cold],
        };
    }

    public function includes(Tier $tier): bool
    {
        return \in_array($tier, $this->tiers(), true);
    }
}
