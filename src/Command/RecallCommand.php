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
 *
 * With no keys and `--latest`, the command answers the other question the store
 * can be asked — *what was written most recently?* — which is what a caller that
 * has just opened a session actually wants, and which no amount of key naming
 * can answer, because it does not yet know any names.
 */
#[AsCommand(
    name: 'memory:recall',
    description: 'Look up keywords, or the most recently written keys',
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
            // Optional, not required: `--latest` on its own is the whole point
            // of the recency mode, and requiring a key would put back the one
            // thing it exists to remove.
            ->addArgument('key', InputArgument::OPTIONAL | InputArgument::IS_ARRAY, 'Keyword(s) to look up')
            // The default is the service's own, not a literal. It was a literal
            // (`2`) and drifted the moment the service's default changed, which
            // is how `memory:recall soul` kept answering with two of nine facts.
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Unpinned sentences per keyword (pinned are always returned)', (string) MemoryService::DEFAULT_DEPTH)
            ->addOption('cold', null, InputOption::VALUE_NONE, 'Include the cold tier')
            // `VALUE_OPTIONAL` with a `false` sentinel, because this option has
            // *three* states and the default has to tell two of them apart:
            //
            //   absent (false)       — no recency read at all
            //   `--latest` (null)    — the service's default count
            //   `--latest=20` ('20') — an explicit count
            //
            // A `null` default collapses the first two: Symfony reports null for
            // "provided with no value" as well, so `memory:recall --latest` read
            // as though nothing had been passed, and the command refused its own
            // documented invocation. `false` is not a value a caller could have
            // meant, which is what makes it a safe thing to mean "absent".
            ->addOption('latest', null, InputOption::VALUE_OPTIONAL, 'Also show the most recently written keys; optionally how many (default: the service\'s '.MemoryService::LATEST_COUNT.')', false)
            // Parity with the wire, where a recency entry carries its own
            // `depth`. Without this the console could not express a request the
            // service supports, which is the one kind of gap this project does
            // not accept between its transports.
            ->addOption('latest-depth', null, InputOption::VALUE_REQUIRED, 'Sentences per key on the recency read', (string) MemoryService::LATEST_DEPTH)
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
        /** @var string|false|null $latest */
        $latest = $input->getOption('latest');
        /** @var string $latestDepth */
        $latestDepth = $input->getOption('latest-depth');

        if (false === $latest && [] === $keys) {
            // Refused rather than answered with nothing, matching the wire
            // (`Count(min: 1)`) and the service. An empty recall is a caller
            // bug, and a cheerful empty result is the silent success the whole
            // service is built to avoid.
            $output->writeln('<error>Name at least one keyword, or pass --latest.</error>');

            return Command::FAILURE;
        }

        if (\is_string($latest) && !ctype_digit($latest)) {
            // The wire rejects a non-numeric count; the console should not be
            // the one transport that quietly coerces it.
            $output->writeln(\sprintf('<error>--latest takes a whole number of keys; got "%s".</error>', $latest));

            return Command::FAILURE;
        }

        $queries = array_map(
            static fn (string $key): array => [
                'key' => $key,
                'depth' => (int) $depth,
                'includeCold' => $includeCold,
            ],
            $keys,
        );

        if (false !== $latest) {
            $queries[] = [
                // `true` for a bare `--latest`, so the console exercises the
                // same defaulting path the tool will rather than resolving the
                // count itself and hiding whether the service agreed.
                'latest' => null === $latest ? true : (int) $latest,
                // The shared `depth` field, not a second dial: two questions,
                // two depths. A `--depth` chosen for a named lookup must not
                // silently become the depth of "and what else was recent".
                'depth' => (int) $latestDepth,
                'includeCold' => false,
            ];
        }

        $result = $this->memory->recall($queries);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '{}');

            return Command::SUCCESS;
        }

        if (isset($result['latest']['note'])) {
            $output->writeln(\sprintf('<comment>%s</comment>', $result['latest']['note']));
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
