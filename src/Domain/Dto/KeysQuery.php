<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The query string of `GET /api/keys`.
 *
 * The limit is bounded rather than merely typed. This endpoint is the one a
 * caller uses to discover the keyspace, and an unbounded limit turns a
 * discovery call into a way to pull the entire store into one response — so the
 * ceiling is part of the contract, not a nicety.
 *
 * A blank pattern means "everything", which is the useful default here: the
 * caller asking what exists should not have to guess a pattern first.
 */
final class KeysQuery
{
    public function __construct(
        public string $pattern = '',
        #[Assert\Range(min: 1, max: 1000)]
        public int $limit = 200,
    ) {
    }
}
