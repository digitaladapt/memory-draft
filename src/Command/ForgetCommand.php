<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MemoryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `memory:forget` — permanently delete a keyword.
 *
 * Deliberately its own command rather than a flag on a write, so nothing routine
 * can reach the only destructive operation by accident.
 */
#[AsCommand(
    name: 'memory:forget',
    description: 'Permanently delete a keyword and everything under it',
)]
final class ForgetCommand extends Command
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
            ->addArgument('key')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit raw JSON');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $key */
        $key = $input->getArgument('key');

        if (!$input->getOption('force') && !$input->getOption('json') && $input->isInteractive()) {
            $confirmed = $this->confirm($input, $output, $key);
            if (!$confirmed) {
                $output->writeln('Cancelled.');

                return Command::SUCCESS;
            }
        }

        $result = $this->memory->forget($key);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) ?: '{}');
        } else {
            $output->writeln(\sprintf('<info>%s</info>: deleted %d sentence(s)', $result['key'], $result['deleted']));
        }

        return Command::SUCCESS;
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $key): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            \sprintf('Delete everything under "%s"? This cannot be undone. [y/N] ', $key),
            false,
        );

        return (bool) $helper->ask($input, $output, $question);
    }
}
