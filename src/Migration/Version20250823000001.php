<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Migration;

use Contao\CoreBundle\Migration\AbstractMigration;
use Contao\CoreBundle\Migration\MigrationResult;
use Doctrine\DBAL\Connection;

class Version20250823000001 extends AbstractMigration
{
    public function __construct(private Connection $connection)
    {
    }

    public function shouldRun(): bool
    {
        // This migration is no longer needed since we handle defaults at application
        // level and the first migration already handles the column type and data updates
        return false;
    }

    public function run(): MigrationResult
    {
        // This migration is no longer needed
        return $this->createResult(true, 'Migration skipped - handled by Version20250823000000');
    }
}
