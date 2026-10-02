<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Dto\RememberItem;
use App\Infrastructure\Storage\MemoryRepository;
use App\Infrastructure\Storage\SqliteConnection;
use App\Service\MemoryService;
use PHPUnit\Framework\TestCase;

/**
 * Service-level behaviour, exercised the way a caller would use it.
 *
 * The repository is built directly against an in-memory database rather than
 * through the container, so each test starts from a clean store with no shared
 * state and no fixtures.
 */
final class MemoryServiceTest extends TestCase
{
    private MemoryService $service;

    #[\Override]
    protected function setUp(): void
    {
        // A fresh in-memory store per test, so no test can see another's data.
        // PHPUnit guarantees this runs before every test method; PHPStan knows
        // that too, via phpstan/phpstan-phpunit (see phpstan.neon.dist).
        $this->service = self::service();
    }

    private static function service(int $hotCap = 20, int $coldCap = 200, string $path = ':memory:'): MemoryService
    {
        return new MemoryService(
            new MemoryRepository(SqliteConnection::fromPath($path), $hotCap, $coldCap)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recallOne(string $key, int $depth = 2, bool $cold = false): array
    {
        return $this->service->recall([['key' => $key, 'depth' => $depth, 'includeCold' => $cold]])['hits'][0];
    }

    /**
     * @param array<string, mixed> $hit
     *
     * @return list<string>
     */
    private function texts(array $hit): array
    {
        return array_map(static fn (array $e): string => $e['text'], $hit['entries']);
    }

    // ---------------------------------------------------------------------- //
    // near-match resolution — the headline feature
    // ---------------------------------------------------------------------- //

    public function testSpellingVariantResolvesInOneRoundTrip(): void
    {
        $this->service->remember([new RememberItem(key: 'context-shuttle', sentences: 'A PHP gateway.')]);

        // Asked for with a different spelling entirely; answered with content.
        // Because the camel-case spelling canonicalises to the same slug, this
        // is an EXACT match — no fuzzy resolution is even needed, which is the
        // best outcome available.
        $hit = $this->recallOne('ContextShuttle');

        self::assertSame('context-shuttle', $hit['key'], 'the canonical key answers');
        self::assertSame('ContextShuttle', $hit['resolved_from'], 'the variant is reported, not hidden');
        self::assertSame('exact', $hit['match']);
        self::assertSame(['A PHP gateway.'], $this->texts($hit));
    }

    /**
     * A spelling that canonicalises differently but shares an identity still
     * resolves — this is the case the fuzzy tier exists for.
     */
    public function testSpellingThatSlugsDifferentlyStillResolvesByIdentity(): void
    {
        $this->service->remember([new RememberItem(key: 'context-shuttle', sentences: 'A PHP gateway.')]);

        $hit = $this->recallOne('contextshuttle');

        self::assertSame('context-shuttle', $hit['key']);
        self::assertSame('slug', $hit['match'], 'resolved by identity, not by exact spelling');
        self::assertNotEmpty($hit['entries']);
    }

    public function testEverySpellingVariantResolves(): void
    {
        $this->service->remember([new RememberItem(key: 'context-shuttle', sentences: 'A PHP gateway.')]);

        foreach (['ContextShuttle', 'context_shuttle', 'Context Shuttle', 'CONTEXTSHUTTLE'] as $variant) {
            $hit = $this->recallOne($variant);
            self::assertSame('context-shuttle', $hit['key'], "variant {$variant} must resolve");
            self::assertNotEmpty($hit['entries']);
        }
    }

    public function testUnknownKeySuggestsRatherThanReturningSilence(): void
    {
        $this->service->remember([new RememberItem(key: 'project:context-shuttle', sentences: 'PHP rewrite.')]);

        $result = $this->service->recall([['key' => 'context-shuttle']]);

        self::assertSame([], $result['hits'], 'no silent empty hit');
        self::assertCount(1, $result['misses']);
        self::assertSame('context-shuttle', $result['misses'][0]['key']);
        self::assertContains('project:context-shuttle', $result['misses'][0]['suggestions']);
    }

    public function testWhollyUnrelatedKeyYieldsNoMisleadingSuggestion(): void
    {
        // Suggesting something that shares nothing would be worse than
        // suggesting nothing: it invites the caller to trust a wrong key.
        $this->service->remember([new RememberItem(key: 'project:context-shuttle', sentences: 'PHP rewrite.')]);

        $miss = $this->service->recall([['key' => 'completely-unrelated']])['misses'][0];

        self::assertSame([], $miss['suggestions']);
    }

    public function testMissSuggestsOnASharedToken(): void
    {
        $this->service->remember([new RememberItem(key: 'project:context-shuttle', sentences: 'A PHP gateway.')]);

        $miss = $this->service->recall([['key' => 'shuttle']])['misses'][0];

        self::assertContains('project:context-shuttle', $miss['suggestions'], 'a shared token is enough to suggest');
    }

    public function testReadOfAVariantResolvesButDoesNotRecordAnAlias(): void
    {
        // A read resolves the variant via its identity, and does NOT mutate the
        // store: aliases are learned on write, so the read path stays a read.
        $this->service->remember([new RememberItem(key: 'context-shuttle', sentences: 'A PHP gateway.')]);

        $hit = $this->recallOne('contextshuttle');

        self::assertSame('slug', $hit['match']);
        self::assertSame('context-shuttle', $hit['key'], 'the readable form is canonical');
        self::assertSame([], $this->service->keys()[0]['aliases']);
    }

    public function testWriteThroughAVariantRecordsAnAlias(): void
    {
        $this->service->remember([new RememberItem(key: 'context-shuttle', sentences: 'A PHP gateway.')]);

        // Writing through the variant is what records it.
        $this->service->remember([new RememberItem(key: 'contextshuttle', sentences: 'Written through a variant.')]);

        // From then on it is a known alias, reported as such.
        $hit = $this->recallOne('contextshuttle');

        self::assertSame('alias', $hit['match']);
        self::assertSame('context-shuttle', $hit['key']);
        self::assertContains('contextshuttle', $hit['aliases']);
    }

    public function testRepeatedReadsNeverMutateTheStore(): void
    {
        // Guards the read path against quietly becoming a write path as the
        // matching logic grows.
        $this->service->remember([new RememberItem(key: 'context-shuttle', sentences: 'A PHP gateway.')]);

        $this->recallOne('contextshuttle');
        $this->recallOne('context_shuttle');
        $this->recallOne('CONTEXTSHUTTLE');

        self::assertSame([], $this->service->keys()[0]['aliases'], 'reads must not mutate the store');
        self::assertCount(1, $this->service->keys(), 'reads must not create keys');
    }

    public function testWritingThroughAVariantStoresUnderTheCanonicalKey(): void
    {
        $this->service->remember([new RememberItem(key: 'context-shuttle', sentences: 'First.')]);
        $this->service->remember([new RememberItem(key: 'ContextShuttle', sentences: 'Second.')]);

        $hit = $this->recallOne('context-shuttle');

        self::assertSame('context-shuttle', $hit['key']);
        self::assertSame(2, $hit['hot'], 'both writes land on one record');
        self::assertCount(1, $this->service->keys(), 'no duplicate key was created');
    }

    public function testMissesDoNotSpoilOtherHitsInABatch(): void
    {
        $this->service->remember([new RememberItem(key: 'alpha', sentences: 'A fact.')]);

        $result = $this->service->recall([['key' => 'alpha'], ['key' => 'nope'], ['key' => 'alpha']]);

        self::assertCount(2, $result['hits']);
        self::assertCount(1, $result['misses']);
    }

    // ---------------------------------------------------------------------- //
    // revisions
    // ---------------------------------------------------------------------- //

    public function testWriteWithoutRevisionAppliesAsCurrent(): void
    {
        $report = $this->service->remember([new RememberItem(key: 'k', sentences: 'A fact.')]);

        self::assertSame('current', $report['results'][0]['intent']);
        self::assertSame(1, $report['results'][0]['revision']);
    }

    public function testMatchingRevisionIsConfirmed(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'A fact.')]);
        $hit = $this->recallOne('k');
        self::assertSame(1, $hit['revision']);

