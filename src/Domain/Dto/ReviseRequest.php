<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The body of `POST /api/revise`: one or more sentences to retire or destroy.
 *
 * A batch, like {@see RememberRequest} and for the same reason: the service
 * applies it in one transaction, so either every item lands or none does. That
 * matters more here than on a write, because a half-applied purge has no undo.
 *
 * See {@see RecallRequest} for why `#[Assert\Valid]` depends on
 * `phpdocumentor/reflection-docblock` being installed.
 */
final class ReviseRequest
{
    /**
     * @param list<ReviseItem> $items
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $items = [],
    ) {
    }
}
