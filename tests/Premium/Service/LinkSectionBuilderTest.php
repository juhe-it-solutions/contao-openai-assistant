<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkSectionBuilder;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use PHPUnit\Framework\TestCase;

class LinkSectionBuilderTest extends TestCase
{
    public function testReturnsEmptyStringWithoutLinks(): void
    {
        $this->assertSame('', (new LinkSectionBuilder())->build([], 'Preise', 'de'));
    }

    public function testGroupsAndOrdersLinks(): void
    {
        $links = [
            new PageLink('https://example.com/kontakt.html', 'Kontakt', PageLink::TYPE_PAGE),
            new PageLink('https://other.tld/norm', 'ÖNORM B 1300', PageLink::TYPE_EXTERNAL),
            new PageLink('https://example.com/files/preisliste.pdf', 'Preisliste 2026', PageLink::TYPE_FILE, '', '', 'files/preisliste.pdf', 1_258_291),
        ];

        $out = (new LinkSectionBuilder())->build($links, 'Preise und Konditionen', 'de');

        $this->assertStringContainsString('## Weiterführende Links auf „Preise und Konditionen"', $out);
        $this->assertStringContainsString('### Dokumente und Downloads', $out);
        $this->assertStringContainsString('- [Preisliste 2026](https://example.com/files/preisliste.pdf) — PDF, 1,2 MB', $out);
        $this->assertStringContainsString('### Seiten auf dieser Website', $out);
        $this->assertStringContainsString('- [Kontakt](https://example.com/kontakt.html)', $out);
        $this->assertStringContainsString('### Externe Links', $out);

        // Documents first, then pages, then external.
        $this->assertLessThan(strpos($out, 'Seiten auf dieser Website'), strpos($out, 'Dokumente und Downloads'));
        $this->assertLessThan(strpos($out, 'Externe Links'), strpos($out, 'Seiten auf dieser Website'));
    }

    public function testEnglishHeadingsAndNumberFormat(): void
    {
        $links = [new PageLink('https://example.com/files/price.pdf', 'Price list', PageLink::TYPE_FILE, '', '', 'files/price.pdf', 1_258_291)];
        $out = (new LinkSectionBuilder())->build($links, 'Pricing', 'en');

        $this->assertStringContainsString('## Related links on "Pricing"', $out);
        $this->assertStringContainsString('### Documents and downloads', $out);
        $this->assertStringContainsString('— PDF, 1.2 MB', $out);
    }

    public function testFallsBackToEnglishForOtherLanguages(): void
    {
        $links = [new PageLink('https://example.com/a.html', 'A', PageLink::TYPE_PAGE)];

        $this->assertStringContainsString('Related links', (new LinkSectionBuilder())->build($links, 'T', 'fr'));
    }

    public function testOmitsHeadingTitleWhenPageTitleIsEmpty(): void
    {
        $links = [new PageLink('https://example.com/a.html', 'A', PageLink::TYPE_PAGE)];
        $out = (new LinkSectionBuilder())->build($links, '  ', 'de');

        $this->assertStringContainsString('## Weiterführende Links', $out);
        $this->assertStringNotContainsString('„', $out);
    }

    /**
     * The chat frontend parses "[label](url)"; a bracket in the label or a space in
     * the URL would break the anchor and leak raw Markdown into an answer.
     */
    public function testEscapesMarkdownBreakingCharacters(): void
    {
        $links = [
            new PageLink('https://example.com/a b.html', 'Titel [mit] Klammern', PageLink::TYPE_PAGE),
            new PageLink('https://example.com/x(1.html', 'Unbalanciert', PageLink::TYPE_PAGE),
            new PageLink('https://en.wikipedia.org/wiki/Function_(mathematics)', 'Funktion', PageLink::TYPE_EXTERNAL),
        ];

        $out = (new LinkSectionBuilder())->build($links, 'T', 'de');

        $this->assertStringContainsString('[Titel (mit) Klammern](https://example.com/a%20b.html)', $out);
        $this->assertStringContainsString('(https://example.com/x%281.html)', $out);
        // Balanced parentheses are supported by the renderer and stay readable.
        $this->assertStringContainsString('(https://en.wikipedia.org/wiki/Function_(mathematics))', $out);
    }

    public function testAddsTitleAttributeAsHintOnlyWhenItAddsInformation(): void
    {
        $links = [
            new PageLink('https://example.com/a.html', 'Antrag', PageLink::TYPE_PAGE, 'Antrag'),
            new PageLink('https://example.com/b.html', 'Formular', PageLink::TYPE_PAGE, 'Antrag auf Kostenübernahme'),
        ];

        $out = (new LinkSectionBuilder())->build($links, 'T', 'de');

        $this->assertStringContainsString('- [Antrag](https://example.com/a.html)'."\n", $out);
        $this->assertStringContainsString('- [Formular](https://example.com/b.html) — Antrag auf Kostenübernahme', $out);
    }

    public function testRendersContactGroups(): void
    {
        $links = [
            new PageLink('mailto:office@example.com', 'Büro', PageLink::TYPE_MAILTO),
            new PageLink('tel:+431234567', 'Zentrale', PageLink::TYPE_TEL),
        ];

        $out = (new LinkSectionBuilder())->build($links, 'Kontakt', 'de');

        $this->assertStringContainsString('### E-Mail-Adressen', $out);
        $this->assertStringContainsString('- [Büro](mailto:office@example.com)', $out);
        $this->assertStringContainsString('### Telefonnummern', $out);
    }

    public function testFormatsSmallAndLargeSizes(): void
    {
        $builder = new LinkSectionBuilder();

        $small = $builder->build([new PageLink('https://example.com/f/a.pdf', 'A', PageLink::TYPE_FILE, '', '', 'f/a.pdf', 900)], 'T', 'de');
        $this->assertStringContainsString('— PDF, 900 B', $small);

        $large = $builder->build([new PageLink('https://example.com/f/b.zip', 'B', PageLink::TYPE_FILE, '', '', 'f/b.zip', 524_288_000)], 'T', 'de');
        $this->assertStringContainsString('— ZIP, 500 MB', $large);
    }

    public function testOutputIsDeterministic(): void
    {
        $links = [new PageLink('https://example.com/a.html', 'A', PageLink::TYPE_PAGE)];
        $builder = new LinkSectionBuilder();

        $this->assertSame($builder->build($links, 'T', 'de'), $builder->build($links, 'T', 'de'));
    }
}
