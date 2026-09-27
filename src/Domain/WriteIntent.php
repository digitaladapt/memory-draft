<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What a caller means when it writes, with respect to the revision it holds.
 *
 * This is the answer to "we would have to decide the priority of that sort of
 * data" — the priority is a consequence of the caller's intent, not of the data:
 *
 *  - {@see Current}  no revision supplied; the caller did not read first (or
 *                    does not care). The write is assumed to describe the world
 *                    now, and applies at the current revision.
 *
 *  - {@see Confirmed} the caller read revision N and nothing has changed since
 *                    (still N). A normal, fully-authoritative write.
 *
 *  - {@see Backfill} the caller read revision N but the key has moved on to N+k.
 *                    The write is still *kept* — it is ranked below current
 *                    knowledge rather than rejected, because silently dropping a
 *                    write loses something the newer writer may never have known.
 */
enum WriteIntent: string
{
    case Current = 'current';
    case Confirmed = 'confirmed';
    case Backfill = 'backfill';
}
