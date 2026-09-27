<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MemoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `memory:stats` — store-wide counts and the active trimming caps.
 */
#[AsCommand(
    name: 'memory:stats',
    description: 'Show store statistics and trimming budgets',
)]
final class StatsCommand extends Command
{
    public function __construct(
        private readonly MemoryService $memory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('json', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Emit raw JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->memory->stats();

        if ($input->getOption('json')) {
            $output->writeln(json_encode($stats, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '{}');

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf('<info>%d</info> sentences across <info>%d</info> keys', $stats['total'], $stats['keys']));
        $output->writeln(\sprintf('  hot:        %d', $stats['hot']));
        $output->writeln(\sprintf('  cold:       %d   (retrieved with --cold)', $stats['cold']));
        $output->writeln(\sprintf('  pinned:     %d   (exempt from trimming)', $stats['pinned']));
        $output->writeln(\sprintf('  backfilled: %d   (written against an older revision)', $stats['backfilled']));
        $output->writeln('');
        $output->writeln(\sprintf('caps: %d hot / %d cold per key', $stats['caps']['hot_per_key'], $stats['caps']['cold_per_key']));

        return Command::SUCCESS;
    }
}
