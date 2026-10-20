<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * How a `revise` pattern is compared against a stored sentence.
 *
 * Ordered from strictest to loosest, and that order is the whole safety model:
 * the default is `Exact` because **a miss is recoverable and a wrong match is
 * not**. A caller quoting what recall showed it either gets that sentence or a
 * clear miss — never "I matched something similar and destroyed that instead".
 * Widening the net is always the caller's explicit act.
 *
 * Not to be confused with {@see MatchKind}, which describes how a *keyword*
 * resolved to a key. One is about names, the other about prose; they share no
 * values and are deliberately separate types so that neither can be passed where
 * the other is meant.
 */
enum SentenceMatchKind: string
{
    /** The stored sentence equals the pattern after folding. */
    case Exact = 'exact';

    /** The stored sentence contains the pattern after folding. */
    case Contains = 'contains';

    /**
     * `*` (any run) and `?` (any one character), anchored to the whole sentence.
     *
     * Anchored deliberately: an unanchored glob is `contains` with extra syntax,
     * and offering two spellings of one behaviour is how the wrong one gets used.
     */
    case Glob = 'glob';
}
