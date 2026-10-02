<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RecallCommand;
use App\Domain\Dto\RememberItem;
use App\Infrastructure\Storage\MemoryRepository;
use App\Infrastructure\Storage\SqliteConnection;
use App\Service\MemoryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The console surface's defaults, which are the ones a caller cannot see.
 *
 * `memory:recall --depth` carried its own literal default (`2`) rather than
 * deferring to {@see MemoryService::DEFAULT_DEPTH}. That is invisible in a diff
 * and silently overrides the service, so `memory:recall soul` answered with two
 * of nine facts however the service's default was set. Asserting the two agree
 * is what stops them drifting apart again.
 *
 * The *shape* assertions below are necessary but not sufficient, and the reason
 * is worth recording: `--latest` was declared `VALUE_OPTIONAL` with a `null`
 * default, which looks right and is not — Symfony reports `null` both for
 * "absent" and for "present with no value", so `memory:recall --latest` was
 * indistinguishable from no recency request, and the command refused its own
 * documented invocation. Every definition-level assertion passed while the
 * command was broken. Only executing it caught that, so the execution tests are
 * the ones carrying the weight.
 *
 * @internal
 *
 * @covers \App\Command\RecallCommand
 */
final class RecallCommandTest extends TestCase
{
    private function service(): MemoryService
    {
        return new MemoryService(
            new MemoryRepository(SqliteConnection::fromPath(':memory:')),
        );
    }

    private function command(?MemoryService $service = null): RecallCommand
    {
        return new RecallCommand($service ?? $this->service());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function invoke(CommandTester $tester, array $input): int
    {
        // `CommandTester` is already bound to its command, so arguments and
        // options are passed directly — there is no `command` key to set, as
        // there would be with the application-level tester.
        return $tester->execute($input);
    }

    public function testDepthDefaultsToTheServiceDefaultRatherThanItsOwnLiteral(): void
    {
        self::assertSame(
            (string) MemoryService::DEFAULT_DEPTH,
            $this->command()->getDefinition()->getOption('depth')->getDefault(),
            'the console default must follow the service, not shadow it',
        );
    }

    /**
     * `--latest` has three states, and the default has to tell two of them apart.
     *
     * `false` is the sentinel precisely because no caller could mean it.
     */
    public function testLatestDefaultsToASentinelRatherThanNull(): void
    {
        $option = $this->command()->getDefinition()->getOption('latest');

        self::assertFalse($option->isValueRequired(), 'a value must be optional, so bare `--latest` means "the default"');
        self::assertTrue($option->acceptValue(), 'but a value must be accepted, so an explicit count is expressible');
        self::assertFalse(
            $option->getDefault(),
            'a null default makes a present-but-valueless option indistinguishable from an absent one',
        );
    }

    public function testLatestDepthDefaultsToTheServiceValue(): void
    {
        self::assertSame(
            (string) MemoryService::LATEST_DEPTH,
            $this->command()->getDefinition()->getOption('latest-depth')->getDefault(),
        );
    }

    /**
     * The invocation that has to work, and did not.
     */
    public function testBareLatestWithNoKeysRunsAndShowsTheRecentKeys(): void
    {
        $service = $this->service();
        $service->remember([new RememberItem(key: 'soul', sentences: 'Durable fact.')]);
        $service->remember([new RememberItem(key: 'chat', sentences: 'Recent chatter.')]);

        $command = $this->command($service);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $this->invoke($tester, ['--latest' => null]));

        $display = $tester->getDisplay();
        self::assertStringContainsString('No keys given', $display);
        self::assertStringContainsString('chat', $display, 'the newest key leads');
        self::assertStringContainsString('soul', $display);
    }

    public function testAnExplicitLatestCountIsHonoured(): void
    {
        $service = $this->service();
        foreach (range(1, 5) as $i) {
            $service->remember([new RememberItem(key: "key{$i}", sentences: "Fact {$i}.")]);
        }

        $command = $this->command($service);
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $this->invoke($tester, ['--latest' => '2']));

        $display = $tester->getDisplay();
        self::assertStringContainsString('key5', $display);
        self::assertStringContainsString('key4', $display);
        self::assertStringNotContainsString('key3', $display, 'the count bounds the read');
    }

    public function testNamedKeysAndLatestCompose(): void
    {
        $service = $this->service();
        $service->remember([new RememberItem(key: 'soul', sentences: 'Durable fact.')]);
        $service->remember([new RememberItem(key: 'chat', sentences: 'Recent chatter.')]);

        $command = $this->command($service);
        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $this->invoke($tester, ['key' => ['soul'], '--latest' => '5']));

        $display = $tester->getDisplay();
        self::assertStringContainsString('Also showing the', $display);
        self::assertStringContainsString('soul', $display);
        self::assertStringContainsString('chat', $display);
    }

    public function testNoKeysAndNoLatestIsRefusedRatherThanAnsweredEmptily(): void
    {
        $command = $this->command();
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $this->invoke($tester, []));
        self::assertStringContainsString('Name at least one keyword', $tester->getDisplay());
    }

    public function testANonNumericLatestCountIsRefused(): void
    {
        // The wire rejects this, so the console must not be the one transport
        // that coerces it to zero and answers with nothing.
        $command = $this->command();
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $this->invoke($tester, ['--latest' => 'abc']));
        self::assertStringContainsString('whole number of keys', $tester->getDisplay());
    }

    public function testAnEmptyStoreIsAnsweredWithANoteNotAFailure(): void
    {
        // The opposite of the missing-key case above: this is a real question
        // with a true answer, so it succeeds.
        $command = $this->command();
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $this->invoke($tester, ['--latest' => null]));
        self::assertStringContainsString('no keys', $tester->getDisplay());
    }
}
