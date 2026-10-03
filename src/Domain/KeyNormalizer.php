<?php

declare(strict_types=1);

namespace App\Domain;

use Transliterator;

/**
 * Canonicalises keyword spellings so that "ContextShuttle", "context-shuttle",
 * "context_shuttle" and "Context Shuttle" are all recognised as the *same* key,
 * and so that "café" is one key whether it was typed composed or decomposed.
 *
 * Two derived forms per key, because matching and display want different things:
 *
 *  - {@see slug()}     the canonical, human-readable spelling. Lowercased, Latin
 *                      diacritics folded to ASCII, runs of separators collapsed
 *                      to a single "-". The namespace colon is preserved, since
 *                      `project:thing` is meaningful structure rather than
 *                      decoration.
 *
 *  - {@see matchKey()} the aggressive identity used for tier-A matching: the
 *                      slug with *all* non-alphanumerics removed. This is what
 *                      makes "ContextShuttle" and "context-shuttle" equal —
 *                      case transitions, dashes, underscores, dots, spaces and
 *                      slashes all vanish.
 *
 * **Non-empty in, non-empty out — always.** A blank (or whitespace-only) key
 * normalises to the empty string, but any key with content must not. The
 * previous implementation was a single `preg_replace` deleting everything
 * outside `[a-z0-9]`, so a key made only of punctuation ("..") or only of
 * characters with no ASCII form ("😬", "记忆") collapsed to "" and every such key
 * was silently stored as *one* row. {@see escape()} is the floor that makes the
 * guarantee hold: when folding has nothing left to keep, the codepoints
 * themselves are spelled out — lossless, deterministic and idempotent. This
 * matters because the identity is *stored*, not recomputed, so an empty one is
 * not a transient value but a durable merge of unrelated keys.
 *
 * **Emoji and non-Latin scripts are preserved, not transliterated.** The primary
 * reader of a key is a language model, and "😬" round-tripping as "😬" is
 * information it can use; "?" — or worse, the empty string — is not. Scripts
 * that Latin-ASCII cannot romanise (Cyrillic, Arabic, Devanagari, Tamil, CJK)
 * likewise pass through intact, which is also why folding is limited to Latin
 * diacritics rather than a hand-written substitution table: the ICU
 * transliterator already knows, correctly, that stripping combining marks is
 * right for Latin (café → cafe) and wrong for Devanagari (नमस्ते must not lose
 * its vowel signs).
 *
 * **ICU's `Latin-ASCII` is not idempotent, so folding is iterated to a fixed
 * point.** It can emit a non-ASCII Latin character (Ɬ → ɬ, ƞ, ǝ) that a
 * *second* pass would fold further. A single pass therefore leaves stored keys
 * that change when read back — exactly the drift the idempotency test below
 * guards against. Iterating removes the whole class of bug rather than sampling
 * for it.
 *
 * **Why strip everything rather than split on case transitions?** Stripping is
 * total and idempotent; case-splitting has to guess where words end and guesses
 * wrong on "McDonalds", "iOS" or "contextSHUTTLE". The identity function never
 * guesses.
 *
 * Known limitation: `matchKey()` is many-to-one, so `project:foo-bar` and
 * `project-foo:bar` collide on `projectfoobar`. That is deliberate — a collision
 * is visible (both spellings surface as aliases of one key) whereas a miss is
 * silent, and `keys` listing aliases is how you notice and rename.
 */
final class KeyNormalizer
{
    /**
     * Upper bound on the fold-to-fixed-point loop. Convergence takes one pass in
     * practice; the cap exists so that a future change to the folding rules
     * cannot turn this into an infinite loop on some input.
     */
    private const MAX_FOLD_PASSES = 6;

    /**
     * Cached because constructing a transliterator is not free and this runs on
     * every lookup. Nullable rather than non-null because the ID could be
     * unavailable in a stripped-down ICU build — in which case folding simply
     * skips the transliteration step instead of fataling.
     */
    private static ?\Transliterator $latinToAscii = null;