        $report = $this->service->remember([
            new RememberItem(key: 'k', sentences: 'Another fact.', revision: $hit['revision']),
        ]);

        self::assertSame('confirmed', $report['results'][0]['intent']);
    }

    public function testStaleRevisionIsKeptAndFlaggedAsBackfilled(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Original.')]);
        $staleRevision = $this->recallOne('k')['revision'];

        // Someone else writes, moving the revision on.
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Concurrent update.')]);
        $currentRevision = $this->recallOne('k')['revision'];
        self::assertGreaterThan($staleRevision, $currentRevision);

        // Now the slow writer submits against the revision it read.
        $report = $this->service->remember([
            new RememberItem(key: 'k', sentences: 'Written from the old picture.', revision: $staleRevision),
        ]);

        self::assertSame('backfill', $report['results'][0]['intent']);

        $hit = $this->recallOne('k', depth: 10);
        $byText = [];
        foreach ($hit['entries'] as $entry) {
            $byText[$entry['text']] = $entry;
        }

        self::assertArrayHasKey('Written from the old picture.', $byText, 'the stale write is NOT discarded');
        self::assertTrue($byText['Written from the old picture.']['backfilled'], 'it is flagged');
        self::assertFalse($byText['Concurrent update.']['backfilled'], 'current knowledge is not flagged');
        self::assertSame(1, $hit['backfilled']);
    }

    public function testBackfilledRanksBelowCurrentKnowledgeButStaysHot(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Original.')]);
        $stale = $this->recallOne('k')['revision'];
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Newer.')]);
        $this->service->remember([new RememberItem(key: 'k', sentences: 'From the old picture.', revision: $stale)]);

        $hit = $this->recallOne('k', depth: 10);

        // Depth 1 must show the current fact, not the backfilled one.
        self::assertSame('Newer.', $this->texts($this->recallOne('k', depth: 1))[0]);

        // And the backfilled row is still hot, not demoted to cold.
        $entry = current(array_filter($hit['entries'], static fn (array $e): bool => $e['backfilled']));
        self::assertNotFalse($entry);
        self::assertSame('hot', $entry['tier'], 'backfill is ranked, not exiled to cold');
    }

    public function testBackfillDoesNotRetireCurrentSentences(): void
    {
        // A `replace` from a stale caller must not wipe what a newer caller
        // just established — that would let a slow writer destroy good facts.
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Current truth.')]);
        $stale = $this->recallOne('k')['revision'];
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Newer truth.')]);

        $this->service->remember([
            new RememberItem(key: 'k', sentences: 'Stale replacement.', mode: 'replace', revision: $stale),
        ]);

        $texts = $this->texts($this->recallOne('k', depth: 10));
        self::assertContains('Newer truth.', $texts, 'current knowledge survives a stale replace');
        self::assertContains('Stale replacement.', $texts);
    }

    // ---------------------------------------------------------------------- //
    // trimming / cold storage
    // ---------------------------------------------------------------------- //

    public function testTrimDemotesRatherThanDeletes(): void
    {
        $service = self::service(hotCap: 2);
        $service->remember([new RememberItem(key: 'k', sentences: 'Fact one.')]);
        $service->remember([new RememberItem(key: 'k', sentences: 'Fact two.')]);
        $report = $service->remember([new RememberItem(key: 'k', sentences: 'Fact three.')]);

        self::assertCount(1, $report['results'][0]['evicted'], 'the oldest is demoted');
        self::assertSame('Fact one.', $report['results'][0]['evicted'][0]['text'], 'and reported on write');

        $hot = $service->recall([['key' => 'k']])['hits'][0];
        self::assertSame(2, $hot['hot']);
        self::assertSame(1, $hot['cold'], 'nothing lost');

        $withCold = $service->recall([['key' => 'k', 'depth' => 10, 'includeCold' => true]])['hits'][0];
        self::assertContains('Fact one.', array_map(static fn (array $e): string => $e['text'], $withCold['entries']));
    }

    public function testReplaceRetiresRatherThanDestroys(): void
    {
        $this->service->remember([new RememberItem(key: 'status', sentences: 'Phase one is done.')]);
        $report = $this->service->remember([
            new RememberItem(key: 'status', sentences: 'Phase two is underway.', mode: 'replace'),
        ]);

        self::assertCount(1, $report['results'][0]['retired'], 'the old value is retired, visibly');

        $hot = $this->recallOne('status');
        self::assertSame(['Phase two is underway.'], $this->texts($hot));
        self::assertSame(1, $hot['cold'], 'the retired value is still recoverable');

        $cold = $this->recallOne('status', depth: 10, cold: true);
        self::assertContains('Phase one is done.', $this->texts($cold));
    }

    public function testReplaceOnAFreshKeyRetiresNothing(): void
    {
        $report = $this->service->remember([
            new RememberItem(key: 'fresh', sentences: 'First write.', mode: 'replace'),
        ]);

        self::assertSame([], $report['results'][0]['retired']);
        self::assertSame(1, $report['results'][0]['added']);
    }

    public function testPinnedSentencesSurviveTrimming(): void
    {
        $service = self::service(hotCap: 1);
        $service->remember([new RememberItem(key: 'k', sentences: 'Critical constraint.', pin: true)]);

        for ($i = 0; $i < 5; ++$i) {
            $service->remember([new RememberItem(key: 'k', sentences: "Chatter {$i}.")]);
        }

        $hit = $service->recall([['key' => 'k', 'depth' => 10]])['hits'][0];
        self::assertContains('Critical constraint.', array_map(static fn (array $e): string => $e['text'], $hit['entries']));
    }

    public function testColdTierIsItselfCappedThenPurged(): void
    {
        $service = self::service(hotCap: 1, coldCap: 2);

        for ($i = 0; $i < 6; ++$i) {
            $report = $service->remember([new RememberItem(key: 'k', sentences: "Fact {$i}.")]);
        }

        // The last write is the one that overflows the cold budget.
        self::assertNotEmpty($report['results'][0]['purged'], 'deletion is reported when it happens');

        $hit = $service->recall([['key' => 'k', 'depth' => 10, 'includeCold' => true]])['hits'][0];
        self::assertSame(3, $hit['hot'] + $hit['cold'], '1 hot + 2 cold, the rest genuinely gone');
    }

    // ---------------------------------------------------------------------- //
    // renewal
    // ---------------------------------------------------------------------- //

    public function testReassertingRenewsRatherThanDuplicating(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Still true.')]);
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Something else.')]);

        $report = $this->service->remember([new RememberItem(key: 'k', sentences: 'Still true.')]);

        self::assertSame(1, $report['results'][0]['renewed']);
        self::assertSame(0, $report['results'][0]['added']);
        self::assertSame(2, $this->recallOne('k')['hot'], 'no duplicate was created');
    }

    public function testReassertingADemotedFactPromotesItBackToHot(): void
    {
        $service = self::service(hotCap: 1);
        $service->remember([new RememberItem(key: 'k', sentences: 'Important.')]);
        $service->remember([new RememberItem(key: 'k', sentences: 'Chatter.')]);
        self::assertSame(1, $service->recall([['key' => 'k']])['hits'][0]['cold']);

        $report = $service->remember([new RememberItem(key: 'k', sentences: 'Important.')]);
        self::assertSame(1, $report['results'][0]['promoted']);

        $hit = $service->recall([['key' => 'k', 'depth' => 10, 'includeCold' => true]])['hits'][0];
        $matching = array_filter($hit['entries'], static fn (array $e): bool => 'Important.' === $e['text']);
        self::assertCount(1, $matching, 'a fact exists exactly once');
        self::assertSame('hot', array_values($matching)[0]['tier']);
    }

    // ---------------------------------------------------------------------- //
    // ordering
    // ---------------------------------------------------------------------- //

    public function testProseReadsForwardsWithinOneWrite(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'First. Second. Third.')]);

        self::assertSame(['First.', 'Second.', 'Third.'], $this->texts($this->recallOne('k', depth: 10)));
    }

    public function testShallowRecallKeepsTheLeadSentence(): void
    {
        // The lead sentence carries the topic, so it must be the last thing
        // truncated — this was a real bug in the prototype.
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Lead fact. Detail one. Detail two.')]);

        self::assertSame(['Lead fact.'], $this->texts($this->recallOne('k', depth: 1)));
    }

    public function testNewestWriteLeads(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Older.')]);
        $this->service->remember([new RememberItem(key: 'k', sentences: 'Newer.')]);

        self::assertSame('Newer.', $this->texts($this->recallOne('k', depth: 10))[0]);
    }

    // ---------------------------------------------------------------------- //
    // keyspace & stats
    // ---------------------------------------------------------------------- //

    public function testKeysListsTheKeyspaceWithAliases(): void
    {
        $this->service->remember([new RememberItem(key: 'project:alpha', sentences: 'One.')]);
        $this->service->remember([new RememberItem(key: 'ProjectAlpha', sentences: 'Two.')]); // records the alias

        $keys = $this->service->keys();

        self::assertCount(1, $keys, 'the variant did not create a second key');
        self::assertSame('project:alpha', $keys[0]['key']);
        self::assertSame(2, $keys[0]['hot']);
        self::assertContains('project-alpha', $keys[0]['aliases']);
    }

    public function testPinnedSentencesAreReturnedRegardlessOfDepth(): void
    {
        // A key whose durable half is longer than the requested depth must not
        // answer with a prefix of it. `depth` bounds chatter, not the facts a
        // caller has explicitly said must not age out — this is the "asked for
        // soul, got two of nine" failure.
        $this->service->remember([new RememberItem(
            key: 'soul',
            sentences: 'Pinned one. Pinned two. Pinned three. Pinned four. Pinned five.',
            pin: true,
        )]);
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Chatter one. Chatter two.')]);

        $hit = $this->recallOne('soul', depth: 2);

        self::assertSame(5, $hit['pinned'], 'the pinned count is reported, not implied');
        self::assertSame(7, $hit['shown'], 'all five pinned plus the two requested');
        self::assertSame(
            ['Pinned one.', 'Pinned two.', 'Pinned three.', 'Pinned four.', 'Pinned five.', 'Chatter one.', 'Chatter two.'],
            $this->texts($hit),
            'pinned facts come first and none are dropped',
        );
    }

    public function testKeysReportsPinnedCountPerKey(): void
    {
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Durable one. Durable two.', pin: true)]);
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Noise.')]);

        $keys = $this->service->keys();

        self::assertSame('soul', $keys[0]['key']);
        self::assertSame(2, $keys[0]['pinned'], 'the durable half is visible without recalling the key');
        self::assertSame(3, $keys[0]['hot']);
    }

    public function testStatsReportsCapsAndCounts(): void
    {
        $service = self::service(hotCap: 7, coldCap: 9);
        $service->remember([new RememberItem(key: 'k', sentences: 'One. Two.', pin: true)]);

        $stats = $service->stats();

        self::assertSame(2, $stats['total']);
        self::assertSame(2, $stats['pinned']);
        self::assertSame(7, $stats['caps']['hot_per_key']);
        self::assertSame(9, $stats['caps']['cold_per_key']);
    }

    public function testEmptyWriteIsReportedNotStored(): void
    {
        $report = $this->service->remember([new RememberItem(key: 'k', sentences: '   ')]);

        self::assertTrue($report['results'][0]['empty']);
        self::assertSame([], $this->service->recall([['key' => 'k']])['hits']);
    }

    public function testForgetIsExplicitAndReported(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'One. Two.')]);

        self::assertSame(['key' => 'k', 'deleted' => 2], $this->service->forget('k'));
        self::assertSame([], $this->service->recall([['key' => 'k']])['hits']);
        self::assertSame(['key' => 'k', 'deleted' => 0], $this->service->forget('k'), 'idempotent');
    }

    public function testDataPersistsAcrossReopen(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'memtest').'.db';

        try {
            $first = self::service(path: $path);
            $first->remember([new RememberItem(key: 'k', sentences: 'Persisted.')]);

            $second = self::service(path: $path);
            $hit = $second->recall([['key' => 'k']])['hits'][0];

            self::assertSame(['Persisted.'], $this->texts($hit));
            self::assertSame(1, $hit['revision'], 'the revision survives too');
        } finally {
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }

    public function testReStatingASentenceDoesNotClearItsPin(): void
    {
        // Re-stating a fact is the canonical "this is still true" gesture, and
        // it must not also be an unpin. If it is, the store converts its
        // strongest durability signal into a disposable one, silently — the
        // loss only shows up when the trimmer eventually eats the fact.
        $this->service->remember([
            new RememberItem(key: 'ops', sentences: 'Rotate the deploy key monthly.', pin: true),
        ]);

        $report = $this->service->remember([
            new RememberItem(key: 'ops', sentences: 'Rotate the deploy key monthly.'),
        ]);

        self::assertSame(1, $report['results'][0]['renewed'], 'the sentence is renewed, not duplicated');
        self::assertSame(1, $this->recallOne('ops')['pinned'], 'an omitted pin leaves an existing pin alone');
    }

    public function testAnUnrelatedWriteDoesNotUnpinAnotherSentence(): void
    {
        // The blunt form of the same bug: storing one unpinned fact must not
        // disturb the pin on a different one.
        $this->service->remember([new RememberItem(key: 'ops', sentences: 'Durable.', pin: true)]);

        $this->service->remember([new RememberItem(key: 'ops', sentences: 'Ephemeral.', pin: false)]);

        self::assertSame(1, $this->recallOne('ops')['pinned']);
    }

    public function testPinFalseExplicitlyUnpins(): void
    {
        // The other half of the distinction: `false` is a real instruction, so
        // there is a way to take a pin back that is not `forget` (which would
        // destroy the history the store promises to keep).
        $this->service->remember([new RememberItem(key: 'ops', sentences: 'Once durable.', pin: true)]);
        self::assertSame(1, $this->recallOne('ops')['pinned']);

        $this->service->remember([new RememberItem(key: 'ops', sentences: 'Once durable.', pin: false)]);

        self::assertSame(0, $this->recallOne('ops')['pinned'], 'pin:false removes a pin');
    }

    // ---------------------------------------------------------------------- //
    // recency — "where did we leave off?"
    // ---------------------------------------------------------------------- //

    /**
     * The one question a keyword store cannot be asked by name.
     *
     * A caller opening a session knows no keys, so every recall it can make is
     * a guess. Asking for the latest is the only request that needs no prior
     * knowledge, and it is the missing half of "what do I know": `memory_keys`
     * can enumerate, but it cannot rank. Recency is already in the store — every
     * write stamps `updated_at` — so this is a read of existing data, not new
     * state.
     */
    public function testLatestReturnsTheMostRecentlyWrittenKeysFirst(): void
    {
        // Written oldest-first, and ten of them land inside one second — which
        // is the ordinary case, not an edge case, and is exactly what
        // second-resolution timestamps cannot order on their own.
        foreach (range(1, 10) as $i) {
            $this->service->remember([new RememberItem(key: "key{$i}", sentences: "Fact {$i}.")]);
        }

        $result = $this->service->recall([['latest' => 3]]);

        self::assertSame(
            ['key10', 'key9', 'key8'],
            array_column($result['hits'], 'key'),
            'the newest writes lead, even inside a single second',
        );
    }

    public function testLatestReportsItsOwnKindRatherThanClaimingAMatch(): void
    {
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Durable.')]);

        $hit = $this->service->recall([['latest' => true]])['hits'][0];

        // Not `exact`: nothing was named. Reporting a match kind would tell the
        // caller it had asked for this key, which it did not.
        self::assertSame('recent', $hit['match']);
        self::assertArrayNotHasKey('resolved_from', $hit, 'nothing was requested, so nothing was resolved');
    }

    public function testLatestDefaultsToTheServiceCountRatherThanACallerChosenOne(): void
    {
        foreach (range(1, 20) as $i) {
            $this->service->remember([new RememberItem(key: "key{$i}", sentences: "Fact {$i}.")]);
        }

        // `true` means "the service decides". This is what lets a client — a
        // small model above all — ask for recency without naming a number, and
        // it is why tuning the default needs no client release.
        $result = $this->service->recall([['latest' => true]]);

        self::assertCount(MemoryService::LATEST_COUNT, $result['hits']);
        self::assertSame(MemoryService::LATEST_COUNT, $result['latest']['count']);
    }

    public function testTheRecencyAnswerSaysWhatItDid(): void
    {
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Durable.')]);

        $meta = $this->service->recall([['latest' => true]])['latest'];

        // The failure mode of a default is a caller mistaking it for a
        // deliberate recall, so the answer carries its own explanation —
        // authored by the service, so console and wire cannot disagree.
        self::assertStringContainsString('No keys given', $meta['note']);
        self::assertStringContainsString((string) MemoryService::LATEST_COUNT, $meta['note']);
        self::assertSame(1, $meta['included']);
        self::assertSame(1, $meta['available']);
        self::assertArrayHasKey('newest', $meta);
        self::assertArrayHasKey('oldest', $meta);
    }

    public function testAnEmptyStoreAnswersARecencyReadWithANoteRatherThanAnError(): void
    {
        // Nothing has been written yet. That is a fact about the store, not a
        // caller bug, so it is a 200 with an explanation — the opposite of the
        // empty *named* recall, which is refused because it is a bug.
        $result = $this->service->recall([['latest' => true]]);

        self::assertSame([], $result['hits']);
        self::assertSame(0, $result['latest']['included']);
        self::assertSame(0, $result['latest']['available']);
        self::assertStringContainsString('no keys', $result['latest']['note']);
    }

    /**
     * Breadth is counted in keys, not sentences.
     *
     * One chatty key must not own the answer: a mail summary is written every
     * few hours and would take every slot in a "N newest sentences" design,
     * crowding out the conversation the caller actually wanted.
     */
    public function testChattyKeysDoNotMonopoliseARecencyRead(): void
    {
        foreach (range(1, 40) as $i) {
            $this->service->remember([new RememberItem(key: 'email-summary', sentences: "Summary {$i}.")]);
        }
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'A decision was made.')]);

        $result = $this->service->recall([['latest' => true]]);
        $keys = array_column($result['hits'], 'key');

        self::assertContains('email-summary', $keys);
        self::assertContains('soul', $keys, 'the quiet conversation survives the chatty key');
        self::assertSame(2, $result['latest']['included']);
    }

    public function testARecencyReadIsShallowPerKeyButNotSilentAboutIt(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'One. Two. Three. Four. Five.')]);

        $hit = $this->service->recall([['latest' => true]])['hits'][0];

        self::assertCount(MemoryService::LATEST_DEPTH, $hit['entries']);
        self::assertSame(5, $hit['hot'], 'the total is reported, so a short view is not a mystery');
        self::assertArrayHasKey('last_written', $hit, 'a recency read is a question about time');
    }

    /**
     * A key named *and* recent is attributed to the named occurrence.
     *
     * The named hit is the stronger claim — the caller asked about that subject
     * specifically — so the recency expansion must not append a second copy of
     * the same key with its own shallower view.
     */
    public function testAKeyNamedAndRecentAppearsOnce(): void
    {
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Durable one. Durable two.')]);
        $this->service->remember([new RememberItem(key: 'other', sentences: 'Something else.')]);

        $result = $this->service->recall([
            ['key' => 'soul', 'depth' => 12],
            ['latest' => 5],
        ]);

        $souls = array_filter($result['hits'], static fn (array $h): bool => 'soul' === $h['key']);
        self::assertCount(1, $souls, 'the named occurrence wins; no duplicate');

        $hit = array_values($souls)[0];
        self::assertSame('exact', $hit['match'], 'it answers the name, not the recency request');
        self::assertCount(2, $hit['entries'], 'and it keeps the depth the caller named for it');

        // `available` excludes what was already answered by name, so
        // `included < available` keeps meaning "there are keys you are not
        // seeing" rather than being confounded by the de-duplication.
        self::assertSame(1, $result['latest']['available']);
        self::assertSame(1, $result['latest']['included']);
    }

    public function testANamedDomainKeyIsAlsoNamedWhenSpelledWithADifferentCase(): void
    {
        // De-duplication is by canonical key, not by the string the caller
        // typed: `Soul` and `soul` are one key, and the expansion must not add
        // it back under the other spelling.
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Durable.')]);

        $result = $this->service->recall([['key' => 'SOUL'], ['latest' => 5]]);

        self::assertCount(1, $result['hits'], 'one key, one hit');
    }

    public function testNamedKeysLeadAndRecencyFollows(): void
    {
        // The answer reads predictably — what you asked for, then what is
        // recent — regardless of where the recency entry sat in the batch.
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Durable.')]);
        $this->service->remember([new RememberItem(key: 'later', sentences: 'More recent.')]);

        foreach ([
            [['latest' => 5], ['key' => 'soul']],
            [['key' => 'soul'], ['latest' => 5]],
        ] as $queries) {
            $hits = $this->service->recall($queries)['hits'];
            self::assertSame('soul', $hits[0]['key'], 'the named key leads either way');
            self::assertSame('later', $hits[1]['key']);
        }
    }

    public function testRecencyNoteTellsTheTruthWhenSomeNamedKeysMiss(): void
    {
        // Nothing named resolved, so nothing was answered by name — but keys do
        // remain to show. The note must not claim the store is empty.
        $this->service->remember([new RememberItem(key: 'soul', sentences: 'Durable.')]);

        $result = $this->service->recall([['key' => 'nobody-knows'], ['latest' => 5]]);

        self::assertCount(1, $result['misses']);
        self::assertSame('soul', $result['hits'][0]['key']);
        self::assertSame('No keys given — showing', substr($result['latest']['note'], 0, 25));
    }

    public function testARecencyReadDoesNotExposeColdOrGrowTheStore(): void
    {
        // A read is a read. Recency reuses the keyspace query, so it must not
        // create keys, learn aliases, or reach into cold storage.
        $service = self::service(hotCap: 1);
        $service->remember([new RememberItem(key: 'k', sentences: 'Old.')]);
        $service->remember([new RememberItem(key: 'k', sentences: 'New.')]);

        $before = $service->stats();
        $hit = $service->recall([['latest' => true]])['hits'][0];

        self::assertSame(['New.'], $this->texts($hit));
        self::assertSame(1, $hit['cold'], 'the cold tier is not silently folded in');
        self::assertSame($before, $service->stats(), 'a recency read mutates nothing');
        self::assertSame([], $service->keys()[0]['aliases']);
    }

    public function testAnOutOfRangeCountIsClampedRatherThanInvertingTheRead(): void
    {
        // The wire rejects these, but the service is called directly too — and a
        // `0` that silently returned the *entire store* would be the worst kind
        // of surprise: a validation gap that reads as a feature.
        $this->service->remember([new RememberItem(key: 'a', sentences: 'One.')]);
        $this->service->remember([new RememberItem(key: 'b', sentences: 'Two.')]);

        self::assertCount(1, $this->service->recall([['latest' => 0]])['hits']);
        self::assertCount(2, $this->service->recall([['latest' => 999]])['hits']);
    }

    public function testOurOwnKeyspaceListingIsNewestFirst(): void
    {
        // `memory_keys` is what a caller reads to discover names, and it was
        // ordered by `updated_at` alone — so same-second writes came back oldest
        // first. Harmless as a listing; wrong as a ranking, which is what
        // recency made it.
        foreach (range(1, 5) as $i) {
            $this->service->remember([new RememberItem(key: "key{$i}", sentences: "Fact {$i}.")]);
        }

        self::assertSame(
            ['key5', 'key4', 'key3', 'key2', 'key1'],
            array_column($this->service->keys(), 'key'),
        );
    }

    public function testLastWrittenIsReportedOnAnOrdinaryRecallToo(): void
    {
        $this->service->remember([new RememberItem(key: 'k', sentences: 'A fact.')]);

        self::assertSame('just now', $this->recallOne('k')['last_written']);
    }
}
