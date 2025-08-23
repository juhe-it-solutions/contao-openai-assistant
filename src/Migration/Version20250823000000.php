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

        // Check if there are any AI chat modules with NULL disclaimer_text
        $result = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_module WHERE type = ? AND (disclaimer_text IS NULL OR disclaimer_text = "")',
            ['ai_chat']
        );
        $hasEmptyModules = (int) $result > 0;

        // Only run if there are modules that need updating
        return $hasEmptyModules;
    }

    public function run(): MigrationResult
    {
        // Default disclaimer text in German
        $defaultDisclaimerText = 'Unser Chatbot ist ein Serviceangebot unseres Unternehmens und soll die Kommunikation sowie den Informationszugang erleichtern. Die Antworten werden automatisch generiert und dienen ausschließlich allgemeinen Informations- und Unterstützungszwecken. Trotz sorgfältiger Entwicklung können Inhalte unvollständig, missverständlich oder fehlerhaft sein. Wir übernehmen daher keine Gewähr für die inhaltliche Richtigkeit oder Vollständigkeit der Antworten. Verbindliche Auskünfte, individuelle Beratung oder rechtliche Empfehlungen werden durch den Chatbot nicht erteilt. Bitte nutze die bereitgestellten Informationen als Orientierung und wende dich für wichtige Anliegen direkt an unser Team oder an eine entsprechend qualifizierte Fachperson.';

        $actions = [];

        try {
            // Step 1: Ensure the column is TEXT type (this is safe to run multiple times)
            $this->connection->executeStatement('
                ALTER TABLE tl_module 
                MODIFY COLUMN disclaimer_text TEXT NULL
            ');
            $actions[] = 'Ensured disclaimer_text column is TEXT type';

            // Step 2: Update existing modules with empty disclaimer_text
            // Note: We do NOT set database default values for TEXT columns in MySQL
            $updatedRows = $this->connection->executeStatement(
                'UPDATE tl_module SET disclaimer_text = ? WHERE type = ? AND (disclaimer_text IS NULL OR disclaimer_text = "")',
                [$defaultDisclaimerText, 'ai_chat']
            );

            if ($updatedRows > 0) {
                $actions[] = sprintf('Updated %d AI chat modules with default disclaimer text', $updatedRows);
            }

        } catch (\Exception $e) {
            return $this->createResult(false, 'Migration failed: ' . $e->getMessage());
        }

        $message = implode('; ', $actions);

        return $this->createResult(true, $message);
    }
}