    /**
     * Canonical display form: lowercase, Latin diacritics folded to ASCII,
     * separators collapsed to "-", namespace colon preserved.
     *
     * Idempotent, and non-empty whenever the input had any content — see the
     * class docblock for why both are load-bearing rather than cosmetic.
     */
    public static function slug(string $key): string
    {
        $key = trim($key);

        // Split off an optional namespace so "project" and the rest are
        // canonicalised with the same rules but re-joined with ":".
        $namespace = '';
        if (false !== ($pos = strpos($key, ':'))) {
            $namespace = self::fold(substr($key, 0, $pos)).':';
            $key = substr($key, $pos + 1);
        }

        $slug = $namespace.self::fold($key);

        // The non-empty guarantee. Reached only when the input had content that
        // folding could not express (punctuation only, or no ASCII form at all).
        if ('' === $slug && '' !== $key) {
            $slug = self::escape($key);
        }

        return $slug;
    }

    /**
     * Aggressive identity for matching: the slug with every non-alphanumeric
     * removed, so spellings that differ only in separators or case collapse to
     * one value.
     *
     * Unicode-aware rather than `[a-z0-9]`, so a non-Latin key keeps an identity
     * of its own instead of every such key sharing the empty string.
     */
    public static function matchKey(string $key): string
    {
        $slug = self::slug($key);
        if ('' === $slug) {
            return '';
        }

        $identity = self::matchFold($slug);

        // An emoji-only or mark-only key has no alphanumerics at all. Falling
        // back to the slug keeps `😬` and `😂` distinct instead of collapsing
        // every such key onto one empty identity — the same merge bug this class
        // exists to prevent, one step further along.
        return '' === $identity ? $slug : $identity;
    }

    /**
     * The identity form: fold, drop every non-alphanumeric, repeat until stable.
     *
     * The repetition is not belt-and-braces, and it is the reason `matchKey()`
     * cannot simply be "the slug with the dashes taken out". Removing characters
     * changes the *context* the transliterator sees, and ICU's `Latin-ASCII` is
     * context-sensitive in two separate ways:
     *
     *  - `ṏ` (U+1E4F) passes through untouched when a combining mark follows it,
     *    but folds to `o` once that mark is stripped away.
     *  - Stripping a mark can leave a trailing jamo beside a Hangul syllable
     *    (`가` + T-jamo), and NFKC then composes them into a different syllable.
     *
     * So fold-then-strip is not a closed operation even though `fold()` alone is,
     * and the identity is *stored* — a value that changes on re-derivation is the
     * silent drift the idempotency tests exist to catch. Iterating removes the
     * whole class rather than the two instances a fuzz run happened to find.
     */
    private static function matchFold(string $value): string
    {
        $previous = null;
        $current = $value;
        $passes = 0;

        while ($current !== $previous && $passes++ < self::MAX_FOLD_PASSES) {
            $previous = $current;
            $current = preg_replace('/[^\p{L}\p{N}]+/u', '', self::fold($current)) ?? $current;
        }

        return $current;
    }

