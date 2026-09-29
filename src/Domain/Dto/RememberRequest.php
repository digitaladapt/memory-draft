<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of `POST /api/remember`: one or more keywords to write.
 *
 * A batch, like {@see RecallRequest}, and wrapped for the same reason. A write
 * batch is applied in a single transaction by the service, so either every item
 * lands or none does — which is why the whole request is validated before any of
 * it is stored.
 *
 * See {@see RecallRequest} for why `#[Assert\Valid]` here depends on
 * `phpdocumentor/reflection-docblock` being installed.
 */
final class RememberRequest
{
    /**
     * @param list<RememberItem> $items
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $items = [],
    ) {
    }
}
