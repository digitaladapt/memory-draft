<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Storage tier for a sentence.
 *
 * Trimming *demotes* rather than deletes: sentences pushed out of `Hot` are
 * still retrievable from `Cold`. Memory that vanishes without saying so is
 * worse than memory that is merely out of the way, because the failure is
 * silent and unrecoverable.
 */
enum Tier: string
{
    /** Shown by default. Bounded per keyword by the hot cap. */
    case Hot = 'hot';

    /** Out of the way but retrievable on request; hard-deleted only past cold cap. */
    case Cold = 'cold';
}
