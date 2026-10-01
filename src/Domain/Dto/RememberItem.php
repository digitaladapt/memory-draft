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
 *
 * `pin` is deliberately tri-state rather than a plain bool, because "do not
 * pin" and "leave the pin as it is" are different instructions and collapsing
 * them is destructive. A sentence that is already stored is *renewed* when it
 * is written again, and re-stating a fact is the normal way to say "this is
 * still true" — so a write that does not mention `pin` must not be read as an
 * instruction to take the pin away. Under a plain bool, omitted and `false`
 * were one value, and re-stating a pinned fact silently made it disposable.
 *
 * - `null`  — leave any existing pin untouched (and store a new sentence unpinned)
 * - `true`  — pin these sentences
 * - `false` — unpin these sentences
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
        public ?bool $pin = null,
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
