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
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\VectorStoreFileSync;
use JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Fixtures\SleeplessVectorStoreFileSync;
use PHPUnit\Framework\Attributes\DataProvider;
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
                    'id' => 1000,
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
                    'id' => 1,
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
     * The title heads the uploaded document and travels as a file attribute, so a page that
     * only got a new title must be re-uploaded - otherwise the vector store keeps the old
     * heading until the text happens to change.
     */
    public function testARenamedPageIsReUploadedEvenWithUnchangedText(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
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

                if ('DELETE' === $method) {
                    return new MockResponse('{}');
                }

                self::fail('Unexpected request: '.$method.' '.$url);
            },
        );

        $stats = (new VectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [array_merge($this->page('same content'), ['title' => 'Aktuelles'])],
        );

        $this->assertSame(1, $stats['updated'], 'A new title alone must count as a change.');
        $this->assertSame(0, $stats['unchanged']);
        $this->assertSame('Aktuelles', $rows[0]['title']);
    }

    public function testUploadUsesASpeakingFilename(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);

        $filenames = [];
        $client = new MockHttpClient(
            function (string $method, string $url, array $options = []) use (&$filenames): MockResponse {
                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    // OpenAI shows this name in its file list, so it must identify the page.
                    preg_match('/filename="([^"]+)"/', $this->readBody($options), $matches);
                    $filenames[] = $matches[1] ?? '';

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

        (new VectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [array_merge($this->page('content'), ['title' => 'Über uns & Kontakt'])],
        );

        $this->assertSame(['seite-42-ueber-uns-kontakt.md'], $filenames);
    }

    public function testPageStatesMapEveryPageToItsVectorStoreFile(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        // Page 42 already uploaded with identical content -> unchanged, keeps its old file.
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

        $stats = (new VectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [
                $this->page('same content'),
                array_merge($this->page('new content'), ['page_id' => 43, 'url' => 'https://example.test/other']),
            ],
        );

        $this->assertSame(
            [
                42 => ['state' => 'unchanged', 'files' => ['old_file']],
                43 => ['state' => 'added', 'files' => ['new_file']],
            ],
            $stats['page_states'],
        );
    }

    /**
     * A page that leaves the sync scope is the privacy path: it was protected, unpublished
     * or deleted. If OpenAI does not confirm the deletion, the document is still attached to
     * the store and still answering visitors - so the row must survive as the retry handle,
     * and the run must not claim the page was removed.
     */
    #[DataProvider('provideUnconfirmedDeletionResponses')]
    public function testAnUnconfirmedDeletionKeepsTheFileTrackedForRetry(callable $respond, int $expectedRequests): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'old_file', hash('sha256', 'old content'), 'uploaded');

        $requests = 0;
        $client = new MockHttpClient(
            static function (string $method, string $url) use ($respond, &$requests): MockResponse {
                self::assertSame('DELETE', $method);
                ++$requests;

                return $respond();
            },
        );

        // No pages at all: page 42 has dropped out of scope entirely.
        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync('sk-test', 'vs_123', 7, []);

        $this->assertSame(0, $stats['removed'], 'An unconfirmed deletion must never be reported as a removal.');
        $this->assertSame(1, $stats['deletes_pending']);
        $this->assertSame($expectedRequests, $requests);

        $this->assertCount(1, $rows, 'The row is the only handle on the remote file and must survive.');
        $this->assertSame('pending_delete', $rows[0]['status']);
        $this->assertSame('old_file', $rows[0]['openai_file_id']);
    }

    /**
     * @return iterable<string, array{callable(): MockResponse, int}>
     */
    public static function provideUnconfirmedDeletionResponses(): iterable
    {
        // A revoked or wrong key. Not retryable, so one attempt.
        yield '401 unauthorised' => [static fn (): MockResponse => new MockResponse('{"error":{"message":"invalid key"}}', ['http_code' => 401]), 1];

        // Retryable, so the full ladder runs and the LAST response is what request()
        // returns - the exact case that used to read as a success.
        yield '429 after every retry' => [static fn (): MockResponse => new MockResponse('{}', ['http_code' => 429]), 6];
        yield '500 after every retry' => [static fn (): MockResponse => new MockResponse('{}', ['http_code' => 500]), 6];

        // Nothing came back at all. The error belongs in the info array - as a body it would
        // just be a 200 with odd content, which is not the case under test.
        yield 'transport failure' => [static fn (): MockResponse => new MockResponse('', ['error' => 'connection reset']), 6];

        // A 4xx that is neither "gone" nor retryable.
        yield '403 forbidden' => [static fn (): MockResponse => new MockResponse('{}', ['http_code' => 403]), 1];
    }

    /**
     * 404 is the state we are trying to reach: the file is not there any more. Treating it
     * as a failure would keep a row retrying a deletion that already happened, forever.
     */
    public function testAMissingRemoteFileCountsAsDeleted(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'old_file', hash('sha256', 'old content'), 'uploaded');

        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{"error":{"message":"No such file"}}', ['http_code' => 404]),
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync('sk-test', 'vs_123', 7, []);

        $this->assertSame(1, $stats['removed']);
        $this->assertSame(0, $stats['deletes_pending']);
        $this->assertSame([], $rows);
    }

    /**
     * The point of keeping the row: the run after the outage has to finish the job without
     * anyone intervening.
     */
    public function testAPendingDeletionIsRetriedAndClearedOnTheNextRun(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'old_file', hash('sha256', 'old content'), 'uploaded');

        $failing = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{}', ['http_code' => 401]),
        );

        $firstRun = (new SleeplessVectorStoreFileSync($connection, $failing, new NullLogger()))->sync('sk-test', 'vs_123', 7, []);

        $this->assertSame(1, $firstRun['deletes_pending']);
        $this->assertSame('pending_delete', $rows[0]['status']);

        $retried = [];
        $recovered = new MockHttpClient(
            static function (string $method, string $url) use (&$retried): MockResponse {
                $retried[] = $method.' '.$url;

                return new MockResponse('{}');
            },
        );

        $secondRun = (new SleeplessVectorStoreFileSync($connection, $recovered, new NullLogger()))->sync('sk-test', 'vs_123', 7, []);

        $this->assertSame(0, $secondRun['deletes_pending'], 'The retry succeeded, so nothing is left pending.');
        $this->assertSame([], $rows, 'The retry handle goes only once the file is provably gone.');
        $this->assertSame(
            [
                'DELETE https://api.openai.com/v1/vector_stores/vs_123/files/old_file',
                'DELETE https://api.openai.com/v1/files/old_file',
            ],
            $retried,
        );
    }

    /**
     * A pending_delete row tracks a file on its way OUT. If loadState() handed it back as the
     * page's current document, the page would look uploaded, be skipped as unchanged, and the
     * store would keep answering from a file nobody is tracking any more.
     */
    public function testAPendingDeletionIsNotMistakenForAnUploadedPage(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'stale_file', hash('sha256', 'same content'), 'pending_delete');

        $client = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                if ('DELETE' === $method) {
                    // Still failing, so the row stays pending across this run too.
                    return new MockResponse('{}', ['http_code' => 401]);
                }

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

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('same content')],
        );

        $this->assertSame(1, $stats['added'], 'The page has no live document, so it must be uploaded.');
        $this->assertSame(0, $stats['unchanged']);
        $this->assertSame(1, $stats['deletes_pending']);

        $statuses = array_column($rows, 'status', 'openai_file_id');
        $this->assertSame(['stale_file' => 'pending_delete', 'new_file' => 'uploaded'], $statuses);
    }

    /**
     * The first v2.2 sync of an existing premium installation. The legacy bulk file is the
     * only knowledge base the site has until the per-page documents exist, so it must not be
     * deleted before they do.
     */
    #[DataProvider('provideLegacyTransitionFailures')]
    public function testTheLegacyBulkFileSurvivesAFailedFirstSync(callable $client): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);

        $requests = [];
        $mock = new MockHttpClient(
            static function (string $method, string $url, array $options = []) use ($client, &$requests): MockResponse {
                $requests[] = $method.' '.$url;

                return $client($method, $url);
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $mock, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('new content')],
            'legacy_bulk_file',
        );

        $this->assertSame(1, $stats['files_failed']);
        $this->assertFalse(
            $stats['legacy_file_removed'],
            'The id must be kept so auto_update_file_id survives and the next run can retry.',
        );
        $this->assertNotContains('DELETE https://api.openai.com/v1/vector_stores/vs_123/files/legacy_bulk_file', $requests);
        $this->assertNotContains('DELETE https://api.openai.com/v1/files/legacy_bulk_file', $requests);
    }

    /**
     * @return iterable<string, array{callable(string, string): MockResponse}>
     */
    public static function provideLegacyTransitionFailures(): iterable
    {
        yield 'upload fails' => [
            static fn (string $method, string $url): MockResponse => 'POST' === $method && 'https://api.openai.com/v1/files' === $url
                ? new MockResponse('{"error":{"message":"upload rejected"}}', ['http_code' => 400])
                : new MockResponse('{}'),
        ];

        yield 'attach fails' => [
            static function (string $method, string $url): MockResponse {
                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('POST' === $method && str_contains($url, '/vector_stores/vs_123/files')) {
                    return new MockResponse('{"error":{"message":"attach rejected"}}', ['http_code' => 400]);
                }

                return new MockResponse('{}');
            },
        ];

        yield 'ingestion fails' => [
            static function (string $method, string $url): MockResponse {
                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('GET' === $method) {
                    return new MockResponse('{"status":"failed","last_error":{"message":"could not parse"}}');
                }

                return new MockResponse('{}');
            },
        ];
    }

    /**
     * A clean run may retire the bulk file - but only if OpenAI confirms it is gone. An
     * unconfirmed deletion that cleared the id would leave a superset document answering
     * alongside every per-page document, with nothing left to identify it by.
     */
    public function testAnUnconfirmedLegacyDeletionKeepsTheIdForTheNextRun(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);

        $client = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('DELETE' === $method && str_contains($url, 'legacy_bulk_file')) {
                    return new MockResponse('{}', ['http_code' => 500]);
                }

                if ('GET' === $method) {
                    return new MockResponse('{"status":"completed"}');
                }

                return new MockResponse('{}');
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('new content')],
            'legacy_bulk_file',
        );

        $this->assertSame(0, $stats['files_failed'], 'The page itself synced fine.');
        $this->assertFalse($stats['legacy_file_removed']);
        $this->assertSame(1, $stats['deletes_pending'], 'The operator has to be told a stale document is still answering.');
    }

    public function testACleanRunRetiresTheLegacyBulkFileLast(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);

        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('GET' === $method) {
                    return new MockResponse('{"status":"completed"}');
                }

                return new MockResponse('{}');
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('new content')],
            'legacy_bulk_file',
        );

        $this->assertSame(1, $stats['added']);
        $this->assertTrue($stats['legacy_file_removed']);
        $this->assertSame(0, $stats['deletes_pending']);

        $upload = array_search('POST https://api.openai.com/v1/files', $requests, true);
        $legacyDelete = array_search('DELETE https://api.openai.com/v1/vector_stores/vs_123/files/legacy_bulk_file', $requests, true);

        $this->assertIsInt($upload);
        $this->assertIsInt($legacyDelete);
        $this->assertGreaterThan($upload, $legacyDelete, 'The replacement must exist before the file it replaces is deleted.');
    }

    /**
     * An OpenAI error body carries {"error": ...} and no "status" key. Defaulting a missing
     * status to "completed" meant every failed status check was recorded as a permanent
     * success on a row later runs would never revisit.
     */
    #[DataProvider('provideInconclusiveIngestionResponses')]
    public function testInconclusiveIngestionIsRecordedAsProcessing(callable $ingestionResponse): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);

        $client = new MockHttpClient(
            static function (string $method, string $url) use ($ingestionResponse): MockResponse {
                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('GET' === $method) {
                    return $ingestionResponse();
                }

                return new MockResponse('{}');
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('new content')],
        );

        $this->assertSame(0, $stats['files_failed'], 'The file is attached; only its ingestion is unconfirmed.');
        $this->assertSame('processing', $rows[0]['status']);
    }

    /**
     * @return iterable<string, array{callable(): MockResponse}>
     */
    public static function provideInconclusiveIngestionResponses(): iterable
    {
        yield 'error body with no status field' => [static fn (): MockResponse => new MockResponse('{"error":{"message":"server error"}}', ['http_code' => 500])];
        yield 'unauthorised' => [static fn (): MockResponse => new MockResponse('{"error":{"message":"bad key"}}', ['http_code' => 401])];
        yield 'transport failure' => [static fn (): MockResponse => new MockResponse('', ['error' => 'connection reset'])];
        yield 'unknown remote state' => [static fn (): MockResponse => new MockResponse('{"status":"cancelled"}')];
        yield 'empty body on 200' => [static fn (): MockResponse => new MockResponse('{}')];
    }

    /**
     * A file still ingesting when the wait budget expires is attached and will probably
     * finish - but "probably" is not a state to store as done.
     */
    public function testAFileStillIngestingAtTheDeadlineIsRecordedAsProcessing(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);

        $client = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"new_file"}');
                }

                if ('GET' === $method) {
                    return new MockResponse('{"status":"in_progress"}');
                }

                return new MockResponse('{}');
            },
        );

        $sync = new SleeplessVectorStoreFileSync($connection, $client, new NullLogger());
        $stats = $sync->sync('sk-test', 'vs_123', 7, [$this->page('new content')]);

        $this->assertSame(0, $stats['files_failed']);
        $this->assertSame('processing', $rows[0]['status']);
        $this->assertNotSame([], $sync->pauses, 'It must actually have polled while waiting.');
    }

    /**
     * The saving that makes "processing" affordable: a page whose ingestion finished after we
     * stopped waiting is settled with one GET, not re-uploaded from scratch.
     */
    public function testAProcessingRowThatFinishedIngestingIsSettledWithoutReUploading(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'new_file', hash('sha256', 'same content'), 'processing');

        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                if ('GET' === $method) {
                    return new MockResponse('{"status":"completed"}');
                }

                self::fail('Unexpected request: '.$method.' '.$url);
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('same content')],
        );

        $this->assertSame(1, $stats['unchanged'], 'Ingestion completed, so there is nothing to re-upload.');
        $this->assertSame(0, $stats['files_uploaded']);
        $this->assertSame('uploaded', $rows[0]['status']);
        $this->assertSame(['GET https://api.openai.com/v1/vector_stores/vs_123/files/new_file'], $requests);
    }

    /**
     * The case the old code could never reach: ingestion that fails server-side AFTER the
     * sync stopped looking. The row has to come back as re-uploadable.
     */
    public function testAProcessingRowThatFailedServerSideIsReUploaded(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'broken_file', hash('sha256', 'same content'), 'processing');

        $client = new MockHttpClient(
            static function (string $method, string $url): MockResponse {
                if ('GET' === $method && str_ends_with($url, '/broken_file')) {
                    return new MockResponse('{"status":"failed","last_error":{"message":"could not parse"}}');
                }

                if ('POST' === $method && 'https://api.openai.com/v1/files' === $url) {
                    return new MockResponse('{"id":"fresh_file"}');
                }

                if ('GET' === $method && str_ends_with($url, '/fresh_file')) {
                    return new MockResponse('{"status":"completed"}');
                }

                return new MockResponse('{}');
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))->sync(
            'sk-test',
            'vs_123',
            7,
            [$this->page('same content')],
        );

        $this->assertSame(0, $stats['unchanged'], 'A failed ingestion must not count as an up-to-date page.');
        $this->assertSame(1, $stats['updated']);
        $this->assertSame(['fresh_file'], array_column($rows, 'openai_file_id'));
        $this->assertSame('uploaded', $rows[0]['status']);
    }

    /**
     * The deletion half of reconciliation has to work without an upload set: sync() aborts on
     * an empty search index, and the removals that matter most for privacy are exactly the
     * ones that would abort with it.
     */
    public function testRemovePagesDeletesTrackedDocumentsWithoutAnUploadSet(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'protected_file', hash('sha256', 'members only'), 'uploaded');

        $requests = [];
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$requests): MockResponse {
                $requests[] = $method.' '.$url;

                return new MockResponse('{}');
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))
            ->removePages('sk-test', 'vs_123', 7, [42])
        ;

        $this->assertSame(['removed' => 1, 'deletes_pending' => 0], $stats);
        $this->assertSame([], $rows);
        $this->assertSame(
            [
                'DELETE https://api.openai.com/v1/vector_stores/vs_123/files/protected_file',
                'DELETE https://api.openai.com/v1/files/protected_file',
            ],
            $requests,
        );
    }

    /**
     * Same confirmed-outcome contract as sync(): an unconfirmed deletion keeps its handle.
     */
    public function testRemovePagesKeepsAnUnconfirmedDeletionPending(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'protected_file', hash('sha256', 'members only'), 'uploaded');

        $client = new MockHttpClient(
            static fn (): MockResponse => new MockResponse('{}', ['http_code' => 401]),
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))
            ->removePages('sk-test', 'vs_123', 7, [42])
        ;

        $this->assertSame(['removed' => 0, 'deletes_pending' => 1], $stats);
        $this->assertCount(1, $rows);
        $this->assertSame('pending_delete', $rows[0]['status']);
    }

    public function testRemovePagesIgnoresPagesItDoesNotTrack(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'kept_file', hash('sha256', 'public'), 'uploaded');

        $client = new MockHttpClient(
            static fn (): MockResponse => self::fail('A page with no tracked files must not cause any request.'),
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))
            ->removePages('sk-test', 'vs_123', 7, [99])
        ;

        $this->assertSame(['removed' => 0, 'deletes_pending' => 0], $stats);
        $this->assertCount(1, $rows, 'The untouched page keeps its document.');
    }

    /**
     * The orchestrator removes authoritatively-gone pages before calling sync(), and sync()
     * then retries every pending deletion. A file that just failed must not be attempted a
     * second time in the same run: that repeats the whole retry ladder for nothing, and
     * reports one stuck file as two.
     */
    public function testAFileAlreadyAttemptedThisRunIsNotRetriedAgain(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'stuck_file', hash('sha256', 'gone'), 'uploaded');

        $attempts = 0;
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$attempts): MockResponse {
                if (str_contains($url, 'stuck_file')) {
                    ++$attempts;
                }

                return new MockResponse('{}', ['http_code' => 401]);
            },
        );

        $sync = new SleeplessVectorStoreFileSync($connection, $client, new NullLogger());

        // The orchestrator's pass: fails, so the row becomes pending_delete.
        $removed = $sync->removePages('sk-test', 'vs_123', 7, [42]);
        $this->assertSame(1, $removed['deletes_pending']);
        $this->assertSame('pending_delete', $rows[0]['status']);

        $afterFirstPass = $attempts;
        $this->assertGreaterThan(0, $afterFirstPass);

        // sync() runs next in the same process and must not try the same file again.
        $stats = $sync->sync('sk-test', 'vs_123', 7, []);

        $this->assertSame($afterFirstPass, $attempts, 'The file must not be attempted twice in one run.');
        $this->assertSame(1, $stats['deletes_pending'], 'One stuck file is reported once, not twice.');
        $this->assertSame('pending_delete', $rows[0]['status'], 'It stays tracked for the NEXT run.');
    }

    /**
     * A fresh process (the next cron tick) has no memory of the earlier attempt, so the
     * deletion really is retried rather than skipped forever.
     */
    public function testTheNextRunRetriesAFileSkippedEarlier(): void
    {
        $rows = [];
        $connection = $this->createConnection($rows);
        $this->insertVectorFile($rows, 'stuck_file', hash('sha256', 'gone'), 'pending_delete');

        $attempts = 0;
        $client = new MockHttpClient(
            static function (string $method, string $url) use (&$attempts): MockResponse {
                ++$attempts;

                return new MockResponse('{}');
            },
        );

        $stats = (new SleeplessVectorStoreFileSync($connection, $client, new NullLogger()))
            ->sync('sk-test', 'vs_123', 7, [])
        ;

        $this->assertSame(2, $attempts, 'Detach and delete are both attempted afresh.');
        $this->assertSame(0, $stats['deletes_pending']);
        $this->assertSame([], $rows);
    }

    /**
     * Consume a normalized multipart request body so the test can assert on the part
     * headers (Symfony hands the callback a chunk generator, not a plain string).
     *
     * @param array<string, mixed> $options
     */
    private function readBody(array $options): string
    {
        $body = $options['body'] ?? '';

        if (!\is_callable($body)) {
            return (string) $body;
        }

        $result = '';

        while ('' !== ($chunk = (string) $body(8192))) {
            $result .= $chunk;
        }

        return $result;
    }

    /**
     * An in-memory stand-in for tl_openai_vector_file.
     *
     * It has to understand the statements rather than record them: the service now keeps a
     * "pending_delete" row as its retry handle for a deletion OpenAI did not confirm, and a
     * fake that ignored the WHERE clause would hand loadState() rows it must never see and
     * report deletions that never happened - passing the very tests that exist to catch that.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function createConnection(array &$rows, bool $failInserts = false): Connection
    {
        $nextId = 1;

        $matches = static fn (array $row, array $criteria): bool => [] === array_filter(
            $criteria,
            static fn (mixed $value, string $column): bool => ($row[$column] ?? null) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

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
                static function (string $sql, array $params) use (&$rows): array {
                    // loadRowsWithStatus(): rows in one specific status, with their primary
                    // key - the retry handles for pending deletions and unfinished ingestion.
                    if (str_contains($sql, 'SELECT id, page_id, openai_file_id')) {
                        $status = $params[1];

                        return array_values(array_map(
                            static fn (array $row): array => [
                                'id' => $row['id'],
                                'page_id' => $row['page_id'],
                                'openai_file_id' => $row['openai_file_id'],
                            ],
                            array_filter($rows, static fn (array $row): bool => $row['status'] === $status),
                        ));
                    }

                    // loadState(): everything EXCEPT the retry rows, which track a file on
                    // its way out and must not look like a page's live document.
                    return array_values(array_map(
                        static fn (array $row): array => [
                            'page_id' => $row['page_id'],
                            'content_hash' => $row['content_hash'],
                            'title' => $row['title'],
                            'url' => $row['url'],
                            'status' => $row['status'],
                            'openai_file_id' => $row['openai_file_id'],
                        ],
                        array_filter($rows, static fn (array $row): bool => 'pending_delete' !== $row['status']),
                    ));
                },
            )
        ;
        $connection
            ->method('insert')
            ->willReturnCallback(
                static function (string $table, array $data) use (&$rows, &$nextId, $failInserts): int {
                    self::assertSame('tl_openai_vector_file', $table);
                    if ($failInserts) {
                        throw new \RuntimeException('Simulated insert failure.');
                    }

                    // Column defaults, so a minimal pending_delete insert looks like a real
                    // row. The union operator keeps the written columns in their own order,
                    // the way a real row reads.
                    $rows[] = ['id' => $nextId++] + $data + [
                        'url' => '',
                        'title' => '',
                        'language' => '',
                        'search_checksum' => '',
                        'content_hash' => '',
                        'chunk_index' => 0,
                        'chunk_count' => 1,
                        'openai_file_id' => '',
                        'bytes' => 0,
                        'status' => '',
                        'last_error' => null,
                    ];

                    return 1;
                },
            )
        ;
        $connection
            ->method('update')
            ->willReturnCallback(
                static function (string $table, array $data, array $criteria) use (&$rows, $matches): int {
                    self::assertSame('tl_openai_vector_file', $table);
                    $updated = 0;

                    foreach ($rows as $index => $row) {
                        if ($matches($row, $criteria)) {
                            $rows[$index] = array_merge($row, $data);
                            ++$updated;
                        }
                    }

                    return $updated;
                },
            )
        ;
        $connection
            ->method('delete')
            ->willReturnCallback(
                static function (string $table, array $criteria) use (&$rows, $matches): int {
                    self::assertSame('tl_openai_vector_file', $table);
                    $before = \count($rows);
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => !$matches($row, $criteria),
                    ));

                    return $before - \count($rows);
                },
            )
        ;
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params) use (&$rows): int {
                    // "DELETE ... WHERE pid = ? AND page_id = ? AND status != ?" - the guarded
                    // cleanup that must step around a pending_delete row.
                    self::assertStringContainsString('DELETE FROM tl_openai_vector_file', $sql);
                    self::assertStringContainsString('status != ?', $sql);

                    [$pid, $pageId, $keepStatus] = $params;
                    $before = \count($rows);
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $row): bool => $row['pid'] !== $pid
                            || $row['page_id'] !== $pageId
                            || $row['status'] === $keepStatus,
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
            'id' => \count($rows) + 1000,
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