    /**
     * Token list for fuzzy comparison, derived from the original separators.
     *
     * @return list<string>
     */
    public static function tokens(string $key): array
    {
        $tokens = [];

        // Split on separators FIRST, while the original casing is still
        // intact — lowercasing before this step would destroy the camel-case
        // transition the split below depends on. Unicode letter/number classes
        // rather than A-Za-z0-9 so that a non-Latin key yields a token instead
        // of nothing, which is what lets the miss path suggest it at all.
        $parts = preg_split('/[^\p{L}\p{N}]+/u', trim($key)) ?: [];

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            // Then split camel-case transitions inside each part, so
            // "contextShuttle" contributes both "context" and "shuttle".
            foreach (preg_split('/(?<=\p{Ll}|\p{Nd})(?=\p{Lu})/u', $part) ?: [$part] as $piece) {
                // Fold each piece the same way a key is folded, so "café" and
                // "cafe" share the token "cafe" and a suggestion is not missed
                // over an accent. Non-Latin scripts come through unchanged.
                $piece = self::fold($piece);
                if ('' !== $piece) {
                    $tokens[] = $piece;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Fold a value to its stable, readable ASCII-ish form.
     *
     * Iterated because the steps are individually idempotent but not jointly so:
     * the transliterator can produce a character that its own next pass would
     * change. Looping to a fixed point makes the result a true normal form.
     */
    private static function fold(string $value): string
    {
        $previous = null;
        $current = $value;
        $passes = 0;

        while ($current !== $previous && $passes++ < self::MAX_FOLD_PASSES) {
            $previous = $current;
            $current = self::foldOnce($current);
        }

        return $current;
    }

    /**
     * One folding pass.
     */
    private static function foldOnce(string $value): string
    {
        // 1. NFKC: canonical *and* compatibility composition. This is what turns
        //    "ﬁle" into "file", "m²" into "m2", "①" into "1", "™" into "TM" and
        //    decomposed "cafe\u{0301}" into composed "café", so the steps below
        //    only ever see one spelling of each character.
        $normalized = \Normalizer::normalize($value, \Normalizer::FORM_KC);
        if (false !== $normalized) {
            $value = $normalized;
        }

        // 2. Latin diacritics to ASCII. The generic ICU transliterator, with no
        //    hand-written rule table: doing this by hand is how you end up
        //    deleting Devanagari vowel marks, because "combining mark" looks
        //    like "accent" until you meet a script where it is not.
        //    Non-Latin scripts and emoji are deliberately left alone.
        //    transliterate() returns false on failure, not null — checking for
        //    null here would assign false and then fatal in mb_strtolower().
        $ascii = self::latinToAscii()->transliterate($value);
        if (false !== $ascii) {
            $value = $ascii;
        }

        // 3. Split camel-case transitions, THEN lowercase — in that order. The
        //    split depends on the original casing, and the lowercasing has to
        //    happen here rather than in the caller because the transliterator
        //    can *introduce* uppercase ("™" → "TM"): lowercasing only before it
        //    would leave the result non-idempotent.
        $value = preg_replace('/(?<=\p{Ll}|\p{Nd})(?=\p{Lu})/u', '-', $value) ?? $value;
        $value = preg_replace('/(?<=\p{Lu})(?=\p{Lu}\p{Ll})/u', '-', $value) ?? $value;
        $value = mb_strtolower($value);

        // 4. Control and format characters are dropped: NUL, zero-width space,
        //    ZWJ, byte-order marks, private use. Note the deliberate *exclusion*
        //    of \p{Cn} (unassigned): an unassigned codepoint is content this
        //    store does not understand, and deleting it is what emptied keys
        //    like "𞌟" — the guarantee in slug() depends on keeping it.
        $value = preg_replace('/[\p{Cc}\p{Cf}\p{Cs}\p{Co}]+/u', '', $value) ?? $value;

        // 5. Runs of separators (spaces, punctuation, and the symbol categories
        //    NFKC leaves behind) collapse to a single "-".
        $value = preg_replace('/[\p{Z}\p{P}\p{Sk}\p{Sm}\p{Sc}]+/u', '-', $value) ?? $value;

        return trim($value, '-');
    }

    /**
     * Last resort for content folding cannot express: spell out the codepoints.
     *
     * "---" becomes "u2d-u2d-u2d" rather than "". Ugly, but it is lossless,
     * deterministic, stable under re-normalisation, and — crucially — distinct
     * per input, so two punctuation-only keys stay separate instead of merging
     * into one. Round-tripping is what the caller needs; prettiness is not.
     */
    private static function escape(string $value): string
    {
        $codes = [];

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (false !== $characters) {
            foreach ($characters as $character) {
                $codes[] = sprintf('u%x', mb_ord($character) ?: 0);
            }
        } else {
            // Malformed UTF-8: fall back to the bytes, so this still cannot
            // return the empty string.
            foreach (str_split($value) as $byte) {
                $codes[] = sprintf('x%02x', ord($byte));
            }
        }

        return implode('-', $codes);
    }

    private static function latinToAscii(): ?\Transliterator
    {
        return self::$latinToAscii ??= \Transliterator::create('Latin-ASCII');
    }
}
