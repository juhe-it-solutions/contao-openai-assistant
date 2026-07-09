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

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreFileSync;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class VectorStoreFileSyncTest extends TestCase
{
    public function testFailedReplacementKeepsPreviousVectorFileState(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'old_file', hash('sha256', 'old content'), 'uploaded');

        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options = []) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('POST' === $method && str_contains($url, '/vector_stores/vs_123/files')) {
                    return new MockResponse('{"error":{"message":"temporary attach failure"}}', ['http_code' => 500]);
                }

                if ('DELETE' === $method && str_ends_with($url, '/vector_stores/vs_123/files/new_file')) {
                    return new MockResponse('{}');
                }

                if ('DELETE' === $method && str_ends_with($url, '/files/new_file')) {
                    return new MockResponse('{}');
                }

                self::fail('Unexpected request: '.$method.' '.$url);
            },
        );

        $stats = (new VectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('new content')],
        );

        $this->assertSame(1, $stats['files_failed']);
        $this->assertSame(0, $stats['updated']);

        $this->assertSame(
            [
                [
                    'pid' => 7,
                    'tstamp' => $rows[0]['tstamp'],
                    'page_id' => 42,
                    'url' => 'https://example.test/page',
                    'title' => 'Example Page',
                    'language' => 'en',
                    'search_checksum' => 'search_checksum',
                    'content_hash' => hash('sha256', 'old content'),
                    'chunk_index' => 0,
                    'chunk_count' => 1,
                    'openai_file_id' => 'old_file',
                    'bytes' => 100,
                    'status' => 'uploaded',
                    'last_error' => null,
                ],
            ],
            $rows,
        );

        $this->assertContains('DELETE https://api.openai.com/v1/vector_stores/vs_123/files/new_file', $requests);
        $this->assertContains('DELETE https://api.openai.com/v1/files/new_file', $requests);
        $this->assertNotContains('DELETE https://api.openai.com/v1/vector_stores/vs_123/files/old_file', $requests);
        $this->assertNotContains('DELETE https://api.openai.com/v1/files/old_file', $requests);
    }

    public function testSuccessfulReplacementSwapsStateBeforeDeletingOldFiles(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'old_file', hash('sha256', 'old content'), 'uploaded');

        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options = []) use (&$rows, &$requests): MockResponse {
                $requests[] = $method.' '.$url;

                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('POST' === $method && str_contains($url, '/vector_stores/vs_123/files')) {
                    return new MockResponse('{}');
                }

                if ('GET' === $method && str_ends_with($url, '/vector_stores/vs_123/files/new_file')) {
                    return new MockResponse('{"status":"completed"}');
                }

                if ('DELETE' === $method && str_ends_with($url, '/vector_stores/vs_123/files/old_file')) {
                    self::assertSame(['new_file'], array_column($rows, 'openai_file_id'));

                    return new MockResponse('{}');
                }

                if ('DELETE' === $method && str_ends_with($url, '/files/old_file')) {
                    return new MockResponse('{}');
                }

                self::fail('Unexpected request: '.$method.' '.$url);
            },
        );

        $stats = (new VectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('new content')],
        );

        $this->assertSame(0, $stats['files_failed']);
        $this->assertSame(1, $stats['updated']);

        $this->assertSame(
            [
                [
                    'pid' => 7,
                    'tstamp' => $rows[0]['tstamp'],
                    'page_id' => 42,
                    'url' => 'https://example.test/page',
                    'title' => 'Example Page',
                    'language' => 'en',
                    'search_checksum' => 'search_checksum',
                    'content_hash' => hash('sha256', 'new content'),
                    'chunk_index' => 0,
                    'chunk_count' => 1,
                    'openai_file_id' => 'new_file',
                    'bytes' => \strlen("# Example Page\n\nQuelle: https://example.test/page\n\nnew content"),
                    'status' => 'uploaded',
                    'last_error' => null,
                ],
            ],
            $rows,
        );

        $this->assertContains('DELETE https://api.openai.com/v1/vector_stores/vs_123/files/old_file', $requests);
        $this->assertContains('DELETE https://api.openai.com/v1/files/old_file', $requests);
    }

    public function testDatabaseSwapFailureKeepsPreviousStateAndCleansReplacementFile(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows, true);
        $this->insertVectorFile($rows, 'old_file', hash('sha256', 'old content'), 'uploaded');

        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url, array $options = []) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('POST' === $method && str_contains($url, '/vector_stores/vs_123/files')) {
                    return new MockResponse('{}');
                }

                if ('GET' === $method && str_ends_with($url, '/vector_stores/vs_123/files/new_file')) {
                    return new MockResponse('{"status":"completed"}');
                }

                if ('DELETE' === $method && str_ends_with($url, '/vector_stores/vs_123/files/new_file')) {
                    return new MockResponse('{}');
                }

                if ('DELETE' === $method && str_ends_with($url, '/files/new_file')) {
                    return new MockResponse('{}');
                }

                self::fail('Unexpected request: '.$method.' '.$url);
            },
        );

        try {
            (new VectorStoreFileSync($connection, $client, new NullLogger()))->sync(
                'sk-test',
                'vs_123',
                7,
                [$this->page('new content')],
            );

            $this->fail('Expected database swap failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated insert failure.', $e->getMessage());
        }

        $this->assertSame(['old_file'], array_column($rows, 'openai_file_id'));
        $this->assertContains('DELETE https://api.openai.com/v1/vector_stores/vs_123/files/new_file', $requests);
        $this->assertContains('DELETE https://api.openai.com/v1/files/new_file', $requests);
        $this->assertNotContains('DELETE https://api.openai.com/v1/vector_stores/vs_123/files/old_file', $requests);
        $this->assertNotContains('DELETE https://api.openai.com/v1/files/old_file', $requests);
    }

    public function testProgressCallbackReportsPagesDoneOfTotal(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        // Page 42 already uploaded with identical content -> counted as unchanged.
        $this->insertVectorFile($rows, 'old_file', hash('sha256', 'same content'), 'uploaded');

        $client = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('POST' === $method && str_contains($url, '/vector_stores/vs_123/files')) {
                    return new MockResponse('{}');
                }

                if ('GET' === $method && str_ends_with($url, '/vector_stores/vs_123/files/new_file')) {
                    return new MockResponse('{"status":"completed"}');
                }

                self::fail('Unexpected request: '.$method.' '.$url);
            },
        );

        $calls = [];
        $stats = (new VectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [
                $this->page('same content'),
                array_merge($this->page('new content'), ['page_id' => 43, 'url' => 'https://example.test/other']),
            ],
            '',
            static function (int $done, int $total) use (&$calls): void {
                $calls[] = [$done, $total];
            },
        );

        $this->assertSame(1, $stats['unchanged']);
        $this->assertSame(1, $stats['added']);
        $this->assertSame([[0, 2], [1, 2], [2, 2]], $calls);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createConnection(array &$rows, bool $failInserts = false): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('transactional')
            ->willReturnCallback(
                static function (callable $callback) use (&$rows) {
                    $snapshot = $rows;

                    try {
                        return $callback();
                    } catch (\Throwable $e) {
                        $rows = $snapshot;

                        throw $e;
                    }
                },
            )
        ;
        $connection
            ->method('fetchAllAssociative')
            ->willReturnCallback(
                static function () use (&$rows): array {
                    return array_map(
                        static fn (array $row): array => [
                            'page_id' => $row['page_id'],
                            'content_hash' => $row['content_hash'],
                            'status' => $row['status'],
                            'openai_file_id' => $row['openai_file_id'],
                        ],
                        $rows,
                    );
                },
            )
        ;
        $connection
            ->method('insert')
            ->willReturnCallback(
                static function (string $table, array $data) use (&$rows, $failInserts): int {
                    self::assertSame('tl_openai_vector_file', $table);
                    if ($failInserts) {
                        throw new \RuntimeException('Simulated insert failure.');
                    }

                    $rows[] = $data;

                    return 1;
                },
            )
        ;
        $connection
            ->method('delete')
            ->willReturnCallback(
                static function (string $table, array $criteria) use (&$rows): int {
                    self::assertSame('tl_openai_vector_file', $table);
                    $before = \count($rows);
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => $row['pid'] !== $criteria['pid'] || $row['page_id'] !== $criteria['page_id'],
                    ));

                    return $before - \count($rows);
                },
            )
        ;

        return $connection;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function insertVectorFile(array &$rows, string $fileId, string $contentHash, string $status): void
    {
        $rows[] = [
            'pid' => 7,
            'tstamp' => time(),
            'page_id' => 42,
            'url' => 'https://example.test/page',
            'title' => 'Example Page',
            'language' => 'en',
            'search_checksum' => 'search_checksum',
            'content_hash' => $contentHash,
            'chunk_index' => 0,
            'chunk_count' => 1,
            'openai_file_id' => $fileId,
            'bytes' => 100,
            'status' => $status,
            'last_error' => null,
        ];
    }

    /**
     * @return array{page_id: int, url: string, title: string, language: string, content: string, search_checksum: string}
     */
    private function page(string $content): array
    {
        return [
            'page_id' => 42,
            'url' => 'https://example.test/page',
            'title' => 'Example Page',
            'language' => 'en',
            'content' => $content,
            'search_checksum' => 'search_checksum',
        ];
    }
}
