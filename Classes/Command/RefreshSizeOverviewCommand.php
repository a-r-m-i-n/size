<?php

declare(strict_types = 1);

namespace T3\Size\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use T3\Size\Service\SizeOverviewRefreshService;

#[AsCommand(
    name: 'size:refresh',
    description: 'Refreshes the persisted storage statistics snapshot.'
)]
final class RefreshSizeOverviewCommand extends Command
{
    public function __construct(
        private readonly SizeOverviewRefreshService $refreshService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->refreshService->refresh();

        if ($result->wasLocked()) {
            $output->writeln('<error>Skipped refresh: another size overview refresh is already running.</error>');

            return self::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Refreshed storage statistics snapshot in %d ms.</info>',
            $result->durationMs ?? 0
        ));

        return self::SUCCESS;
    }
}
