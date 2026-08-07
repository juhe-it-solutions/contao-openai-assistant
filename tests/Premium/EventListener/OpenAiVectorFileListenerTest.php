<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\EventListener;

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\EventListener\OpenAiVectorFileListener;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;

class OpenAiVectorFileListenerTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $dcaBackup = [];

    protected function setUp(): void
    {
        $this->dcaBackup = $GLOBALS['TL_DCA'] ?? [];

        $GLOBALS['TL_DCA']['tl_openai_vector_file']['list']['label']['fields'] = [
            'page_id', 'title', 'url', 'indexed_urls', 'status', 'chunk_index', 'bytes', 'openai_file_id', 'tstamp',
        ];

        // The status label comes from the DCA reference, never from the pre-formatted
        // column value - Contao 6 hands those over already escaped.
        $GLOBALS['TL_DCA']['tl_openai_vector_file']['fields']['status']['reference'] = [
            'uploaded' => 'Hochgeladen',
            'failed' => 'Fehlgeschlagen',
        ];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TL_DCA'] = $this->dcaBackup;
        unset($GLOBALS['TL_LANG']['tl_openai_vector_file']);
    }

    public function testRendersThePageAndFileColumnsAsAMap(): void
    {
        $args = $this->format($this->row(), true);

        $this->assertSame('<a href="/contao?do=page&amp;act=edit&amp;id=42" title="Edit page in the site structure">42</a>', $args[0]);
        $this->assertSame(
            '<a href="https://example.test/preise" target="_blank" rel="noopener noreferrer" title="Open page in a new tab">https://example.test/preise</a>',
            $args[2],
        );
        $this->assertSame('47', $args[3], 'A reader page must show how many indexed URLs went into its one document.');
        $this->assertSame('<span class="vsau-badge green">Hochgeladen</span>', $args[4]);
        $this->assertSame('2/3', $args[5]);
        $this->assertSame('<code>file-abc123</code>', $args[7]);
    }

    /**
     * The label callback output is rendered raw by DC_Table, so a page title or URL coming
     * from the database must never reach the listing as markup.
     */
    public function testEscapesValuesTakenFromTheDatabase(): void
    {
        $args = $this->format(
            array_merge($this->row(), [
                'title' => '<script>alert(1)</script>',
                'url' => 'https://example.test/"><script>alert(1)</script>',
                'openai_file_id' => '<img src=x onerror=alert(1)>',
            ]),
            true,
        );

        $this->assertStringNotContainsString('<script>', $args[1]);
        $this->assertStringNotContainsString('<script>', $args[2]);
        $this->assertStringNotContainsString('<img', $args[7]);
        $this->assertStringContainsString('&lt;script&gt;', $args[1]);
    }

    /**
     * A "javascript:" value in the URL column must stay text: an href built from it would
     * execute on click.
     */
    public function testNeverLinksANonHttpScheme(): void
    {
        $args = $this->format(array_merge($this->row(), ['url' => 'javascript:alert(1)']), true);

        $this->assertSame('javascript:alert(1)', $args[2]);
    }

    public function testPageIdIsPlainTextWithoutAccessToThePageModule(): void
    {
        $args = $this->format($this->row(), false);

        $this->assertSame('42', $args[0]);
    }

    /**
     * page_id 0 is the synthetic site-wide link directory, not a Contao page - it must not
     * link into the site structure.
     */
    public function testTheLinkDirectoryIsNotShownAsAPage(): void
    {
        $args = $this->format(array_merge($this->row(), ['page_id' => 0]), true);

        $this->assertSame('Link directory (not a page)', $args[0]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<int, string>
     */
    private function format(array $row, bool $canAccessPages): array
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($canAccessPages);

        $router = $this->createMock(RouterInterface::class);
        $router
            ->method('generate')
            ->willReturnCallback(
                static fn (string $name, array $parameters = []): string => '/contao?'.http_build_query($parameters),
            )
        ;

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllKeyValue')
            // tl_search rows per page: the reader page 42 merged 47 news/FAQ/event URLs.
            ->willReturn(['42' => '47', '43' => '1'])
        ;

        $listener = new OpenAiVectorFileListener($security, $router, new RequestStack(), $connection);

        // DC_Table hands over the pre-formatted column values; only the ones the callback
        // rewrites matter here. formatColumns() is the version-independent half of the
        // label callback, so this test holds on Contao 5 and 6 alike.
        return $listener->formatColumns(
            $row,
            ['42', 'Preise', 'https://example.test/preise', '', 'Uploaded', '1', '2048', 'file-abc123', '01.08.2026 12:00'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'page_id' => 42,
            'title' => 'Preise',
            'url' => 'https://example.test/preise',
            'status' => 'uploaded',
            'chunk_index' => 1,
            'chunk_count' => 3,
            'bytes' => 2048,
            'openai_file_id' => 'file-abc123',
        ];
    }
}
