<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Canonicalizes keyword spellings so that "ContextShuttle", "context-shuttle",
 * "context_shuttle" and "Context Shuttle" are all recognised as the *same* key.
 *
 * Two derived forms per key, because matching and display want different things:
 *
 *  - {@see slug()}     the canonical, human-readable spelling. Lowercased, with
 *                      runs of separators collapsed to a single "-". The
 *                      namespace colon is preserved, since `project:thing` is
 *                      meaningful structure rather than decoration.
 *
 *  - {@see matchKey()} the aggressive identity used for tier-A matching:
 *                      lowercased with *all* non-alphanumerics removed. This is
 *                      what makes "ContextShuttle" and "context-shuttle" equal —
 *                      case transitions, dashes, underscores, dots, spaces and
 *                      slashes all vanish.
 *
 * Why strip everything rather than split on case transitions: stripping is
 * idempotent and total. Camel-case splitting ("ContextShuttle" -> "context
 * shuttle") has to guess where words end, and guesses wrong on "McDonalds",
 * "iOS", or "contextSHUTTLE". Stripping never guesses.
 *
 * Known limitation: `matchKey()` is many-to-one, so `project:foo-bar` and
 * `project-foo:bar` collide on `projectfoobar`. That is deliberate — a collision
 * is visible (both spellings surface as aliases of one key) whereas a miss is
 * silent, and `keys` listing aliases is how you notice and rename.
 */
final class KeyNormalizer
{
    /**
     * Canonical display form: lowercase, separators collapsed to "-",
     * namespace colon preserved.
     */
    public static function slug(string $key): string
    {
        $key = trim($key);

        // Split off an optional namespace so "project" and the rest are
        // canonicalised with the same rules but re-joined with ":".
        $namespace = '';
        if (false !== ($pos = strpos($key, ':'))) {
            $namespace = self::collapse(self::splitCamel(substr($key, 0, $pos))).':';
            $key = substr($key, $pos + 1);
        }

        $body = self::collapse(self::splitCamel($key));

        return $namespace.$body;
    }

    /**
     * Insert a separator at camel-case transitions, so a key written as
     * "ContextShuttle" canonicalises to the readable "context-shuttle" rather
     * than to a separator-free "contextshuttle".
     *
     * This only affects the *display* form. Identity is still decided by
     * {@see matchKey()}, which is unaffected by the split — so an all-caps
     * spelling ("CONTEXTSHUTTLE", which has no case transition to split on)
     * still resolves to the same key. Match for identity, slug for readability.
     */
    private static function splitCamel(string $value): string
    {
        // Lower-to-upper is the transition that separates words: "contextShuttle".
        $split = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $value) ?? $value;

        // An acronym run followed by a word ("HTTPServer") splits after the run.
        $split = preg_replace('/(?<=[A-Z])(?=[A-Z][a-z])/', '-', $split) ?? $split;

        // Lowercase LAST. The split above depends on the original casing, and
        // collapse() maps every non-lowercase-alphanumeric to a separator — so
        // lowercasing before this point would turn each capital into a dash and
        // mangle the key ("ContextShuttle" -> "ontext-uttle").
        return mb_strtolower($split);
    }

    /**
     * Aggressive identity for matching: lowercase, non-alphanumerics removed.
     *
     * Falls back to the trimmed lowercase input when a key has no ASCII
     * alphanumerics at all (e.g. a non-Latin key), so those keys still match
     * exactly instead of collapsing to the empty string.
     */
    public static function matchKey(string $key): string
    {
        $stripped = preg_replace('/[^a-z0-9]+/', '', mb_strtolower(trim($key)));

        if (null === $stripped || '' === $stripped) {
            return mb_strtolower(trim($key));
        }

        return $stripped;
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
        // transition the split below depends on.
        $parts = preg_split('/[^A-Za-z0-9]+/', trim($key)) ?: [];

        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            // Then split camel-case transitions inside each part, so
            // "contextShuttle" contributes both "context" and "shuttle".
            foreach (preg_split('/(?<=[a-z0-9])(?=[A-Z])/', $part) ?: [$part] as $piece) {
                $piece = mb_strtolower($piece);
                if ('' !== $piece) {
                    $tokens[] = $piece;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Collapse runs of non-alphanumeric characters into single dashes and trim
     * any leading/trailing dash.
     */
    private static function collapse(string $value): string
    {
        $collapsed = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($collapsed, '-');
    }
}
