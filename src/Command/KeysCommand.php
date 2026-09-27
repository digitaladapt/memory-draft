<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MemoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `memory:keys` — enumerate the keyspace.
 *
 * The highest-value read in the service. A keyword store punishes guessing key
 * names, so being able to ask "what do you actually know about?" — rather than
 * hoping to guess the right key — is what makes the whole thing usable.
 */
#[AsCommand(
    name: 'memory:keys',
    description: 'List the keywords currently stored',
)]
final class KeysCommand extends Command
{
    public function __construct(
        private readonly MemoryService $memory,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('pattern', InputArgument::OPTIONAL, 'Substring filter', '')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum keys to list', '200')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $pattern */
        $pattern = $input->getArgument('pattern');
        /** @var string $limit */
        $limit = $input->getOption('limit');

        $keys = $this->memory->keys($pattern, (int) $limit);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($keys, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '[]');

            return Command::SUCCESS;
        }

        if ([] === $keys) {
            $output->writeln('<comment>(no keys stored yet)</comment>');

            return Command::SUCCESS;
        }

        foreach ($keys as $key) {
            $detail = \sprintf('%d hot', $key['hot']);
            if ($key['cold'] > 0) {
                $detail .= \sprintf(', %d cold', $key['cold']);
            }
            if ($key['backfilled'] > 0) {
                $detail .= \sprintf(', %d backfilled', $key['backfilled']);
            }

            $line = \sprintf(
                '<info>%s</info>  (%s, revision %d, %s)',
                $key['key'],
                $detail,
                $key['revision'],
                $key['last_written'],
            );

            if ([] !== $key['aliases']) {
                $line .= \sprintf('  <comment>also: %s</comment>', implode(', ', $key['aliases']));
            }

            $output->writeln($line);
        }

        return Command::SUCCESS;
    }
}
