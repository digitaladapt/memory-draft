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
}
