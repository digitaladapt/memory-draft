<?php

declare(strict_types=1);

namespace App\Domain\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One sentence to revise, named by its text.
 *
 * There is no id field, and that is the design rather than an omission: a
 * sentence is already uniquely addressed by `(key, text)` — the repository
 * enforces it on `sha256(text)` scoped to `key_id` — so an id would be a second
 * name for a thing that has one, at the cost of a stale-handle class of bug the
 * store does not currently have (REVISE.md §3.1).
 *
 * `text` is required and `NotBlank`: an empty pattern under `contains` would
 * match every sentence on the key, which is one keystroke away from emptying it.
 *
 * `disposition` defaults to `demote`, the reversible one. A caller has to type
 * `purge` to destroy anything.
 */
final class ReviseItem
{
    public function __construct(
        #[Assert\NotBlank]
        public string $key = '',
        #[Assert\NotBlank]
        public string $text = '',
        #[Assert\Choice(choices: ['demote', 'purge'])]
        public string $disposition = 'demote',
        #[Assert\Choice(choices: ['exact', 'contains', 'glob'])]
        public string $match = 'exact',
        #[Assert\Choice(choices: ['hot', 'cold', 'any'])]
        public string $tier = 'hot',
        public bool $dryRun = false,
        public bool $includePinned = false,
    ) {
    }
}
