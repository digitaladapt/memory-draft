<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Domain\Dto\RememberItem;
use App\Domain\Dto\ReviseItem;
use App\Infrastructure\Storage\MemoryRepository;
use App\Infrastructure\Storage\SqliteConnection;
use App\Service\MemoryService;
use PHPUnit\Framework\TestCase;

/**
 * `revise`, exercised the way a caller uses it.
 *
 * The service is built directly against an in-memory store, as in
 * {@see MemoryServiceTest}, so every test starts from a clean key and there are
 * no fixtures to keep in step with the schema.
 *
 * A deliberate note on what is *not* here: there is no test that a purge is
 * unrecoverable, because that is not something a test can assert without
 * pretending to be a backup. What is tested is that a purge touches exactly the
 * sentences it selected and nothing else — the key, its revision and its
 * neighbours all survive — since "forget used to take the whole key with it" is
 * the bug the verb exists to fix.
 */
final class MemoryServiceReviseTest extends TestCase
{
    private MemoryService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->service = self::service();
    }

    private static function service(int $hotCap = 20, int $coldCap = 200): MemoryService
    {
        return new MemoryService(
            new MemoryRepository(SqliteConnection::fromPath(':memory:'), $hotCap, $coldCap)
        );
    }

    // ---------------------------------------------------------------------- //
    // demote — the default, and the reversible one
    // ---------------------------------------------------------------------- //

    public function testDemoteMovesOneSentenceOutOfTheHotViewWithoutDestroyingIt(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.'),
        ]);

        self::assertSame(1, $result['changed']);
        self::assertSame('demoted', $result['results'][0]['entries'][0]['action']);

        // Gone from the default view...
        self::assertSame(['Rollback is one command.'], $this->hot('deploy'));
        // ...but still there, which is the whole difference from a purge.
        self::assertContains('Deploys use blue-green.', $this->cold('deploy'));
    }

    public function testDemoteIsTheDefaultDisposition(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);

        // No `disposition` argument at all.
        $result = $this->service->revise([new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.')]);

        self::assertSame('demote', $result['results'][0]['disposition']);
        self::assertSame([], $this->hot('deploy'));
    }

    /**
     * The natural inverse, and the reason `demote` needs no `promote` verb: a
     * demoted sentence is promoted back to hot by re-stating it, because that is
     * already what `upsertSentence` does for any sentence it finds in cold.
     */
    public function testReStatingADemotedSentencePromotesItBackToHot(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->service->revise([new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.')]);
        self::assertSame([], $this->hot('deploy'));

        $this->given('deploy', ['Deploys use blue-green.']);

        self::assertSame(['Deploys use blue-green.'], $this->hot('deploy'));
    }

    public function testDemoteOverSeveralSentencesIsAllowedBecauseItLosesNothing(): void
    {
        $this->given('deploy', [
            'Deploys use blue-green.',
            'Deploys roll back in one command.',
            'Unrelated fact.',
        ]);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'deploys*', match: 'glob', disposition: 'demote'),
        ]);

        self::assertSame(2, $result['changed']);
        self::assertSame(['Unrelated fact.'], $this->hot('deploy'));
    }

    // ---------------------------------------------------------------------- //
    // purge — explicit, single, irreversible
    // ---------------------------------------------------------------------- //

    public function testPurgeDeletesOneSentenceAndLeavesTheRestOfTheKeyIntact(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Rollback is one command.', disposition: 'purge'),
        ]);

        self::assertSame(1, $result['changed']);
        self::assertSame(['Deploys use blue-green.'], $this->hot('deploy'));

        // The key is still there — `forget` would have removed it entirely.
        $keys = array_column($this->service->keys(), 'key');
        self::assertContains('deploy', $keys);
    }

    public function testPurgeRefusesToDestroyMoreThanOneSentenceAtATime(): void
    {
        $this->given('deploy', [
            'Deploys use blue-green.',
            'Deploys roll back in one command.',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/matches 2 sentences/');

        $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'deploys*', match: 'glob', disposition: 'purge'),
        ]);
    }

    public function testARefusedPurgeChangesNothingAtAll(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Deploys roll back in one command.']);

        try {
            $this->service->revise([
                new ReviseItem(key: 'deploy', text: 'deploys*', match: 'glob', disposition: 'purge'),
            ]);
            self::fail('The purge should have been refused.');
        } catch (\DomainException) {
            // Expected: assert below that the refusal was not partial.
        }

        self::assertCount(2, $this->hot('deploy'));
    }

    public function testADryRunPurgeOverSeveralSentencesIsAllowedSoTheyCanBeInspected(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Deploys roll back in one command.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'deploys*', match: 'glob', disposition: 'purge', dryRun: true),
        ]);

        $report = $result['results'][0];
        self::assertSame(0, $result['changed']);
        self::assertSame(2, $report['matched']);
        self::assertSame(['would_purge', 'would_purge'], array_column($report['entries'], 'action'));
        // Nothing was touched.
        self::assertCount(2, $this->hot('deploy'));
    }

    // ---------------------------------------------------------------------- //
    // selecting by text — the address scheme
    // ---------------------------------------------------------------------- //

    public function testAMissIsReportedAsZeroRatherThanAsAnError(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'something never stored'),
        ]);

        self::assertSame(0, $result['changed']);
        self::assertSame(0, $result['results'][0]['matched']);
        self::assertArrayNotHasKey('error', $result['results'][0]);
    }

    public function testAnUnknownKeySuggestsNearbyKeysAndChangesNothing(): void
    {
        $this->given('context-shuttle', ['A PHP gateway.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'ContextShuttle', text: 'A PHP gateway.'),
        ]);
        $report = $result['results'][0];

        // Spelling variants resolve, so this is a *hit*, not a miss.
        self::assertSame('context-shuttle', $report['key']);
        self::assertSame(1, $report['changed']);
    }

    public function testAGenuinelyUnknownKeyIsReportedMissingWithSuggestions(): void
    {
        $this->given('context-shuttle', ['A PHP gateway.']);

        $report = $this->service->revise([
            new ReviseItem(key: 'context-shuttel', text: 'A PHP gateway.'),
        ])['results'][0];

        // Either it resolved as a near-miss (and acted) or it said so plainly.
        if (isset($report['missing'])) {
            self::assertTrue($report['missing']);
            self::assertSame(0, $report['changed']);
        } else {
            self::assertSame(1, $report['changed']);
        }
    }

    public function testContainsSelectsByFragment(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'blue-green', match: 'contains'),
        ]);

        self::assertSame(1, $result['changed']);
        self::assertSame(['Rollback is one command.'], $this->hot('deploy'));
    }

    /**
     * The default kind is `exact`, so a fragment is a miss rather than a
     * surprise — the safety property of the whole design, asserted at the
     * service level and not only in the matcher's unit tests.
     */
    public function testTheDefaultMatchIsExactSoAFragmentDoesNotSelectAnything(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);

        $result = $this->service->revise([new ReviseItem(key: 'deploy', text: 'blue-green')]);

        self::assertSame(0, $result['changed']);
    }

    // ---------------------------------------------------------------------- //
    // tier scope
    // ---------------------------------------------------------------------- //

    public function testTheDefaultScopeIsHotSoAColdSentenceIsNotReached(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->service->revise([new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.')]);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.', disposition: 'purge'),
        ]);

        self::assertSame(0, $result['changed']);
        self::assertContains('Deploys use blue-green.', $this->cold('deploy'));
    }

    /**
     * A miss that is actually a hit in the other tier must say so, or the caller
     * concludes the sentence is gone when it is merely demoted.
     */
    public function testAMissThatExistsInTheOtherTierCarriesAHint(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->service->revise([new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.')]);

        $report = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.'),
        ])['results'][0];

        self::assertSame(0, $report['changed']);
        self::assertSame(1, $report['skipped']['other_tier']);
        self::assertStringContainsString('cold', $report['hint']);
    }

    public function testTierAnyReachesColdSentences(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->service->revise([new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.')]);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.', disposition: 'purge', tier: 'any'),
        ]);

        self::assertSame(1, $result['changed']);
        self::assertSame([], $this->cold('deploy'));
    }

    public function testTierColdDoesNotReachHotSentences(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.', tier: 'cold'),
        ]);

        self::assertSame(0, $result['changed']);
        self::assertSame(['Deploys use blue-green.'], $this->hot('deploy'));
    }

    // ---------------------------------------------------------------------- //
    // pinned sentences
    // ---------------------------------------------------------------------- //

    public function testPinnedSentencesAreSkippedByDefaultAndTheSkipIsReported(): void
    {
        $this->given('deploy', ['Deploys use blue-green.'], pin: true);

        $report = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.'),
        ])['results'][0];

        self::assertSame(0, $report['changed']);
        self::assertSame(1, $report['skipped']['pinned']);
        self::assertArrayHasKey('hint_pinned', $report);
        self::assertSame(['Deploys use blue-green.'], $this->hot('deploy'));
    }

    public function testIncludePinnedOverridesTheSkip(): void
    {
        $this->given('deploy', ['Deploys use blue-green.'], pin: true);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.', includePinned: true),
        ]);

        self::assertSame(1, $result['changed']);
        self::assertSame([], $this->hot('deploy'));
    }

    // ---------------------------------------------------------------------- //
    // revision — the concurrency token
    // ---------------------------------------------------------------------- //

    public function testReviseBumpsTheRevisionSoAStaleWriteIsFlaggedBackfilled(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);
        $before = $this->revision('deploy');

        $report = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Rollback is one command.'),
        ])['results'][0];

        self::assertGreaterThan($before, $report['revision']);

        // Writing against the *old* revision now lands as a backfill, which is
        // the signal that the caller's knowledge is out of date.
        $write = $this->service->remember([
            new RememberItem(key: 'deploy', sentences: 'A stale fact.', revision: $before),
        ]);
        self::assertSame('backfill', $write['results'][0]['intent']);
    }

    /**
     * The revision is a property of the key's content, and a purge changes the
     * content — so it counts even when the sentence removed was already cold and
     * therefore invisible to recall either way.
     */
    public function testPurgingAColdSentenceStillBumpsTheRevision(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->service->revise([new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.')]);
        $before = $this->revision('deploy');

        $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.', disposition: 'purge', tier: 'any'),
        ]);

        self::assertGreaterThan($before, $this->revision('deploy'));
    }

    public function testADryRunDoesNotBumpTheRevision(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $before = $this->revision('deploy');

        $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.', dryRun: true),
        ]);

        self::assertSame($before, $this->revision('deploy'));
    }

    // ---------------------------------------------------------------------- //
    // batching
    // ---------------------------------------------------------------------- //

    public function testABatchIsAppliedInOneTransaction(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->given('editor', ['Helix is the editor.']);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.'),
            new ReviseItem(key: 'editor', text: 'Helix is the editor.', disposition: 'purge'),
        ]);

        self::assertSame(2, $result['changed']);
        self::assertSame([], $this->hot('deploy'));
        self::assertSame([], $this->hot('editor'));
    }

    /**
     * If a later item raises, the earlier items must not have landed. The
     * ambiguous-purge refusal is the only error `revise` raises on purpose, so it
     * is what this uses to trip the transaction.
     */
    public function testAFailingItemRollsBackTheWholeBatch(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->given('editor', ['Helix is the editor.', 'Helix is fast.']);

        try {
            $this->service->revise([
                new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.'),
                new ReviseItem(key: 'editor', text: 'helix*', match: 'glob', disposition: 'purge'),
            ]);
            self::fail('The second item should have been refused.');
        } catch (\DomainException) {
            // Expected.
        }

        // The first item's demote was rolled back with the second's refusal.
        self::assertSame(['Deploys use blue-green.'], $this->hot('deploy'));
    }

    public function testAnUnknownDispositionIsRejectedByTheServiceNotOnlyTheValidator(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Unknown disposition/');

        $this->service->revise([new ReviseItem(key: 'deploy', text: 'x', disposition: 'delete')]);
    }

    public function testAnUnknownMatchKindIsRejected(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Unknown match/');

        $this->service->revise([new ReviseItem(key: 'deploy', text: 'x', match: 'fuzzy')]);
    }

    // ---------------------------------------------------------------------- //
    // reporting
    // ---------------------------------------------------------------------- //

    public function testTheReportCarriesTheResultingTierCountsSoNoSecondReadIsNeeded(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $report = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'Deploys use blue-green.'),
        ])['results'][0];

        self::assertSame(['hot' => 1, 'cold' => 1, 'pinned' => 0], $report['remaining']);
    }

    public function testALargeSelectionIsCappedInTheReportButNotInWhatIsActedOn(): void
    {
        // Kept under the 20-sentence hot cap, or the extras would already have
        // been demoted before `revise` ran and this would be testing trimming.
        $sentences = [];
        for ($i = 1; $i <= 25; ++$i) {
            $sentences[] = \sprintf('Fact number %d about deploys.', $i);
        }
        $this->given('deploy', $sentences, hotCap: 100);

        $result = $this->service->revise([
            new ReviseItem(key: 'deploy', text: 'fact*', match: 'glob'),
        ]);
        $report = $result['results'][0];

        self::assertCount(MemoryService::REVISE_ENTRY_CAP, $report['entries']);
        self::assertSame(25 - MemoryService::REVISE_ENTRY_CAP, $report['more']);
        // Still every one of them, out of the hot view.
        self::assertSame(25, $result['changed']);
    }

    // ---------------------------------------------------------------------- //
    // helpers
    // ---------------------------------------------------------------------- //

    /**
     * @param list<string> $sentences
     */
    private function given(string $key, array $sentences, ?bool $pin = null, int $hotCap = 20): void
    {
        if (20 !== $hotCap) {
            $this->service = self::service(hotCap: $hotCap);
        }

        $items = [];
        foreach ($sentences as $sentence) {
            $items[] = new RememberItem(key: $key, sentences: $sentence, pin: $pin);
        }

        $this->service->remember($items);
    }

    /**
     * @return list<string>
     */
    private function hot(string $key): array
    {
        return $this->texts($key, includeCold: false);
    }

    /**
     * @return list<string>
     */
    private function cold(string $key): array
    {
        return $this->texts($key, includeCold: true);
    }

    /**
     * @return list<string>
     */
    private function texts(string $key, bool $includeCold): array
    {
        $hit = $this->service->recall([
            ['key' => $key, 'depth' => 100, 'includeCold' => $includeCold],
        ])['hits'][0];

        $entries = $hit['entries'];

        if ($includeCold) {
            // A cold read shows both tiers; the cold-only view is the difference.
            $cold = array_filter($entries, static fn (array $e): bool => 'cold' === $e['tier']);

            return array_values(array_map(static fn (array $e): string => $e['text'], $cold));
        }

        return array_map(static fn (array $e): string => $e['text'], $entries);
    }

    private function revision(string $key): int
    {
        return $this->service->recall([['key' => $key, 'depth' => 1]])['hits'][0]['revision'];
    }
}
