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
 * `memory:recall` — "what do I know about X?".
 *
 * The primary read. Every hit carries its revision, because that is the token
 * the caller echoes back on write; a caller that never sees it can never supply
 * it, and the concurrency check becomes decorative.
 */
#[AsCommand(
    name: 'memory:recall',
    description: 'Look up one or more keywords',
)]
final class RecallCommand extends Command
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
            ->addArgument('key', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Keyword(s) to look up')
            // The default is the service's own, not a literal. It was a literal
            // (`2`) and drifted the moment the service's default changed, which
            // is how `memory:recall soul` kept answering with two of nine facts.
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Unpinned sentences per keyword (pinned are always returned)', (string) MemoryService::DEFAULT_DEPTH)
            ->addOption('cold', null, InputOption::VALUE_NONE, 'Include the cold tier')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $keys */
        $keys = $input->getArgument('key');
        /** @var string $depth */
        $depth = $input->getOption('depth');
        $includeCold = (bool) $input->getOption('cold');

        $result = $this->memory->recall(array_map(
            static fn (string $key): array => [
                'key' => $key,
                'depth' => (int) $depth,
                'includeCold' => $includeCold,
            ],
            $keys,
        ));

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '{}');

            return Command::SUCCESS;
        }

        foreach ($result['hits'] as $hit) {
            $meta = \sprintf('%d hot', $hit['hot']);
            if ($hit['cold'] > 0) {
                $meta .= \sprintf(', %d cold', $hit['cold']);
            }
            if ($hit['backfilled'] > 0) {
                $meta .= \sprintf(', %d backfilled', $hit['backfilled']);
            }

            $output->writeln(\sprintf(
                '<info>%s</info>  (%s, revision %d)%s',
                $hit['key'],
                $meta,
                $hit['revision'],
                isset($hit['resolved_from']) ? \sprintf('  <comment>[resolved from "%s"]</comment>', $hit['resolved_from']) : '',
            ));

            foreach ($hit['entries'] as $entry) {
                $flags = ($entry['pinned'] ? '*' : '').('cold' === $entry['tier'] ? ' [cold]' : '').($entry['backfilled'] ? ' [backfilled]' : '');
                $output->writeln(\sprintf('  - [%s]%s %s', $entry['age'], $flags, $entry['text']));
            }

            if ($hit['shown'] < $hit['hot'] + ($includeCold ? $hit['cold'] : 0)) {
                $output->writeln(\sprintf('    <comment>… %d more; raise --depth to see them</comment>', $hit['hot'] + ($includeCold ? $hit['cold'] : 0) - $hit['shown']));
            }
        }

        foreach ($result['misses'] as $miss) {
            $line = \sprintf('<comment>%s  (no entry)</comment>', $miss['key']);
            if ([] !== $miss['suggestions']) {
                $line .= \sprintf(' — did you mean: %s?', implode(', ', $miss['suggestions']));
            }
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }
}
