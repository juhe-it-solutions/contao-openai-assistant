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

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LinkIndexDocumentBuilder;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use PHPUnit\Framework\TestCase;

class LinkIndexDocumentBuilderTest extends TestCase
{
    /**
     * @return list<array{page_id: int, url: string, title: string, language: string}>
     */
    private static function pages(): array
    {
        return [
            ['page_id' => 1, 'url' => 'https://example.com/preise.html', 'title' => 'Preise', 'language' => 'de'],
            ['page_id' => 2, 'url' => 'https://example.com/start.html', 'title' => 'Startseite', 'language' => 'de'],
        ];
    }

    public function testListsDocumentsWithTheirSourcePages(): void
    {
        $pdf = new PageLink('https://example.com/files/preisliste.pdf', 'Preisliste 2026', PageLink::TYPE_FILE, '', '', 'files/preisliste.pdf', 1_258_291);

        $out = (new LinkIndexDocumentBuilder())->build(
            self::pages(),
            [1 => [$pdf], 2 => [$pdf]],
            'de',
            'example.com',
        );

        $this->assertStringContainsString('# Link- und Dokumentenverzeichnis — example.com', $out);
        $this->assertStringContainsString('## Dokumente und Downloads (1)', $out);
        $this->assertStringContainsString('[Preisliste 2026](https://example.com/files/preisliste.pdf) — PDF, 1,2 MB', $out);
        $this->assertStringContainsString('· verlinkt auf: Preise, Startseite', $out);
        $this->assertStringContainsString('## Seitenverzeichnis (2)', $out);
        $this->assertStringContainsString('- [Preise](https://example.com/preise.html)', $out);
    }

    public function testDeduplicatesDocumentsAndKeepsTheBestLabel(): void
    {
        $short = new PageLink('https://example.com/files/a.pdf', 'PDF', PageLink::TYPE_FILE, '', '', 'files/a.pdf');
        $long = new PageLink('https://example.com/files/a.pdf', 'Jahresbericht 2026', PageLink::TYPE_FILE, '', '', 'files/a.pdf');

        $out = (new LinkIndexDocumentBuilder())->build(self::pages(), [1 => [$short], 2 => [$long]], 'de');

        $this->assertStringContainsString('## Dokumente und Downloads (1)', $out);
        $this->assertStringContainsString('Jahresbericht 2026', $out);
    }

    public function testIgnoresNonDocumentLinks(): void
    {
        $page = new PageLink('https://example.com/kontakt.html', 'Kontakt', PageLink::TYPE_PAGE);
        $external = new PageLink('https://other.tld/x', 'Extern', PageLink::TYPE_EXTERNAL);

        $out = (new LinkIndexDocumentBuilder())->build(self::pages(), [1 => [$page, $external]], 'de');

        $this->assertStringNotContainsString('Dokumente und Downloads', $out);
        $this->assertStringNotContainsString('other.tld', $out);
        $this->assertStringContainsString('Seitenverzeichnis', $out);
    }

    public function testEnglishOutput(): void
    {
        $out = (new LinkIndexDocumentBuilder())->build(self::pages(), [], 'en');

        $this->assertStringContainsString('# Link and document directory', $out);
        $this->assertStringContainsString('## Page directory (2)', $out);
        $this->assertSame('Link and document directory', (new LinkIndexDocumentBuilder())->title('en'));
    }

    public function testReturnsEmptyStringWithoutAnyContent(): void
    {
        $this->assertSame('', (new LinkIndexDocumentBuilder())->build([], [], 'de'));
    }

    public function testEscapesMarkdownBreakingCharacters(): void
    {
        $pages = [['page_id' => 1, 'url' => 'https://example.com/a b.html', 'title' => 'Titel [X]', 'language' => 'de']];
        $out = (new LinkIndexDocumentBuilder())->build($pages, [], 'de');

        $this->assertStringContainsString('- [Titel (X)](https://example.com/a%20b.html)', $out);
    }

    public function testOutputIsDeterministic(): void
    {
        $pdf = new PageLink('https://example.com/files/a.pdf', 'A', PageLink::TYPE_FILE, '', '', 'files/a.pdf');
        $builder = new LinkIndexDocumentBuilder();

        $this->assertSame(
            $builder->build(self::pages(), [1 => [$pdf]], 'de'),
            $builder->build(self::pages(), [1 => [$pdf]], 'de'),
        );
    }
}
