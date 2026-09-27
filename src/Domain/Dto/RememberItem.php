<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use App\Domain\WriteIntent;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One keyword to write.
 *
 * `revision` is the optional concurrency token. Supplying the value you read
 * means "I am writing against revision N" and lets the service detect that
 * another writer has moved on. Omitting it means "I did not read first", which
 * is legitimate and treated as a write against current knowledge.
 */
final class RememberItem
{
    /**
     * @param string|list<string>|null $sentences
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $key = '',
        public string|array|null $sentences = null,
        #[Assert\Choice(choices: ['append', 'replace'])]
        public string $mode = 'append',
        public bool $pin = false,
        public ?int $revision = null,
    ) {
    }

    /**
     * What the caller's revision means, once the key's real revision is known.
     */
    public function intent(?int $currentRevision): WriteIntent
    {
        if (null === $this->revision) {
            return WriteIntent::Current;
        }

        return $this->revision === $currentRevision ? WriteIntent::Confirmed : WriteIntent::Backfill;
    }
}
