<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Dto\ReviseItem;
use App\Service\MemoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `memory:revise` — retire or destroy individual sentences, by their text.
 *
 * Its own command, for the reason `memory:forget` is: an operation that can
 * destroy data should not be reachable by mistyping a write. It is also not a
 * flag on `memory:forget`, because the two differ in the thing that matters —
 * `forget` takes a whole key, `revise` takes sentences out of one.
 *
 * The confirmation prompt exists for `purge` only. A `demote` loses nothing and
 * asking about it would train the operator to hit `y` without reading, which is
 * exactly the habit that would defeat the prompt on the one call that needs it.
 */
#[AsCommand(
    name: 'memory:revise',
    description: 'Retire (demote) or destroy (purge) individual sentences, addressed by their text',
)]
final class ReviseCommand extends Command
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
            ->addArgument('key', InputArgument::REQUIRED, 'The keyword the sentences belong to')
            ->addArgument('text', InputArgument::REQUIRED, 'The sentence text to match')
            ->addOption('disposition', 'd', InputOption::VALUE_REQUIRED, 'demote (default) or purge', 'demote')
            ->addOption('match', 'm', InputOption::VALUE_REQUIRED, 'exact (default), contains or glob', 'exact')
            ->addOption('tier', 't', InputOption::VALUE_REQUIRED, 'hot (default), cold or any', 'hot')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without changing it')
            ->addOption('include-pinned', null, InputOption::VALUE_NONE, 'Act on pinned sentences too, which are otherwise skipped')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON')
            ->setHelp(<<<'HELP'
                Sentences are addressed by their text, the way <comment>memory:recall</comment>
                shows them. The default match is <comment>exact</comment>, so quoting a sentence
                selects that sentence or nothing — a miss is recoverable, a wrong match is not.
                Widen it deliberately:

                  <info>memory:revise deploy 'Deploys use blue-green.'</info>
                      demote that one sentence (reversible: re-state it to promote it back)
                  <info>memory:revise deploy 'blue-green' -m contains</info>
                      demote everything mentioning blue-green
                  <info>memory:revise deploy 'Deploys*' -m glob --disposition purge --dry-run</info>
                      show what a purge would hit, before committing to it
                  <info>memory:revise deploy 'Rollback is one command.' -d purge</info>
                      destroy that one sentence; the key and its other sentences survive

                Pinned sentences are skipped unless <comment>--include-pinned</comment> is given,
                because a pin means "do not trim this" and both dispositions are trims.

                A <comment>purge</comment> that would match more than one sentence is refused rather
                than resolved. Use <comment>--dry-run</comment> to see them, then narrow the pattern.
                HELP)
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $key */
        $key = $input->getArgument('key');
        /** @var string $text */
        $text = $input->getArgument('text');
        /** @var string $disposition */
        $disposition = $input->getOption('disposition');
        /** @var string $match */
        $match = $input->getOption('match');
        /** @var string $tier */
        $tier = $input->getOption('tier');

        $item = new ReviseItem(
            key: $key,
            text: $text,
            disposition: $disposition,
            match: $match,
            tier: $tier,
            dryRun: (bool) $input->getOption('dry-run'),
            includePinned: (bool) $input->getOption('include-pinned'),
        );

        // Ask before doing, and only for the irreversible one — see the class
        // note. `--json` and non-interactive input skip it by definition.
        if ('purge' === $disposition
            && !$item->dryRun
            && !$input->getOption('force')
            && !$input->getOption('json')
            && $input->isInteractive()
            && !$this->confirm($input, $output, $item)
        ) {
            $output->writeln('Cancelled.');

            return Command::SUCCESS;
        }

        try {
            $result = $this->memory->revise([$item]);
        } catch (\DomainException $exception) {
            // The ambiguous-purge refusal is a usage error, not a crash: it says
            // what to do next, and the exit code says it was not performed.
            $output->writeln(\sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::INVALID;
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '{}');

            return Command::SUCCESS;
        }

        $this->render($output, $result['results'][0], $result['changed']);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function render(OutputInterface $output, array $report, int $changed): void
    {
        $style = new SymfonyStyle(new \Symfony\Component\Console\Input\ArrayInput([]), $output);

        if (isset($report['missing']) && true === $report['missing']) {
            $style->warning(\sprintf('No key matched "%s".', $report['key']));
            $suggestions = $report['suggestions'] ?? [];
            if ([] !== $suggestions) {
                $style->writeln('Did you mean: '.implode(', ', $suggestions));
            }

            return;
        }

        $entries = $report['entries'] ?? [];

        // A dry run reports `changed: 0` deliberately — nothing was written — so
        // printing that figure beside the words "would change" would say
        // "nothing would happen" while listing what would. The actionable count
        // is the selection itself, which is exactly `entries` plus the `more` it
        // withheld; `matched` would overstate it, because a skipped pinned
        // sentence is matched but not acted on.
        $actionable = \count($entries) + (\is_int($report['more'] ?? null) ? $report['more'] : 0);

        $style->writeln(\sprintf(
            '<info>%s</info>: %s — %d matched, %d %s',
            $report['key'],
            $report['tier'],
            $report['matched'],
            $report['dry_run'] ? $actionable : $changed,
            $report['dry_run'] ? 'would change' : 'changed',
        ));

        foreach ($entries as $entry) {
            $style->writeln(\sprintf('  <comment>%s</comment> %s <fg=gray>(%s)</>', $entry['action'], $entry['text'], $entry['age']));
        }

        if (isset($report['more'])) {
            $style->writeln(\sprintf('  … and %d more', $report['more']));
        }

        if (isset($report['hint'])) {
            $style->note($report['hint']);
        }

        if (isset($report['hint_pinned'])) {
            $style->note($report['hint_pinned']);
        }

        // Keyed on `matched`, not on `entries`: a selection that was entirely
        // pinned has nothing listed and nothing changed, but it did match, and
        // the note above already explained why it was skipped.
        if (0 === $report['matched']) {
            $style->writeln('Nothing matched. Nothing was changed.');
        }
    }

    private function confirm(InputInterface $input, OutputInterface $output, ReviseItem $item): bool
    {
        $style = new SymfonyStyle($input, $output);

        return $style->confirm(
            \sprintf('Permanently delete sentences on "%s" matching %s "%s"? This cannot be undone.', $item->key, $item->match, $item->text),
            false,
        );
    }
}
