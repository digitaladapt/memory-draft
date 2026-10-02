<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The body of `POST /api/recall`: one or more entries to look up.
 *
 * A wrapper object rather than a bare JSON array because the endpoint takes a
 * *batch*, and a top-level array leaves nowhere to put constraints that apply to
 * the batch itself. The list is required and non-empty: an empty recall is a
 * caller bug, not a request for nothing, and answering it with an empty result
 * would be exactly the kind of silent success this service is built to avoid.
 *
 * `#[Assert\Valid]` is load-bearing. It is what makes the constraints on each
 * {@see RecallQuery} run. That cascade depends on the element type being
 * resolvable from the `list<RecallQuery>` docblock, which is why
 * `phpdocumentor/reflection-docblock` is a direct dependency: without it the
 * collection denormalizes to raw arrays, `#[Assert\Valid]` becomes inert, and a
 * blank key would reach the service instead of being rejected with a 422.
 */
#[Assert\Callback('validateBatch')]
final class RecallRequest
{
    /**
     * @param list<RecallQuery> $queries
     */
    public function __construct(
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $queries = [],
    ) {
    }

    /**
     * Batch-wide rules, which no single entry can see.
     *
     * At most one entry may ask for the latest. Two would be a request for two
     * orderings of the same thing — the answer could only be one of them, and
     * which one it was would be an implementation detail rather than a stated
     * rule.
     */
    public static function validateBatch(mixed $value, ExecutionContextInterface $context): void
    {
        if (!$value instanceof self) {
            return;
        }

        $recency = 0;
        foreach ($value->queries as $query) {
            if ($query->isLatest()) {
                ++$recency;
            }
        }

        if ($recency > 1) {
            $context
                ->buildViolation('At most one entry may ask for the latest.')
                ->atPath('queries')
                ->addViolation();
        }
    }
}
