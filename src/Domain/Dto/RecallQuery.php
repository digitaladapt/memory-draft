<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use App\Domain\WriteIntent;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One keyword to look up.
 *
 * A bare string is also accepted ("recall": ["context-shuttle"]) because that is
 * the common case and requiring an object for it would be noise. The object form
 * exists so a caller can ask for more (or less) depth per keyword.
 */
final class RecallQuery
{
    public function __construct(
        #[Assert\NotBlank]
        public string $key = '',
        public ?int $depth = null,
        public bool $includeCold = false,
    ) {
    }

    /**
     * Intent describing what the caller's optional revision means.
     */
    public function intent(?int $revision): WriteIntent
    {
        return null === $revision ? WriteIntent::Current : WriteIntent::Confirmed;
    }
}
