<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

/**
 * v2.0 upgrade — Step 1 of 2.
 *
 * Renames tl_openai_assistants → tl_openai_prompts and introduces the
 * prompt_id / prompt_version columns used to reference dashboard-managed
 * OpenAI Prompts from a local prompt record.
 *
 * Idempotent: safe to run multiple times and on fresh installs.
 */
class Version20260416000000RenamePromptsTable extends AbstractMigration
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function getName(): string
    {
        return 'v2.0 Step 1: Rename tl_openai_assistants to tl_openai_prompts and add prompt_id/prompt_version';
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->getSchemaManager();

        $hasLegacy = $schemaManager->tablesExist(['tl_openai_assistants']);
        $hasNew    = $schemaManager->tablesExist(['tl_openai_prompts']);

        if ($hasLegacy && ! $hasNew) {
            return true;
        }

        if (! $hasNew) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_openai_prompts');

        return ! isset($columns['prompt_id']) || ! isset($columns['prompt_version']);
    }

    public function run(): MigrationResult
    {
        $schemaManager = $this->getSchemaManager();
        $platform      = $this->connection->getDatabasePlatform();
        $isMysql       = $platform instanceof MySQLPlatform;
        $messages      = [];

        $hasLegacy = $schemaManager->tablesExist(['tl_openai_assistants']);
        $hasNew    = $schemaManager->tablesExist(['tl_openai_prompts']);

        if ($hasLegacy && ! $hasNew) {
            if ($isMysql) {
                $this->connection->executeStatement('RENAME TABLE tl_openai_assistants TO tl_openai_prompts');
            } else {
                $this->connection->executeStatement('ALTER TABLE tl_openai_assistants RENAME TO tl_openai_prompts');
            }
            $messages[] = 'Renamed tl_openai_assistants to tl_openai_prompts';
        } elseif ($hasLegacy && $hasNew) {
            $messages[] = 'WARNING: both tl_openai_assistants and tl_openai_prompts exist; left tl_openai_assistants intact for manual review';
        }

        if (! $schemaManager->tablesExist(['tl_openai_prompts'])) {
            return $this->createResult(true, 'No tl_openai_prompts table yet; skipping column additions (install will create it)');
        }

        $columns = $schemaManager->listTableColumns('tl_openai_prompts');

        if (! isset($columns['prompt_id'])) {
            $this->connection->executeStatement(
                "ALTER TABLE tl_openai_prompts ADD prompt_id VARCHAR(128) NOT NULL DEFAULT ''"
            );
            $messages[] = 'Added prompt_id column';
        }

        if (! isset($columns['prompt_version'])) {
            $this->connection->executeStatement(
                "ALTER TABLE tl_openai_prompts ADD prompt_version VARCHAR(32) NOT NULL DEFAULT ''"
            );
            $messages[] = 'Added prompt_version column';
        }

        if (empty($messages)) {
            $messages[] = 'Schema already up to date';
        }

        return $this->createResult(true, implode('; ', $messages));
    }

    private function getSchemaManager(): AbstractSchemaManager
    {
        return $this->connection->createSchemaManager();
    }
}
