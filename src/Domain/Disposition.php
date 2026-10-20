<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What `revise` does to the sentences it selected.
 *
 * Two dispositions rather than one destructive verb, because "this is no longer
 * true" and "this must not be recoverable" are the same decision made with
 * different confidence — and a caller forced to pick a single verb would reach
 * for the destructive one on a day it was merely unsure.
 */
enum Disposition: string
{
    /**
     * Move the sentence from hot to cold. The default.
     *
     * The per-sentence form of what `replace` does to a whole key and the hot cap
     * does to the oldest sentences: out of view, still retrievable, and promoted
     * straight back to hot by re-stating it with `remember` — which
     * {@see \App\Infrastructure\Storage\MemoryRepository::upsertSentence()}
     * already does, so this disposition needs no inverse operation of its own.
     */
    case Demote = 'demote';

    /**
     * Delete the sentence outright. The only irreversible memory operation.
     *
     * Unlike `forget`, which it supersedes, this removes a sentence and nothing
     * else: the key keeps its identity, revision, aliases and its other
     * sentences.
     */
    case Purge = 'purge';

    public function isDestructive(): bool
    {
        return self::Purge === $this;
    }
}
