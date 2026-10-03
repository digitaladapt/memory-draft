<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\KeyNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The matching rules that make "ContextShuttle" and "context-shuttle" the same
 * key. These are the unit-level guarantees behind the escalation in
 * {@see \App\Service\MemoryService::recall()}.
 */
final class KeyNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugCases(): iterable
    {
        yield 'already canonical' => ['context-shuttle', 'context-shuttle'];
        yield 'camel case' => ['ContextShuttle', 'context-shuttle'];
        yield 'camel case acronym' => ['HTTPServer', 'http-server'];
        yield 'camel case acronym plural' => ['HTTPSServer', 'https-server'];
        yield 'underscores' => ['context_shuttle', 'context-shuttle'];
        yield 'spaces' => ['Context Shuttle', 'context-shuttle'];
        yield 'dots and slashes' => ['context.shuttle/v2', 'context-shuttle-v2'];
        yield 'namespace preserved' => ['Project:Context-Shuttle', 'project:context-shuttle'];
        yield 'namespace with spaces' => ['Project : Context Shuttle', 'project:context-shuttle'];
        yield 'leading and trailing noise' => ['  --Context--Shuttle--  ', 'context-shuttle'];
        yield 'collapses runs' => ['a___b   c', 'a-b-c'];
    }

    #[DataProvider('slugCases')]
    public function testSlugCanonicalisesSpelling(string $input, string $expected): void
    {
        self::assertSame($expected, KeyNormalizer::slug($input));
    }

    /**
     * The headline requirement: these all resolve to one identity.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function matchKeyCases(): iterable
    {
        yield 'vs camel' => ['ContextShuttle', 'contextshuttle'];
        yield 'vs dashed' => ['context-shuttle', 'contextshuttle'];
        yield 'vs underscored' => ['context_shuttle', 'contextshuttle'];
        yield 'vs spaced' => ['Context Shuttle', 'contextshuttle'];
        yield 'vs mixed' => ['CONTEXT__shuttle', 'contextshuttle'];
        yield 'namespace ignored for identity' => ['project:context-shuttle', 'projectcontextshuttle'];
    }

    #[DataProvider('matchKeyCases')]
    public function testMatchKeyUnifiesSpellingVariants(string $input, string $expected): void
    {
        self::assertSame($expected, KeyNormalizer::matchKey($input));
    }

    public function testSpellingVariantsShareOneMatchKey(): void
    {
        $variants = ['ContextShuttle', 'context-shuttle', 'context_shuttle', 'Context Shuttle', 'contextshuttle'];

        $identities = array_unique(array_map(KeyNormalizer::matchKey(...), $variants));

        self::assertCount(1, $identities, 'every variant must collapse to one identity');
    }

    /**
     * The slug is the *stored, displayed* form, so it must stay readable.
     * Identity is decided by matchKey(); readability is decided here.
     */
    public function testSlugPrefersAReadableSpelling(): void
    {
        self::assertSame('context-shuttle', KeyNormalizer::slug('ContextShuttle'));
        self::assertSame('http-server', KeyNormalizer::slug('HTTPServer'));
        self::assertSame('my-http-server', KeyNormalizer::slug('MyHTTPServer'));
    }

    /**
     * An all-caps spelling has no case transition to split on, so its slug
     * differs from the camel-case spelling's — but the *match key* is the same,
     * which is what makes them one key. Match for identity, slug for display.
     */
    public function testSlugVariationDoesNotAffectIdentity(): void
    {
        self::assertSame('contextshuttle', KeyNormalizer::slug('CONTEXTSHUTTLE'));
        self::assertSame('context-shuttle', KeyNormalizer::slug('ContextShuttle'));
        self::assertNotSame(KeyNormalizer::slug('CONTEXTSHUTTLE'), KeyNormalizer::slug('ContextShuttle'));

        self::assertSame(
            KeyNormalizer::matchKey('CONTEXTSHUTTLE'),
            KeyNormalizer::matchKey('ContextShuttle'),
            'identity must be identical regardless of the slug difference'
        );
    }

    /**
     * The slug is written to the database, so re-slugging a stored key must be
     * a no-op — otherwise a key would drift every time it was read back.
     */
    public function testSlugIsIdempotent(): void
    {
        foreach (['context-shuttle', 'contextshuttle', 'http-server', 'i-os', 'project:alpha'] as $key) {
            $once = KeyNormalizer::slug($key);
            self::assertSame($once, KeyNormalizer::slug($once), "slug({$key}) must be stable");
        }
    }

    public function testMatchKeyIsIdempotent(): void
    {
        $once = KeyNormalizer::matchKey('Context Shuttle-v2');
        self::assertSame($once, KeyNormalizer::matchKey($once));
    }

    public function testMatchKeyFallsBackForNonLatinKeys(): void
    {
        // Nothing to strip: falling back to the trimmed input keeps these keys
        // matching exactly instead of all collapsing to the empty string.
        self::assertSame('дом', KeyNormalizer::matchKey('  Дом  '));
    }

    /**
     * Known, accepted collision: stripping is many-to-one. A collision is
     * visible (both spellings list as aliases of one key) whereas a miss would
     * be silent — so this documents the tradeoff rather than pretending it
     * cannot happen.
     */
    public function testNamespaceBoundaryAlsoStrippedForIdentity(): void
    {
        self::assertSame(
            KeyNormalizer::matchKey('project:foo'),
            KeyNormalizer::matchKey('project-foo'),
            'accepted collision: separators are erased in the match identity'
        );
        // The canonical slugs stay distinct, which is what keeps them separable.
        self::assertNotSame(KeyNormalizer::slug('project:foo'), KeyNormalizer::slug('project-foo'));
    }

    public function testTokensSplitCamelCaseForSuggestions(): void
    {
        self::assertSame(['context', 'shuttle'], KeyNormalizer::tokens('contextShuttle'));
        self::assertSame(['context', 'shuttle'], KeyNormalizer::tokens('Context-Shuttle'));
        self::assertSame(['project', 'context', 'shuttle'], KeyNormalizer::tokens('project:context_shuttle'));
    }

    public function testEmptyInputIsSafe(): void
    {
        self::assertSame('', KeyNormalizer::slug('   '));
        self::assertSame('', KeyNormalizer::matchKey('   '));
        self::assertSame([], KeyNormalizer::tokens('   '));
    }

    /**
     * The invariant this class holds above all others: a key with content never
     * normalises to nothing, whatever the content is.
     *
     * "Nothing outside a-z0-9 survives" and "every non-blank key keeps an
     * identity" are different promises, and the old implementation only made the
     * first. Because the identity is *stored* rather than recomputed, every key
     * below collapsed onto the empty string and therefore onto *one row*:
     * punctuation-only keys, emoji, and any script with no ASCII form all merged
     * silently. This was a real bug, not a hypothetical.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonEmptyCases(): iterable
    {
        yield 'punctuation only' => ['---'];
        yield 'single dot' => ['.'];
        yield 'two dots' => ['..'];
        yield 'symbols' => ['!@#'];
        yield 'emoji' => ["\u{1F62C}"];
        yield 'emoji with variation selector' => ["\u{2764}\u{FE0F}"];
        yield 'regional flag' => ["\u{1F1FA}\u{1F1F8}"];
        yield 'CJK' => ["\u{8BB0}\u{5FC6}"];
        yield 'devanagari' => ["\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}"];
        yield 'cyrillic' => ["\u{043F}\u{0430}\u{043C}\u{044F}\u{0442}\u{044C}"];
        yield 'zero width space' => ["\u{200B}"];
        yield 'unassigned codepoint' => ["\u{1E31F}"];
    }

    #[DataProvider('nonEmptyCases')]
    public function testNonEmptyInputIsNeverLost(string $input): void
    {
        self::assertNotSame('', KeyNormalizer::slug($input), 'the slug must not discard the only content a key has');
        self::assertNotSame('', KeyNormalizer::matchKey($input), 'the identity must not collapse to empty');
        self::assertNotSame('', KeyNormalizer::slug(KeyNormalizer::slug($input)), 'and the escape must survive being folded again');
    }

    /**
     * Distinct contentless keys stay distinct, rather than merging into one row.
     */
    public function testDistinctContentlessKeysDoNotMerge(): void
    {
        $slugs = array_map(KeyNormalizer::slug(...), ['.', '..', '---', '!@#']);

        self::assertCount(4, array_unique($slugs), 'each must keep its own spelling');
    }

    /**
     * Accents are folded, so composed and decomposed spellings are one key.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function accentCases(): iterable
    {
        yield 'composed accent' => ["caf\u{00E9}", 'cafe'];
        yield 'decomposed accent' => ["cafe\u{0301}", 'cafe'];
        yield 'diaeresis' => ["na\u{00EF}ve", 'naive'];
        yield 'sharp s' => ["stra\u{00DF}e", 'strasse'];
        yield 'stroke' => ["\u{00F8}re", 'ore'];
        yield 'ligature' => ["\u{FB01}le", 'file'];
        yield 'superscript' => ["m\u{00B2}", 'm2'];
        yield 'fullwidth' => ["\u{FF25}\u{FF2D}", 'em'];
    }

    #[DataProvider('accentCases')]
    public function testSlugFoldsLatinDiacritics(string $input, string $expected): void
    {
        self::assertSame($expected, KeyNormalizer::slug($input));
    }

    /**
     * Emoji and non-Latin scripts survive as themselves.
     *
     * The primary reader of a key is a language model, and "😬" is information it
     * can use where "?" — or "" — is not.
     *
     * @return iterable<string, array{string}>
     */
    public static function preservedCases(): iterable
    {
        yield 'face emoji' => ["\u{1F62C}"];
        yield 'heart with variation selector' => ["\u{2764}\u{FE0F}"];
        yield 'regional flag' => ["\u{1F1FA}\u{1F1F8}"];
        yield 'CJK' => ["\u{8BB0}\u{5FC6}"];
        yield 'cyrillic' => ["\u{043F}\u{0430}\u{043C}\u{044F}\u{0442}\u{044C}"];
        yield 'arabic' => ["\u{0645}\u{0631}\u{062D}\u{0628}\u{0627}"];
        yield 'devanagari' => ["\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}"];
        yield 'tamil' => ["\u{0BA8}\u{0BA9}\u{0BCD}\u{0BB1}\u{0BBF}"];
    }

    #[DataProvider('preservedCases')]
    public function testSlugPreservesNonLatinAndEmoji(string $input): void
    {
        self::assertSame($input, KeyNormalizer::slug($input));
    }

    /**
     * Devanagari keeps its vowel signs.
     *
     * Transliterating them away would leave "नमस्ते" differing only in length from
     * a different word, silently equating keys the caller wrote precisely because
     * they differ.
     */
    public function testSlugDoesNotStripNonLatinCombiningMarks(): void
    {
        $devanagari = "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}";

        self::assertSame($devanagari, KeyNormalizer::slug($devanagari));
    }

    /**
     * Idempotency and non-emptiness together, sampled across the codepoint space.
     *
     * Both are required because **both values are stored**: a slug that changes on
     * read-back drifts, and an identity that changes on read-back stops matching.
     * ICU's transliterators are context-sensitive — U+1E4F folds only once the
     * combining mark after it is gone, and stripping a mark can leave a jamo that
     * NFKC then composes into a different syllable — so "it holds for the examples
     * I chose" is not the same claim as this one. Both of those were found by
     * exactly this loop, after a hand-picked suite passed.
     */
    public function testNormalisationIsIdempotentAndLosslessOverRandomInput(): void
    {
        mt_srand(20261003);

        for ($i = 0; $i < 20000; ++$i) {
            $input = '';
            $length = mt_rand(1, 16);
            for ($j = 0; $j < $length; ++$j) {
                $codepoint = mt_rand(0x20, 0x1F9FF);
                // Surrogates are not encodable as UTF-8; skipping them keeps the
                // sample honest rather than generating broken input.
                if ($codepoint >= 0xD800 && $codepoint <= 0xDFFF) {
                    continue;
                }
                $input .= mb_chr($codepoint);
            }

            $slug = KeyNormalizer::slug($input);
            $match = KeyNormalizer::matchKey($input);

            if ('' !== trim($input)) {
                self::assertNotSame('', $slug, 'non-empty input must keep an identity: '.bin2hex($input));
                self::assertNotSame('', $match, 'non-empty input must keep a match key: '.bin2hex($input));
            }

            self::assertSame($slug, KeyNormalizer::slug($slug), 'the slug must be stable: '.bin2hex($input));
            self::assertSame($match, KeyNormalizer::matchKey($match), 'the match key must be stable: '.bin2hex($input));
        }
    }

    /**
     * Non-Latin keys yield tokens, so the miss path can suggest them.
     *
     * The old tokenizer split on `[^A-Za-z0-9]+`, which made Cyrillic and CJK keys
     * yield *no* tokens at all — so a miss near such a key offered no suggestion,
     * and the caller was told nothing similar exists.
     */
    public function testTokensIncludeNonLatinRuns(): void
    {
        self::assertSame(['дом'], KeyNormalizer::tokens('  Дом  '));
        self::assertSame(["\u{8BB0}\u{5FC6}"], KeyNormalizer::tokens("\u{8BB0}\u{5FC6}"));
    }

    /**
     * A token is folded like a key is, so an accent cannot hide a suggestion.
     */
    public function testTokensFoldAccents(): void
    {
        self::assertSame(['cafe', 'test'], KeyNormalizer::tokens("caf\u{00E9}-Test"));
        self::assertSame(KeyNormalizer::tokens('cafe-Test'), KeyNormalizer::tokens("caf\u{00E9}-Test"));
    }

    /**
     * Two emoji keys keep two identities rather than sharing one.
     */
    public function testEmojiKeysKeepDistinctIdentities(): void
    {
        self::assertNotSame(
            KeyNormalizer::matchKey("\u{1F62C}"),
            KeyNormalizer::matchKey("\u{1F622}"),
        );
    }

    /**
     * A word keeps its word boundaries when a Latin diacritic is folded away.
     */
    public function testFoldingAnAccentDoesNotGlueWordsTogether(): void
    {
        self::assertSame('cafe-au-lait', KeyNormalizer::slug("caf\u{00E9} au lait"));
        self::assertSame(['cafe', 'au', 'lait'], KeyNormalizer::tokens("caf\u{00E9} au lait"));
    }
}
