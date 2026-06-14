<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Service\BoilerplateFilter;
use PHPUnit\Framework\TestCase;

class BoilerplateFilterTest extends TestCase
{
    public function testRemovesRepeatedChromeButKeepsUniqueContent(): void
    {
        $nav = "Home\nUnternehmen\nKontakt\nImpressum";
        $footer = 'Copyright 2026 Nordlicht AG. Alle Rechte vorbehalten.';

        $bodies = [
            'Die Urlaubsregelung sieht 30 Tage vor.',
            'Das Onboarding beginnt um 9 Uhr am Welcome Desk.',
            'Die Helix Cloud Plattform bietet Prozessautomatisierung.',
            'Reisekosten werden ueber das Portal abgerechnet.',
            'Der Code Review erfordert zwei Freigaben.',
            'Die DSGVO Schulung ist jaehrlich verpflichtend.',
        ];

        $pages = [];
        foreach ($bodies as $i => $body) {
            $pages[$i] = $nav."\n".$body."\n".$footer;
        }

        $result = (new BoilerplateFilter())->clean($pages);

        // Unique content survives.
        self::assertStringContainsString('Urlaubsregelung', $result['texts'][0]);
        self::assertStringContainsString('Helix Cloud', $result['texts'][2]);

        // Repeated navigation and footer are stripped.
        self::assertStringNotContainsString('Kontakt', $result['texts'][0]);
        self::assertStringNotContainsString('Alle Rechte vorbehalten', $result['texts'][0]);
        self::assertGreaterThan(0, $result['stats']['removed_segments']);
    }

    public function testChromeOnlyPageCollapsesToEmpty(): void
    {
        $nav = "Home\nUnternehmen\nKontakt\nImpressum";

        $pages = [
            $nav."\nDie Urlaubsregelung sieht 30 Tage vor.",
            $nav."\nDas Onboarding beginnt um 9 Uhr.",
            $nav."\nDie Helix Cloud Plattform.",
            $nav."\nReisekosten ueber das Portal.",
            $nav, // pure chrome
        ];

        $result = (new BoilerplateFilter())->clean($pages);

        self::assertSame('', trim($result['texts'][4]));
    }

    public function testSmallCorpusIsLeftUntouched(): void
    {
        // Below the minimum page count there is not enough signal: no-op.
        $pages = [
            "Home\nUnique one.",
            "Home\nUnique two.",
        ];

        $result = (new BoilerplateFilter())->clean($pages);

        self::assertSame($pages, $result['texts']);
        self::assertSame(0, $result['stats']['removed_segments']);
    }
}
