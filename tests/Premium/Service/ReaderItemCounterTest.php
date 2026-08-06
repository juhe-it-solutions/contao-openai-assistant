<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ExpandArrayParameters;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\SQL\Parser;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\ReaderItemCounter;
use PHPUnit\Framework\TestCase;

class ReaderItemCounterTest extends TestCase
{
    public function testCountsNewsFaqAndEventItemsPerReaderPage(): void
    {
        $queries = [];
        $connection = $this->mockConnection(
            ['tl_news_archive', 'tl_news', 'tl_faq_category', 'tl_faq', 'tl_calendar', 'tl_calendar_events'],
            ['published', 'start', 'stop', 'source'],
            static function (string $sql, array $params = [], array $types = []) use (&$queries): array {
                $queries[] = $sql;

                return match (true) {
                    str_contains($sql, 'tl_news') => [['page_id' => 277, 'items' => 21]],
                    str_contains($sql, 'tl_faq') => [['page_id' => 63, 'items' => 8]],
                    str_contains($sql, 'tl_calendar_events') => [['page_id' => 277, 'items' => 4]],
                    default => [],
                };
            },
        );

        // Everything is indexed here, so the cap does not bite.
        $counts = (new ReaderItemCounter($connection))->countByPage([277, 63], [277 => 25, 63 => 8]);

        $this->assertSame(
            [277 => 25, 63 => 8],
            $counts,
            'A page serving both a news archive and a calendar must add both up.',
        );
        $this->assertCount(3, $queries, 'One query per content bundle; the index counts were supplied.');
    }

    public function testCapsTheCountAtWhatIsActuallyIndexed(): void
    {
        // 300 published news items but a list module that links only five of them: the
        // other 295 are never crawled and never reach the chatbot, so charging the
        // customer's plan for them would be wrong.
        $connection = $this->mockConnection(
            ['tl_news_archive', 'tl_news'],
            ['published'],
            static fn (string $sql, array $params = [], array $types = []): array => [['page_id' => 277, 'items' => 300]],
        );

        $this->assertSame(
            [277 => 5],
            (new ReaderItemCounter($connection))->countByPage([277], [277 => 5]),
        );
    }

    public function testCountsNothingWhileTheIndexIsStillEmpty(): void
    {
        // Before the first crawl the knowledge base really is empty, so reporting zero
        // items is the honest answer rather than an under-count.
        $connection = $this->mockConnection(
            ['tl_news_archive', 'tl_news'],
            ['published'],
            static fn (string $sql, array $params = [], array $types = []): array => [['page_id' => 277, 'items' => 300]],
        );

        $this->assertSame([], (new ReaderItemCounter($connection))->countByPage([277], []));
    }

    public function testLooksUpTheIndexCountsWhenTheCallerHasNone(): void
    {
        $sawSearchQuery = false;
        $connection = $this->mockConnection(
            ['tl_news_archive', 'tl_news', 'tl_search'],
            ['published'],
            static function (string $sql, array $params = [], array $types = []) use (&$sawSearchQuery): array {
                if (str_contains($sql, 'tl_search')) {
                    $sawSearchQuery = true;

                    return [['pid' => 277, 'urls' => 7]];
                }

                return [['page_id' => 277, 'items' => 300]];
            },
        );

        $this->assertSame([277 => 7], (new ReaderItemCounter($connection))->countByPage([277]));
        $this->assertTrue($sawSearchQuery, 'Without supplied counts the index is queried.');
    }

    public function testExcludesUnpublishedExpiredAndRedirectingItems(): void
    {
        $sql = null;
        $connection = $this->mockConnection(
            ['tl_news_archive', 'tl_news'],
            ['published', 'start', 'stop', 'source'],
            static function (string $query, array $params = [], array $types = []) use (&$sql): array {
                $sql ??= $query;

                return [];
            },
        );

        (new ReaderItemCounter($connection))->countByPage([277]);

        $this->assertNotNull($sql);
        $this->assertStringContainsString("item.published = '1'", $sql);
        $this->assertStringContainsString("item.start = '' OR item.start <= ?", $sql);
        $this->assertStringContainsString("item.stop = '' OR item.stop > ?", $sql);
        $this->assertStringContainsString('item.source NOT IN (?)', $sql, 'Items redirecting elsewhere never get a reader URL.');
    }

