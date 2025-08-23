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

class Version20250823000000 extends AbstractMigration
{
    public function __construct(
        private Connection $connection
    ) {
    }

    public function shouldRun(): bool
    {
        $schemaManager = $this->connection->createSchemaManager();

        if (! $schemaManager->tablesExist(['tl_module'])) {
            return false;
        }

        $columns = $schemaManager->listTableColumns('tl_module');

        if (! array_key_exists('disclaimer_text', $columns)) {
            return false;
        }

        // Check if we need to run this migration
        $column     = $columns['disclaimer_text'];
        $hasDefault = $column->getDefault() !== null;

        // Check if there are any AI chat modules with NULL disclaimer_text
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_module WHERE type = ? AND (disclaimer_text IS NULL OR disclaimer_text = "")',
            ['ai_chat']
        );
        $hasEmptyModules = (int) $result > 0;

        // Run migration if either condition is true
        return ! $hasDefault || $hasEmptyModules;
    }

    public function run(): MigrationResult
    {
        // Default disclaimer text in German
        $defaultDisclaimerText = 'Unser Chatbot ist ein Serviceangebot unseres Unternehmens und soll die Kommunikation sowie den Informationszugang erleichtern. Die Antworten werden automatisch generiert und dienen ausschließlich allgemeinen Informations- und Unterstützungszwecken. Trotz sorgfältiger Entwicklung können Inhalte unvollständig, missverständlich oder fehlerhaft sein. Wir übernehmen daher keine Gewähr für die inhaltliche Richtigkeit oder Vollständigkeit der Antworten. Verbindliche Auskünfte, individuelle Beratung oder rechtliche Empfehlungen werden durch den Chatbot nicht erteilt. Bitte nutze die bereitgestellten Informationen als Orientierung und wende dich für wichtige Anliegen direkt an unser Team oder an eine entsprechend qualifizierte Fachperson.';

        $actions = [];

        // Step 1: Add default value to the column if it doesn't exist
        $schemaManager = $this->connection->createSchemaManager();
        $columns       = $schemaManager->listTableColumns('tl_module');
        $column        = $columns['disclaimer_text'];

        if ($column->getDefault() === null) {
            $this->connection->executeStatement('
                ALTER TABLE tl_module 
                ALTER COLUMN disclaimer_text SET DEFAULT ?
            ', [$defaultDisclaimerText]);
            $actions[] = 'Added default value to disclaimer_text column';
        }

        // Step 2: Update existing modules with empty disclaimer_text
        $updatedRows = $this->connection->executeStatement(
            'UPDATE tl_module SET disclaimer_text = ? WHERE type = ? AND (disclaimer_text IS NULL OR disclaimer_text = "")',
            [$defaultDisclaimerText, 'ai_chat']
        );

        if ($updatedRows > 0) {
            $actions[] = sprintf('Updated %d AI chat modules with default disclaimer text', $updatedRows);
        }

        $message = empty($actions) ? 'No changes needed' : implode('; ', $actions);

        return $this->createResult(true, $message);
    }
}
