<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Dto\RememberItem;
use App\Service\MemoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `memory:remember` — store sentences under a keyword.
 *
 * Sentence-level input is offered alongside a prose blob because splitting prose
 * is a heuristic and heuristics on prose are lossy. `--sentence` may be repeated
 * to bypass the splitter entirely.
 */
#[AsCommand(
    name: 'memory:remember',
    description: 'Store one or more sentences under a keyword',
)]
final class RememberCommand extends Command
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
            ->addArgument('key', InputArgument::REQUIRED, 'Keyword to write under (variants resolve to one key)')
            ->addArgument('text', InputArgument::OPTIONAL, 'Prose to split into sentences')
            ->addOption('sentence', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An exact sentence (repeatable); skips the splitter')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'append (default) or replace', 'append')
            ->addOption('pin', null, InputOption::VALUE_NONE, 'Exempt from trimming (durable facts)')
            ->addOption('revision', 'r', InputOption::VALUE_REQUIRED, 'Revision you read; a lower one is kept as backfill')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $key */
        $key = $input->getArgument('key');
        /** @var string|null $text */
        $text = $input->getArgument('text');
        /** @var list<string> $sentences */
        $sentences = $input->getOption('sentence');
        /** @var string $mode */
        $mode = $input->getOption('mode');
        $pin = (bool) $input->getOption('pin');
        /** @var string|null $revision */
        $revision = $input->getOption('revision');

        if (null === $text && [] === $sentences) {
            $output->writeln('<error>Provide prose as an argument, or one or more --sentence options.</error>');

            return Command::INVALID;
        }

        if (!\in_array($mode, ['append', 'replace'], true)) {
            $output->writeln('<error>--mode must be "append" or "replace".</error>');

            return Command::INVALID;
        }

        $item = new RememberItem(
            key: $key,
            sentences: [] !== $sentences ? $sentences : $text,
            mode: $mode,
            pin: $pin,
            revision: null === $revision ? null : (int) $revision,
        );

        $report = $this->memory->remember([$item]);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '{}');

            return Command::SUCCESS;
        }

        foreach ($report['results'] as $result) {
            $bits = [];
            if ($result['added'] > 0) {
                $bits[] = '+'.$result['added'];
            }
            if ($result['renewed'] > 0) {
                $bits[] = $result['renewed'].' renewed';
            }
            if ($result['promoted'] > 0) {
                $bits[] = $result['promoted'].' promoted';
            }
            $bits[] = $result['empty'] ? 'nothing to store' : '';

            $output->writeln(\sprintf(
                '<info>%s</info>: %s (%s, revision %d, intent %s)',
                $result['key'],
                trim(implode(', ', array_filter($bits)), ', ') ?: 'no change',
                $result['mode'],
                $result['revision'],
                $result['intent'],
            ));

            // Demotions are surfaced on the write, so the caller sees what it
            // just stopped guaranteeing rather than discovering it later.
            foreach ($result['retired'] as $row) {
                $output->writeln(\sprintf('    <comment>retired</comment> [%s] %s', $row['age'], $row['text']));
            }
            foreach ($result['evicted'] as $row) {
                $output->writeln(\sprintf('    <comment>-> cold</comment> [%s] %s', $row['age'], $row['text']));
            }
            foreach ($result['purged'] as $row) {
                $output->writeln(\sprintf('    <error>x purged</error> %s', $row['text']));
            }
        }

        return Command::SUCCESS;
    }
}
