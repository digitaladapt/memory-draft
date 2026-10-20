<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\SentenceMatcher;
use App\Domain\SentenceMatchKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `revise` addressing mechanism.
 *
 * These are the tests that stand between a caller and the wrong sentence being
 * demoted, so they lean heavily on the *negative* direction: most of them assert
 * that something does **not** match, because a matcher that is too eager is
 * dangerous in a way that one which is too strict is not.
 */
final class SentenceMatcherTest extends TestCase
{
    // ---------------------------------------------------------------------- //
    // folding
    // ---------------------------------------------------------------------- //

    /**
     * The fold has to be stable, because a pattern typed now is compared with
     * text stored months ago — and if folding were not idempotent, the two would
     * drift apart.
     */
    #[DataProvider('foldSamples')]
    public function testFoldIsIdempotent(string $input): void
    {
        $once = SentenceMatcher::fold($input);

        self::assertSame($once, SentenceMatcher::fold($once));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foldSamples(): iterable
    {
        yield 'plain' => ['Deploys use blue-green'];
        yield 'tabs and newlines' => ["Deploys\tuse\n\nblue-green "];
        yield 'composed accents' => ['café résumé'];
        yield 'decomposed accents' => ["cafe\u{0301} re\u{0301}sume\u{0301}"];
        yield 'punctuation kept' => ["It's 3 p.m., not 3 p.m...."];
        yield 'emoji' => ['works 😬 fine'];
        yield 'non-Latin' => ['नमस्ते दुनिया'];
        yield 'empty' => [''];
        yield 'whitespace only' => ["  \t\n "];
    }

    public function testFoldCollapsesWhitespaceSoAQuotedLineWrapStillMatches(): void
    {
        self::assertSame(
            SentenceMatcher::fold('the store keeps every cold sentence'),
            SentenceMatcher::fold("the store keeps every\n   cold sentence"),
        );
    }

    public function testFoldLowercasesWithoutStrippingPunctuation(): void
    {
        // The difference from KeyNormalizer::fold() — prose keeps its punctuation.
        self::assertSame("it's 3 p.m.", SentenceMatcher::fold("It's 3 P.M."));
    }

    public function testFoldTreatsComposedAndDecomposedAccentsAsOneForm(): void
    {
        // NFKC, so a sentence pasted from macOS matches one typed on Linux.
        self::assertSame(
            SentenceMatcher::fold('café'),
            SentenceMatcher::fold("cafe\u{0301}"),
        );
    }

    // ---------------------------------------------------------------------- //
    // exact
    // ---------------------------------------------------------------------- //

    public function testExactMatchesTheWholeSentenceRegardlessOfCaseAndSpacing(): void
    {
        self::assertTrue(SentenceMatcher::matches(
            SentenceMatchKind::Exact,
            'THE STORE KEEPS PINNED SENTENCES.',
            'the  store keeps pinned sentences.',
        ));
    }

    public function testExactDoesNotMatchASubstring(): void
    {
        // The safety property: quoting a fragment under the default kind misses
        // rather than silently selecting a longer sentence.
        self::assertFalse(SentenceMatcher::matches(
            SentenceMatchKind::Exact,
            'keeps pinned sentences',
            'the store keeps pinned sentences.',
        ));
    }

    public function testExactDoesNotMatchAsuperstringEither(): void
    {
        self::assertFalse(SentenceMatcher::matches(
            SentenceMatchKind::Exact,
            'the store keeps pinned sentences and more',
            'the store keeps pinned sentences.',
        ));
    }

    // ---------------------------------------------------------------------- //
    // contains
    // ---------------------------------------------------------------------- //

    public function testContainsMatchesAnywhereInTheSentence(): void
    {
        self::assertTrue(SentenceMatcher::matches(
            SentenceMatchKind::Contains,
            'PINNED',
            'the store keeps pinned sentences.',
        ));
    }

    /**
     * An empty `contains` pattern would select every sentence on the key, which
     * under `purge` is one keystroke from an empty key. It is treated as a miss
     * here as well as being blocked by validation upstream, so the two agree.
     */
    public function testContainsWithAnEmptyPatternMatchesNothing(): void
    {
        self::assertFalse(SentenceMatcher::matches(
            SentenceMatchKind::Contains,
            '',
            'the store keeps pinned sentences.',
        ));
    }

    public function testContainsWithWhitespaceOnlyPatternMatchesNothing(): void
    {
        self::assertFalse(SentenceMatcher::matches(
            SentenceMatchKind::Contains,
            "  \n ",
            'the store keeps pinned sentences.',
        ));
    }

    // ---------------------------------------------------------------------- //
    // glob
    // ---------------------------------------------------------------------- //

    public function testGlobStarSpansAnyRun(): void
    {
        self::assertTrue(SentenceMatcher::matches(
            SentenceMatchKind::Glob,
            'the store * sentences.',
            'the store keeps pinned sentences.',
        ));
    }

    public function testGlobIsAnchoredAtTheStart(): void
    {
        // Unanchored this would be `contains` with extra syntax; anchored, a
        // pattern that omits the opening words misses.
        self::assertFalse(SentenceMatcher::matches(
            SentenceMatchKind::Glob,
            'keeps *',
            'the store keeps pinned sentences.',
        ));
    }

    public function testGlobIsAnchoredAtTheEnd(): void
    {
        self::assertFalse(SentenceMatcher::matches(
            SentenceMatchKind::Glob,
            '*sentences',
            'the store keeps pinned sentences.',
        ));
    }

    public function testGlobQuestionMarkMatchesExactlyOneCharacter(): void
    {
        self::assertTrue(SentenceMatcher::matches(SentenceMatchKind::Glob, 'h?t', 'hot'));
        self::assertFalse(SentenceMatcher::matches(SentenceMatchKind::Glob, 'h?t', 'hoost'));
    }

    /**
     * The reason `preg_quote` is applied before the wildcards are re-opened: a
     * sentence full of regex metacharacters must still be quotable verbatim.
     */
    public function testGlobTreatsRegexMetacharactersAsLiterals(): void
    {
        self::assertTrue(SentenceMatcher::matches(
            SentenceMatchKind::Glob,
            'costs $3.50 (approx.)',
            'costs $3.50 (approx.)',
        ));

        // `.` is a literal dot, not "any character".
        self::assertFalse(SentenceMatcher::matches(SentenceMatchKind::Glob, '3.50', '3x50'));
    }

    /**
     * Under `exact` and `contains`, `*` and `?` are ordinary characters. Prose
     * containing them must not gain wildcard powers just by being quoted back.
     */
    public function testWildcardsAreLiteralOutsideGlob(): void
    {
        self::assertTrue(SentenceMatcher::matches(
            SentenceMatchKind::Exact,
            'is it ready?',
            'is it ready?',
        ));

        self::assertFalse(SentenceMatcher::matches(
            SentenceMatchKind::Exact,
            'is it ready?',
            'is it ready!',
        ));
    }

    public function testGlobWithAColonPatternStillMatchesBecauseColonsAreNotSpecialInKeys(): void
    {
        self::assertTrue(SentenceMatcher::matches(
            SentenceMatchKind::Glob,
            'pref:editor*',
            'pref:editor is helix.',
        ));
    }

    // ---------------------------------------------------------------------- //
    // diacritics — the documented sharp edge
    // ---------------------------------------------------------------------- //

    /**
     * Deliberately asserted, not merely accepted: prose matching is
     * diacritic-sensitive where key resolution is not. The consequence is a
     * miss, which is recoverable, rather than a wrong match, which is not.
     */
    public function testMatchingIsDiacriticSensitiveUnlikeKeyResolution(): void
    {
        self::assertFalse(SentenceMatcher::matches(SentenceMatchKind::Exact, 'cafe', 'café'));
        self::assertTrue(SentenceMatcher::matches(SentenceMatchKind::Exact, 'café', 'café'));
    }
}
