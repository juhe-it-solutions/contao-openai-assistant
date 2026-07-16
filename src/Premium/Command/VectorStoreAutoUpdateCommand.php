<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Command;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreAutoUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI entry point for a single configuration sync.
 *
 * Used by the backend manual trigger (dispatched non-blocking via ProcessUtil)
 * and for operator smoke tests. Keeps the long-running crawl + LLM call out of
 * HTTP requests (constraint C4).
 */
#[AsCommand(
    name: 'contao:openai-vector-sync',
    description: 'Run vector store auto-update for one OpenAI configuration record',
)]
class VectorStoreAutoUpdateCommand extends Command
{
    public function __construct(private readonly VectorStoreAutoUpdateService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('config-id', InputArgument::REQUIRED, 'tl_openai_config.id');
        // The backend "Run sync now" button dispatches this command with
        // --source=manual; an operator running it by hand leaves the default (cli).
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Trigger source recorded in the sync log (cron|manual|cli)', VectorStoreAutoUpdateService::SOURCE_CLI);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configId = (int) $input->getArgument('config-id');

        if ($configId <= 0) {
            $output->writeln('<error>A positive config-id argument is required.</error>');

            return Command::INVALID;
        }

        $status = $this->service->run($configId, (string) $input->getOption('source'));

        if ('error' === $status) {
            $output->writeln('<error>Vector store auto-update failed for config '.$configId.'. Check the sync log for details.</error>');

            return Command::FAILURE;
        }

        if ('partial' === $status) {
            $output->writeln('<comment>Vector store auto-update finished with partial failures for config '.$configId.'.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('<info>Vector store auto-update finished with status "'.$status.'" for config '.$configId.'.</info>');

        return Command::SUCCESS;
    }
}
