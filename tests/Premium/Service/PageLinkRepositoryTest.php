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

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\BooleanType;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLink;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\PageLinkRepository;
use PHPUnit\Framework\TestCase;

class PageLinkRepositoryTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createRepository(array $rows, bool $schemaReady = true): PageLinkRepository
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn($schemaReady);
        // Real Column objects are never null; isset() would be false for null and
        // the schema guard would wrongly report "not migrated".
        $schemaManager->method('listTableColumns')->willReturn(
            $schemaReady ? ['auto_update_include_links' => new Column('auto_update_include_links', new BooleanType())] : [],
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new PageLinkRepository($connection);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(array $overrides = []): array
    {
        return array_merge([
            'page_id' => 7,
            'url' => 'https://example.com/a.html',
            'label' => 'A',
            'link_title' => '',
            'type' => PageLink::TYPE_PAGE,
            'mime' => '',
            'file_path' => '',
            'file_size' => 0,
            'position' => 0,
            'occurrences' => 1,
        ], $overrides);
    }

    /**
     * A page indexed under several URLs (paginated readers) stores the same link
     * once per document. The sync merges those documents into one page document, so
     * the repository has to merge the links too - otherwise the rendered list would
     * repeat them.
     */
    public function testMergesDuplicateLinksOfOnePage(): void
    {
        $repository = $this->createRepository([
            self::row(['label' => 'A', 'occurrences' => 2]),
            self::row(['label' => 'Ausführlicher Text', 'occurrences' => 1]),
            self::row(['url' => 'https://example.com/b.html', 'label' => 'B', 'position' => 1]),
        ]);

        $links = $repository->findForPages([7]);

        $this->assertCount(2, $links[7]);
        $this->assertSame('https://example.com/a.html', $links[7][0]->url);
        $this->assertSame('Ausführlicher Text', $links[7][0]->label, 'keeps the most descriptive label');
        $this->assertSame(3, $links[7][0]->occurrences, 'sums the occurrences');
    }

    /**
     * The merge here reads labels back out of the database, so it applies the same
     * ranking the extractor does: a label that only restates the target loses to one
     * a human wrote, however much longer it is.
     */
    public function testMergeDoesNotPreferALabelThatOnlyRestatesTheTarget(): void
    {
        $repository = $this->createRepository([
            self::row(['label' => 'Anfahrt']),
            self::row(['label' => 'example.com/a.html']),
            self::row(['label' => '#lageplan']),
        ]);

        $links = $repository->findForPages([7]);

        $this->assertCount(1, $links[7]);
        $this->assertSame('Anfahrt', $links[7][0]->label);
        $this->assertSame(3, $links[7][0]->occurrences);
    }

    public function testCapsLinksPerPage(): void
    {
        $rows = [];

        for ($i = 0; $i < 120; ++$i) {
            $rows[] = self::row(['url' => 'https://example.com/seite-'.$i.'.html', 'position' => $i]);
        }

        $links = $this->createRepository($rows)->findForPages([7]);

        $this->assertCount(40, $links[7]);
    }

    /**
     * A page indexed under many URLs can exceed the per-document cap; documents
     * must survive that merge, not the pages that happen to come first.
     */
    public function testCapPrefersDocumentsWhenMergingManyDocuments(): void
    {
        $rows = [];

        for ($i = 0; $i < 60; ++$i) {
            $rows[] = self::row(['url' => 'https://example.com/seite-'.$i.'.html', 'position' => $i]);
        }

        for ($i = 0; $i < 3; ++$i) {
            $rows[] = self::row([
                'url' => 'https://example.com/files/bericht-'.$i.'.pdf',
                'type' => PageLink::TYPE_FILE,
                'position' => 100 + $i,
            ]);
        }

        $links = $this->createRepository($rows)->findForPages([7]);
        $files = array_filter($links[7], static fn (PageLink $l): bool => PageLink::TYPE_FILE === $l->type);

        $this->assertCount(40, $links[7]);
        $this->assertCount(3, $files);
    }

    public function testGroupsByPage(): void
    {
        $links = $this->createRepository([
            self::row(['page_id' => 7]),
            self::row(['page_id' => 9, 'url' => 'https://example.com/c.html']),
        ])->findForPages([7, 9]);

        $this->assertSame([7, 9], array_keys($links));
    }

    public function testReturnsNothingWithoutPageIds(): void
    {
        $this->assertSame([], $this->createRepository([self::row()])->findForPages([]));
    }

    /**
     * The publish filter in readAllPages() stops an unpublished page from being
     * uploaded; this set stops the OTHER pages from linking to it. Without it the page
     * is gone from the chatbot's knowledge while a link block still sends the visitor
     * to a URL that 404s.
     */
    public function testCollectsTheUrlsOfUnpublishedPages(): void
    {
        $capturedSql = null;

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn(
            ['auto_update_include_links' => new Column('auto_update_include_links', new BooleanType())],
        );

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $capturedTypes = null;

        $connection
            ->method('fetchFirstColumn')
            ->willReturnCallback(
                static function (string $sql, array $params = [], array $types = []) use (&$capturedSql, &$capturedTypes): array {
                    $capturedSql = $sql;
                    $capturedTypes = $types;

                    return ['https://example.com/retired.html'];
                },
            )
        ;

        $urls = (new PageLinkRepository($connection))->unpublishedUrls();

        $this->assertArrayHasKey(
            PageLink::comparisonKey('https://example.com/retired.html'),
            $urls,
            'The URL must be keyed exactly like protectedUrls(), so the two sets merge.',
        );
        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString("p.published != '1'", $capturedSql);
        $this->assertStringContainsString("p.start != '' AND p.start > ?", $capturedSql, 'Not live yet counts as unpublished.');
        $this->assertStringContainsString("p.stop != '' AND p.stop <= ?", $capturedSql, 'Expired counts as unpublished.');
        $this->assertStringContainsString(
            'ORDER BY s.url',
            $capturedSql,
            'Without a deterministic order the LIMIT truncates a different subset each run, '
            .'which makes link blocks flap and re-uploads documents for nothing.',
        );
        $this->assertSame(
            [ParameterType::INTEGER, ParameterType::INTEGER],
            $capturedTypes,
            'start/stop are varchar columns: an untyped parameter binds as a STRING and '
            .'would compare lexicographically instead of numerically.',
        );
    }

    /**
     * Before contao:migrate the table does not exist yet - every read must be a
     * silent no-op instead of a fatal error.
     */
    public function testIsInertWhenTheSchemaIsNotReady(): void
    {
        $repository = $this->createRepository([self::row()], false);

        $this->assertFalse($repository->isSchemaReady());
        $this->assertFalse($repository->isFeatureEnabled());
        $this->assertSame([], $repository->findForPages([7]));
        $this->assertSame(0, $repository->pruneOrphans());
    }
}
