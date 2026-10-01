<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\RecallCommand;
use App\Infrastructure\Storage\MemoryRepository;
use App\Infrastructure\Storage\SqliteConnection;
use App\Service\MemoryService;
use PHPUnit\Framework\TestCase;

/**
 * The console surface's defaults, which are the ones a caller cannot see.
 *
 * `memory:recall --depth` carried its own literal default (`2`) rather than
 * deferring to {@see MemoryService::DEFAULT_DEPTH}. That is invisible in a diff
 * and silently overrides the service, so `memory:recall soul` answered with two
 * of nine facts however the service's default was set. Asserting the two agree
 * is what stops them drifting apart again.
 *
 * @internal
 *
 * @covers \App\Command\RecallCommand
 */
final class RecallCommandTest extends TestCase
{
    private function command(): RecallCommand
    {
        return new RecallCommand(new MemoryService(
            new MemoryRepository(SqliteConnection::fromPath(':memory:')),
        ));
    }

    public function testDepthDefaultsToTheServiceDefaultRatherThanItsOwnLiteral(): void
    {
        self::assertSame(
            (string) MemoryService::DEFAULT_DEPTH,
            $this->command()->getDefinition()->getOption('depth')->getDefault(),
            'the console default must follow the service, not shadow it',
        );
    }
}
