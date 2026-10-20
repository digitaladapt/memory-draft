<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Compares a caller's text pattern against a stored sentence.
 *
 * `revise` addresses sentences by their *content* rather than by an id (see
 * docs/design/REVISE.md §3.1: text is already unique per key, so an id would be
 * a second name for a thing that has one). That makes matching the whole
 * addressing mechanism, and therefore worth its own tested unit.
 *
 * Two decisions live here:
 *
 * 1. **Matching is case-insensitive and whitespace-insensitive, and nothing
 *    else.** {@see fold()} normalises Unicode composition, collapses whitespace
 *    runs and lowercases. It deliberately does *not* strip punctuation or
 *    diacritics — see the note on {@see fold()}.
 *
 * 2. **Metacharacters are literal unless the caller asked for `glob`.** A
 *    sentence containing `?` or `*` is ordinary prose; it must not become a
 *    wildcard merely by being quoted back.
 */
final class SentenceMatcher
{
    /**
     * The comparison form of a piece of text.
     *
     * NFKC first, so that the composed and decomposed spellings of `café` are one
     * form; whitespace runs collapsed, so that quoting a sentence off a screen
     * with a line wrap still matches; then lowercased. Trimming completes it.
     *
     * Why not reuse {@see KeyNormalizer::fold()}? Because it is key-shaped: it
     * collapses every run of punctuation to `-`, which is right for
     * `context-shuttle` and wrong for prose — `"it's 3 p.m."` would become
     * `it-s-3-p-m`, and no caller would ever guess that. In prose, punctuation is
     * information. The duplication is deliberate: sharing that method is how a key
     * rule would become a text rule by accident.
     *
     * The consequence, stated here because it will be asked: **matching is
     * diacritic-sensitive** (`café` does not match `cafe`), unlike key
     * resolution. That is the conservative direction — it produces a miss rather
     * than a wrong match — and `contains` on a shared stem is the escape hatch.
     *
     * Idempotent: folding folded text returns it unchanged, which the unit tests
     * assert, because a non-idempotent fold would make a stored pattern behave
     * differently from a freshly typed one.
     */
    public static function fold(string $text): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
            if (false !== $normalized) {
                $text = $normalized;
            }
        }

        // \s+ covers newlines, tabs and the exotic spaces NFKC just produced, so
        // a sentence quoted across a line break still matches the stored one.
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $lowered = mb_strtolower($collapsed, 'UTF-8');

        return trim($lowered);
    }

    /**
     * Does the stored sentence satisfy the pattern?
     *
     * `$sentence` is raw, unfolded text as it sits in storage; folding happens
     * here so callers cannot forget it.
     */
    public static function matches(SentenceMatchKind $kind, string $pattern, string $sentence): bool
    {
        $foldedPattern = self::fold($pattern);
        $foldedSentence = self::fold($sentence);

        return match ($kind) {
            SentenceMatchKind::Exact => $foldedSentence === $foldedPattern,
            SentenceMatchKind::Contains => '' !== $foldedPattern && str_contains($foldedSentence, $foldedPattern),
            SentenceMatchKind::Glob => self::globMatches($foldedPattern, $foldedSentence),
        };
    }

    /**
     * Anchored `*`/`?` matching against already-folded text.
     *
     * Anchored at both ends on purpose: an unanchored glob is just `contains`
     * with extra syntax, and two ways to express one behaviour is how the wrong
     * one ends up in use. `plan*` therefore matches `planned the move` but not
     * `the plan`; `*plan` matches `the plan` but not `planet`.
     */
    private static function globMatches(string $foldedPattern, string $foldedSentence): bool
    {
        // preg_quote, then re-open the two wildcards we do support. Everything
        // else the caller typed — `.`, `(`, `+`, `$` — stays literal.
        $quoted = preg_quote($foldedPattern, '/');
        $regex = strtr($quoted, ['\\*' => '.*', '\\?' => '.']);
        $matched = preg_match('/\A'.$regex.'\z/us', $foldedSentence);

        // An invalid pattern (a broken UTF-8 sequence, say) is treated as a miss
        // rather than an error: the caller's next step is to look at what is
        // stored, and `dry_run` shows that.
        return 1 === $matched;
    }
}
