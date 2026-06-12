<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Command;

use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI entry point for a single configuration sync.
 *
 * Used by the backend manual trigger (dispatched non-blocking via ProcessUtil) and
 * for operator smoke tests. Keeps the long-running crawl + LLM call out of HTTP
 * requests (constraint C4).
 */
#[AsCommand(
    name: 'contao:openai-vector-sync',
    description: 'Run vector store auto-update for one OpenAI configuration record',
)]
class VectorStoreAutoUpdateCommand extends Command
{
    public function __construct(
        private readonly VectorStoreAutoUpdateService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('config-id', InputArgument::REQUIRED, 'tl_openai_config.id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configId = (int) $input->getArgument('config-id');

        if ($configId <= 0) {
            $output->writeln('<error>A positive config-id argument is required.</error>');

            return Command::INVALID;
        }

        $this->service->run($configId);
        $output->writeln('<info>Vector store auto-update finished for config ' . $configId . '.</info>');

        return Command::SUCCESS;
    }
}