    public function testOmitsPredicatesForColumnsTheTableDoesNotHave(): void
    {
        // tl_faq has "published" but neither a start/stop window nor a source.
        $sql = null;
        $connection = $this->mockConnection(
            ['tl_faq_category', 'tl_faq'],
            ['published'],
            static function (string $query, array $params = [], array $types = []) use (&$sql): array {
                $sql ??= $query;

                return [];
            },
        );

        (new ReaderItemCounter($connection))->countByPage([63]);

        $this->assertNotNull($sql);
        $this->assertStringContainsString("item.published = '1'", $sql);
        $this->assertStringNotContainsString('item.start', $sql);
        $this->assertStringNotContainsString('item.source', $sql);
    }

    public function testReturnsNothingWhenTheBundlesAreNotInstalled(): void
    {
        $connection = $this->mockConnection([], [], static fn (string $sql, array $params = [], array $types = []): array => [['page_id' => 1, 'items' => 99]]);

        $this->assertSame([], (new ReaderItemCounter($connection))->countByPage([1, 2, 3]));
    }

    public function testTheGeneratedSqlSurvivesDoctrinesRealArrayParameterExpansion(): void
    {
        // The other tests mock the connection, so they would never notice a params/types
        // array that DBAL cannot line up - the failure mode would only appear on a real
        // database. This runs Doctrine's own parser and expansion over the generated SQL
        // instead, which is where a mismatch between placeholders, parameters and types
        // actually surfaces.
        $captured = null;
        $connection = $this->mockConnection(
            ['tl_news_archive', 'tl_news'],
            ['published', 'start', 'stop', 'source'],
            static function (string $sql, array $params, array $types) use (&$captured): array {
                // The REAL params and types the counter built - hardcoding them here
                // would only prove that this test agrees with itself.
                $captured = [$sql, $params, $types];

                return [];
            },
        );

        (new ReaderItemCounter($connection))->countByPage([277, 63]);

        $this->assertNotNull($captured);
        [$sql, $params, $types] = $captured;

        $visitor = new ExpandArrayParameters($params, $types);
        (new Parser(false))->parse($sql, $visitor);

        $this->assertSame(
            substr_count($visitor->getSQL(), '?'),
            \count($visitor->getParameters()),
            'Every placeholder after expansion must have exactly one parameter.',
        );
        $this->assertCount(
            \count($visitor->getParameters()),
            $visitor->getTypes(),
            'Parameters and types must stay aligned through the expansion.',
        );
        $this->assertSame(7, \count($visitor->getParameters()), '2 page ids + 2 timestamps + 3 source values.');
    }

    public function testIgnoresAnEmptyPageList(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $this->assertSame([], (new ReaderItemCounter($connection))->countByPage([]));
    }

    /**
     * @param list<string>                       $tables
     * @param list<string>                       $itemColumns
     * @param \Closure(string, array<mixed>, array<mixed>): list<array<string, mixed>> $onQuery
     */
    private function mockConnection(array $tables, array $itemColumns, \Closure $onQuery): Connection
    {
        $columns = [];

        foreach ([...$itemColumns, 'id', 'pid'] as $name) {
            $columns[$name] = new Column($name, Type::getType(Types::STRING));
        }

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn($tables);
        $schemaManager->method('listTableColumns')->willReturn($columns);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection
            ->method('fetchAllAssociative')
            ->willReturnCallback(
                static fn (string $sql, array $params = [], array $types = []): array => $onQuery($sql, $params, $types),
            )
        ;

        return $connection;
    }
}
