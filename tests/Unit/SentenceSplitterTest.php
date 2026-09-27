<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\SentenceSplitter;
use PHPUnit\Framework\TestCase;

/**
 * Sentence splitting is a heuristic on prose, so the interesting cases are the
 * ones where a naive split would be wrong.
 */
final class SentenceSplitterTest extends TestCase
{
    public function testSplitsOnBoundaries(): void
    {
        self::assertSame(['One.', 'Two.', 'Three.'], SentenceSplitter::split('One. Two. Three.'));
    }

    public function testProtectsAbbreviationsAndTheirCasing(): void
    {
        self::assertSame(
            ['I met Dr. Smith yesterday.', 'He was kind.'],
            SentenceSplitter::split('I met Dr. Smith yesterday. He was kind.')
        );
    }

    public function testProtectsInitials(): void
    {
        self::assertSame(
            ['Written by J. Adams.', 'Reviewed later.'],
            SentenceSplitter::split('Written by J. Adams. Reviewed later.')
        );
    }

    public function testProtectsDecimals(): void
    {
        self::assertSame(['It costs 3.50 today.', 'Cheap.'], SentenceSplitter::split('It costs 3.50 today. Cheap.'));
    }

    public function testNewlinesAreBoundaries(): void
    {
        self::assertSame(['first line.', 'second line'], SentenceSplitter::split("first line\nsecond line"));
    }

    public function testEmptyInputYieldsNothing(): void
    {
        self::assertSame([], SentenceSplitter::split(''));
        self::assertSame([], SentenceSplitter::split("  \n "));
    }

    public function testNormalizeAcceptsAnExplicitList(): void
    {
        // The explicit list is the lossless path — no splitting to get wrong.
        self::assertSame(
            ['First.', 'Second.'],
            SentenceSplitter::normalize(['First.', 'Second.'])
        );
    }

    public function testNormalizeSplitsEachListItem(): void
    {
        self::assertSame(
            ['A one.', 'A two.', 'B one.'],
            SentenceSplitter::normalize(['A one. A two.', 'B one.'])
        );
    }

    public function testHumanizeAgeBuckets(): void
    {
        $now = strtotime('2026-06-01T00:00:00Z');

        self::assertSame('just now', SentenceSplitter::humanizeAge('2026-06-01T00:00:00Z', $now));
        self::assertSame('10m ago', SentenceSplitter::humanizeAge('2026-05-31T23:50:00Z', $now));
        self::assertSame('2h ago', SentenceSplitter::humanizeAge('2026-05-31T22:00:00Z', $now));
        self::assertSame('3d ago', SentenceSplitter::humanizeAge('2026-05-29T00:00:00Z', $now));
        self::assertSame('2mo ago', SentenceSplitter::humanizeAge('2026-04-01T00:00:00Z', $now));
        self::assertSame('1y ago', SentenceSplitter::humanizeAge('2025-05-01T00:00:00Z', $now));
    }

    public function testHumanizeAgeTreatsNearInstantAsJustNow(): void
    {
        $now = strtotime('2026-06-01T00:00:00Z');
        self::assertSame('just now', SentenceSplitter::humanizeAge('2026-05-31T23:59:40Z', $now));
    }

    public function testHumanizeAgeHandlesGarbage(): void
    {
        self::assertSame('?', SentenceSplitter::humanizeAge('not-a-date'));
    }
}
