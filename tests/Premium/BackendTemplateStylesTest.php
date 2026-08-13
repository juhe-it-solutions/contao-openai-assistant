<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium;

use PHPUnit\Framework\TestCase;

class BackendTemplateStylesTest extends TestCase
{
    public function testRecentSyncTableCellsStayTopAligned(): void
    {
        $template = file_get_contents(__DIR__.'/../../contao/templates/backend/vector_store_auto_update.html.twig');
        $this->assertIsString($template);

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $template, $rules, PREG_SET_ORDER);

        $alignmentRules = 0;

        foreach ($rules as [, $selector, $declarations]) {
            if (!str_contains($selector, 'table.vsau-log td') || !str_contains($declarations, 'vertical-align:')) {
                continue;
            }

            ++$alignmentRules;
            $this->assertMatchesRegularExpression(
                '/vertical-align:\s*top\s*;/',
                $declarations,
                'No data column may override the table-wide top alignment.',
            );
        }

        $this->assertGreaterThan(0, $alignmentRules, 'The recent-sync table needs an explicit cell alignment.');
    }
}
