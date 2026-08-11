<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Migration;

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Migration\Version20260416000001CleanupOrphanAssistants;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The legacy assistant id is the only handle on the remote object, and shouldRun() fires
 * solely on rows that still carry one - so clearing it is what retires a row for good. These
 * tests pin when that is allowed to happen.
 */
class CleanupOrphanAssistantsTest extends TestCase
{
    #[DataProvider('provideConclusiveOutcomes')]
    public function testAConclusiveOutcomeClearsTheReference(int $httpStatus): void
    {
        $cleared = [];
        $migration = $this->createMigration(
            new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => $httpStatus])),
            $cleared,
        );

        $migration->run();

        $this->assertSame([7], $cleared, 'The assistant is provably gone, so the row is done with.');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideConclusiveOutcomes(): iterable
    {
        yield 'deleted' => [200];
        yield 'already gone' => [404];
        yield 'gone permanently' => [410];
    }

    #[DataProvider('provideInconclusiveOutcomes')]
    public function testAnInconclusiveOutcomeKeepsTheReferenceForTheNextRun(MockResponse $response): void
    {
        $cleared = [];
        $migration = $this->createMigration(
            new MockHttpClient(static fn (): MockResponse => $response),
            $cleared,
        );

        $result = $migration->run();

        $this->assertSame([], $cleared, 'Nothing was established, so the row must stay retryable.');
        $this->assertTrue($result->isSuccessful(), 'The migration still must not fail the whole update.');
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function provideInconclusiveOutcomes(): iterable
    {
        // A revoked or wrong key says nothing about whether the assistant exists. It used to
        // be bucketed with "already gone", which retired the row on an answer about the key.
        yield 'revoked key' => [new MockResponse('{}', ['http_code' => 401])];
        yield 'server error' => [new MockResponse('{}', ['http_code' => 500])];
        yield 'rate limited' => [new MockResponse('{}', ['http_code' => 429])];
        yield 'transport failure' => [new MockResponse('', ['error' => 'connection reset'])];
    }

    /**
     * @param list<int> $cleared row ids whose openai_assistant_id was emptied
     */
    private function createMigration(MockHttpClient $http, array &$cleared): Version20260416000001CleanupOrphanAssistants
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAllAssociative')
            ->willReturn([['id' => 7, 'pid' => 1, 'openai_assistant_id' => 'asst_legacy']])
        ;
        $connection
            ->method('fetchAssociative')
            ->willReturn(['id' => 1, 'api_key' => 'encrypted'])
        ;
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params) use (&$cleared): int {
                    if (str_contains($sql, "openai_assistant_id = ''")) {
                        $cleared[] = (int) $params[0];
                    }

                    return 1;
                },
            )
        ;

        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('getApiKeyForConfig')->willReturn('sk-test');

        return new Version20260416000001CleanupOrphanAssistants(
            $connection,
            $http,
            $encryption,
            new NullLogger(),
        );
    }
}
