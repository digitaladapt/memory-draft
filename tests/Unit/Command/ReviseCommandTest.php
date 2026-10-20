<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ReviseCommand;
use App\Infrastructure\Storage\MemoryRepository;
use App\Infrastructure\Storage\SqliteConnection;
use App\Service\MemoryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The console's rendering of a revise, which is where a correct report can still
 * be read as its opposite.
 *
 * The bug these exist for: a dry run deliberately reports `changed: 0` — nothing
 * was written — and the renderer printed that figure beside the words "would
 * change". The output read
 *
 *     deploy: hot — 1 matched, 0 would change
 *       would_purge Rollback is one command.
 *
 * which says "nothing would happen" directly above the thing that would. The
 * report was right and the sentence describing it was wrong, so no assertion on
 * the service could have caught it: only executing the command and reading what
 * it printed does.
 *
 * The second claim here is the refusal path. An ambiguous `purge` is a usage
 * error that changes nothing, so it must exit non-zero and say what to do next
 * *without* the confirmation prompt having offered to go ahead anyway — a prompt
 * for a call that will be refused trains the operator to answer `y` blind.
 *
 * @internal
 *
 * @covers \App\Command\ReviseCommand
 */
final class ReviseCommandTest extends TestCase
{
    private MemoryService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->service = new MemoryService(
            new MemoryRepository(SqliteConnection::fromPath(':memory:')),
        );
    }

    private function command(): ReviseCommand
    {
        return new ReviseCommand($this->service);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function invoke(array $input): CommandTester
    {
        $tester = new CommandTester($this->command());
        // Non-interactive throughout: the prompt is tested separately, and a
        // tester left interactive blocks on a question it was never asked.
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    /**
     * @param list<string> $sentences
     */
    private function given(string $key, array $sentences): void
    {
        $this->service->remember([
            new \App\Domain\Dto\RememberItem(key: $key, sentences: $sentences),
        ]);
    }

    public function testADryRunSaysHowManyItWouldChangeRatherThanReportingZero(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $tester = $this->invoke([
            'key' => 'deploy',
            'text' => 'Rollback is one command.',
            '--disposition' => 'purge',
            '--dry-run' => true,
        ]);

        self::assertStringContainsString('1 would change', $tester->getDisplay());
        self::assertStringNotContainsString('0 would change', $tester->getDisplay());
        self::assertStringContainsString('would_purge Rollback is one command.', $tester->getDisplay());
    }

    public function testADryRunChangesNothing(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $this->invoke([
            'key' => 'deploy',
            'text' => 'Rollback is one command.',
            '--disposition' => 'purge',
            '--dry-run' => true,
        ]);

        self::assertSame(2, $this->service->recall([['key' => 'deploy']])['hits'][0]['hot']);
    }

    public function testAnAmbiguousPurgeIsRefusedAndSaysWhatToDoInstead(): void
    {
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.', 'Cluster is eu-west.']);

        $tester = $this->invoke([
            'key' => 'deploy',
            'text' => '*e*',
            '--match' => 'glob',
            '--disposition' => 'purge',
            '--force' => true,
        ]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('refuses to destroy more than one at a time', $tester->getDisplay());
        // Narrowing the pattern is the only way forward, so the refusal has to
        // name both moves that reach it.
        self::assertStringContainsString('Narrow the pattern', $tester->getDisplay());
        self::assertStringContainsString('dryRun', $tester->getDisplay());
    }

    public function testARefusedPurgeDestroysNothing(): void
    {
        // The whole value of refusing is that the store is untouched. Asserting
        // only the exit code would pass for a command that destroyed two
        // sentences and then complained about the third.
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.', 'Cluster is eu-west.']);

        $this->invoke([
            'key' => 'deploy',
            'text' => '*e*',
            '--match' => 'glob',
            '--disposition' => 'purge',
            '--force' => true,
        ]);

        self::assertSame(3, $this->service->recall([['key' => 'deploy']])['hits'][0]['hot']);
    }

    public function testADemoteOfSeveralSentencesIsAllowedBecauseItLosesNothing(): void
    {
        // The refusal is about destruction, not about breadth: "push everything
        // mentioning blue-green out of view" is a real request, and re-stating a
        // sentence promotes it back, so there is nothing to be ambiguous about.
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $tester = $this->invoke([
            'key' => 'deploy',
            'text' => '*e*',
            '--match' => 'glob',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('2 changed', $tester->getDisplay());
    }

    public function testAMissSaysNothingMatchedAndPointsAtTheOtherTier(): void
    {
        $this->given('deploy', ['Deploys use blue-green.']);
        $this->invoke(['key' => 'deploy', 'text' => 'Deploys use blue-green.']);

        $tester = $this->invoke(['key' => 'deploy', 'text' => 'Deploys use blue-green.']);

        self::assertStringContainsString('Nothing matched. Nothing was changed.', $tester->getDisplay());
        self::assertStringContainsString('tier: any', $tester->getDisplay());
    }

    public function testAnUnknownKeyIsAWarningWithSuggestionsAndSucceeds(): void
    {
        // A miss on a write is almost always a typo, and a typo is not a crash.
        // Succeeding also keeps it out of scripts' failure paths.
        //
        // The miss is a prefix rather than a misspelling on purpose:
        // `suggest()` scores substring overlap and shared tokens, so `depl` finds
        // `deploy` while `deploi` — one character out — finds nothing. That gap
        // is pre-existing and lives in `suggest()`; asserting a suggestion for a
        // typo it never claimed to handle would be testing the wrong thing.
        $this->given('deploy', ['Deploys use blue-green.']);

        $tester = $this->invoke(['key' => 'depl', 'text' => 'Deploys use blue-green.']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No key matched "depl".', $tester->getDisplay());
        self::assertStringContainsString('Did you mean: deploy', $tester->getDisplay());
    }

    public function testAJsonDryRunReportsChangedZeroAndLeavesTheStoreAlone(): void
    {
        // `--json` is the machine-readable form and must not do the console's
        // editorialising: the field says what happened (nothing), while the
        // entries say what would. Rewriting `changed` to satisfy the human
        // reading would make the JSON lie to a script.
        $this->given('deploy', ['Deploys use blue-green.', 'Rollback is one command.']);

        $tester = $this->invoke([
            'key' => 'deploy',
            'text' => 'Rollback is one command.',
            '--disposition' => 'purge',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(0, $payload['changed']);
        self::assertTrue($payload['results'][0]['dry_run']);
        self::assertSame(2, $this->service->recall([['key' => 'deploy']])['hits'][0]['hot']);
    }
}
