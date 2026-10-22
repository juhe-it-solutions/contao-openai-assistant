<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Notification;

use Contao\CoreBundle\Monolog\ContaoContext;
use JuheItSolutions\ContaoOpenaiAssistant\Controller\AiChatController;
use JuheItSolutions\ContaoOpenaiAssistant\Exception\OpenAiRequestException;
use JuheItSolutions\ContaoOpenaiAssistant\Exception\UnbilledRequestException;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureDetails;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureReason;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureSystemLogger;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\NotificationSiteResolver;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ResolvedSite;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\HttpFoundation\Request;

class ChatFailureSystemLoggerTest extends TestCase
{
    use CapturedLogTrait;
    use HostileInputTrait;

    private string $timezone;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->now = new \DateTimeImmutable('2026-10-22 13:20:00', new \DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testAFailureWritesOnePrivacySafeErrorEntry(): void
    {
        $handler = new TestHandler();

        $this->createLogger($handler)->logChatFailure(['id' => 1], $this->quotaException(), $this->createRequest());

        $records = self::capturedRecords($handler);
        $this->assertCount(1, $records);
        $this->assertSame('ERROR', $records[0]['level']);

        $context = $records[0]['context']['contao'];
        $this->assertInstanceOf(ContaoContext::class, $context);
        $this->assertSame(ContaoContext::ERROR, $context->getAction());
        $this->assertSame(AiChatController::class.'::send', $context->getFunc());
        $this->assertSame('N/A', $context->getBrowser());
        $this->assertSame('N/A', $context->getUsername());
        $this->assertSame('/ai-chat/send', $context->getUri());
    }

    #[DataProvider('provideMessages')]
    public function testMessageFormat(ResolvedSite $site, array|null $config, \Throwable $e, string $expected): void
    {
        $handler = new TestHandler();

        $this->createLogger($handler, $site)->logChatFailure($config, $e, $this->createRequest());

        $this->assertSame($expected, self::capturedRecords($handler)[0]['message']);
    }

    public static function provideMessages(): iterable
    {
        $siteA = new ResolvedSite(ResolvedSite::CASE_VERIFIED_DOMAIN, 'example.com', 1, 'Main site', 'example.com', true);
        $siteB = new ResolvedSite(ResolvedSite::CASE_ROOT_WITHOUT_DOMAIN, 'example.com', 1, 'Main site');
        $siteC = new ResolvedSite(ResolvedSite::CASE_NO_ROOT, 'example.com', installationRootTitles: ['Main site']);
        $quota = new UnbilledRequestException('x', 0, null, ChatFailureDetails::fromHttpStatus(429, ['error' => ['code' => 'insufficient_quota']]));

        yield 'case A with status and code' => [
            $siteA,
            ['id' => 1],
            $quota,
            'Chatbot could not answer a visitor on "Main site" (example.com): quota_exceeded, HTTP 429, OpenAI error code insufficient_quota. Details are in var/logs.',
        ];

        yield 'case A with status only' => [
            $siteA,
            ['id' => 1],
            new OpenAiRequestException('x', ChatFailureDetails::fromHttpStatus(500, [])),
            'Chatbot could not answer a visitor on "Main site" (example.com): openai_unavailable, HTTP 500. Details are in var/logs.',
        ];

        yield 'case B without status' => [
            $siteB,
            ['id' => 1],
            new OpenAiRequestException('x', new ChatFailureDetails(ChatFailureReason::ConnectionFailed)),
            'Chatbot could not answer a visitor on "Main site" (example.com, unverified): connection_failed. Details are in var/logs.',
        ];

        yield 'case C without a configuration' => [
            $siteC,
            null,
            new UnbilledRequestException('No OpenAI configuration found', 0, null, new ChatFailureDetails(ChatFailureReason::NotConfigured)),
            'Chatbot could not answer a visitor on example.com (unverified, no matching website root): not_configured. No OpenAI configuration exists. Details are in var/logs.',
        ];

        yield 'case C with an invalid host' => [
            new ResolvedSite(ResolvedSite::CASE_NO_ROOT, null),
            ['id' => 1],
            new \LogicException('x'),
            'Chatbot could not answer a visitor on (unknown host) (unverified, no matching website root): unknown. Details are in var/logs.',
        ];

        yield 'empty page name' => [
            new ResolvedSite(ResolvedSite::CASE_VERIFIED_DOMAIN, 'example.com', 1, '', 'example.com'),
            ['id' => 1],
            new \LogicException('x'),
            'Chatbot could not answer a visitor on "(untitled)" (example.com): unknown. Details are in var/logs.',
        ];
    }

    public function testNoVisitorDataReachesTheSystemLog(): void
    {
        $handler = new TestHandler();

        $this->createLogger($handler)->logChatFailure(['id' => 1], self::hostileException(), self::createHostileRequest());
        $this->createLogger($handler)->logDailyLimitReached(['id' => 1], 5, self::createHostileRequest());

        $this->assertCount(2, self::capturedRecords($handler));

        foreach (self::capturedRecords($handler) as $record) {
            $this->assertNoVisitorData($this->dumpRecord($record));
        }
    }

    public function testTheHourlyCapWritesOneSummaryAndThenNothing(): void
    {
        $handler = new TestHandler();
        $cache = new ArrayAdapter();
        $clock = $this->now;
        $logger = $this->createLogger(
            $handler,
            null,
            $cache,
            static function () use (&$clock): \DateTimeImmutable {
                return $clock;
            }
        );

        for ($i = 1; $i <= 105; ++$i) {
            $logger->logChatFailure(['id' => 1], $this->quotaException(), $this->createRequest());
        }

        $records = self::capturedRecords($handler);
        $this->assertCount(101, $records);
        $this->assertStringStartsWith('Chatbot could not answer a visitor', $records[99]['message']);
        $this->assertSame(
            'Chatbot failures on "Main site" (example.com): more than 100 entries this hour. Further failures until 16:00 CEST are only written to var/logs.',
            $records[100]['message'],
        );
        $this->assertSame(ContaoContext::ERROR, $records[100]['context']['contao']->getAction());

        // A separate configuration has its own counter.
        $logger->logChatFailure(['id' => 2], $this->quotaException(), $this->createRequest());
        $this->assertCount(102, self::capturedRecords($handler));

        // The next UTC hour starts over.
        $clock = $this->now->modify('+1 hour');
        $logger->logChatFailure(['id' => 1], $this->quotaException(), $this->createRequest());
        $this->assertCount(103, self::capturedRecords($handler));
    }

    public function testOverTheCapTheWebsiteIsNotResolved(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('oaa_syslog_failures_1_2026102213');
        $cache->save($item->set(101));

        $resolver = $this->createMock(NotificationSiteResolver::class);
        $resolver
            ->expects($this->never())
            ->method('resolve')
        ;

        $handler = new TestHandler();
        $logger = new ChatFailureSystemLogger(new Logger('test', [$handler]), $cache, $resolver, fn (): \DateTimeImmutable => $this->now);
        $logger->logChatFailure(['id' => 1], $this->quotaException(), $this->createRequest());

        $this->assertCount(0, self::capturedRecords($handler));
    }

    public function testAnAlreadyClaimedDailyLimitEntryDoesNotResolveTheWebsite(): void
    {
        $cache = new ArrayAdapter();
        $item = $cache->getItem('oaa_syslog_daily_limit_1_20261022');
        $cache->save($item->set($this->now->getTimestamp() - 60));

        $resolver = $this->createMock(NotificationSiteResolver::class);
        $resolver
            ->expects($this->never())
            ->method('resolve')
        ;

        $handler = new TestHandler();
        $logger = new ChatFailureSystemLogger(new Logger('test', [$handler]), $cache, $resolver, fn (): \DateTimeImmutable => $this->now);
        $logger->logDailyLimitReached(['id' => 1], 5, $this->createRequest());

        $this->assertCount(0, self::capturedRecords($handler));
    }

    #[DataProvider('provideBrokenCaches')]
    public function testWithoutACacheTheEntryIsStillWritten(CacheItemPoolInterface $cache): void
    {
        $handler = new TestHandler();

        $this->createLogger($handler, null, $cache)->logChatFailure(['id' => 1], $this->quotaException(), $this->createRequest());

        $this->assertCount(1, self::capturedRecords($handler));
        $this->assertArrayHasKey('contao', self::capturedRecords($handler)[0]['context']);
    }

    public static function provideBrokenCaches(): iterable
    {
        $throwing = new class() extends ArrayAdapter {
            public function getItem(mixed $key): CacheItem
            {
                throw new \RuntimeException('cache down');
            }
        };

        $notSaving = new class() extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };

        yield 'pool throws' => [$throwing];
        yield 'save returns false' => [$notSaving];
    }

    public function testTheDailyLimitIsLoggedOncePerUtcDay(): void
    {
        $handler = new TestHandler();
        $clock = $this->now;
        $logger = $this->createLogger($handler, null, new ArrayAdapter(), static function () use (&$clock): \DateTimeImmutable {
            return $clock;
        });

        $logger->logDailyLimitReached(['id' => 1], 500, $this->createRequest());
        $logger->logDailyLimitReached(['id' => 1], 500, $this->createRequest());

        $records = self::capturedRecords($handler);
        $this->assertCount(1, $records);
        $this->assertSame('WARNING', $records[0]['level']);
        $this->assertSame(ContaoContext::GENERAL, $records[0]['context']['contao']->getAction());
        $this->assertSame(
            'Chatbot reached its daily limit of 500 messages on "Main site" (example.com). Visitors get no answers until the limit resets at 2026-10-23 02:00 CEST.',
            $records[0]['message'],
        );

        // Another configuration is logged separately.
        $logger->logDailyLimitReached(['id' => 2], 500, $this->createRequest());
        $this->assertCount(2, self::capturedRecords($handler));

        $clock = $this->now->modify('+1 day');
        $logger->logDailyLimitReached(['id' => 1], 500, $this->createRequest());
        $this->assertCount(3, self::capturedRecords($handler));
    }

    public function testAFailingSystemLogFallsBackToAPlainLine(): void
    {
        $plain = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->method('log')
            ->willThrowException(new \RuntimeException('tl_log is not writable'))
        ;
        $logger
            ->expects($this->exactly(2))
            ->method('error')
            ->willReturnCallback(
                static function (string $message, array $context = []) use (&$plain): void {
                    $plain[] = [$message, $context];
                }
            )
        ;

        $systemLogger = new ChatFailureSystemLogger($logger, new ArrayAdapter(), $this->mockResolver(), fn (): \DateTimeImmutable => $this->now);
        $systemLogger->logChatFailure(['id' => 1], $this->quotaException(), $this->createRequest());
        $systemLogger->logDailyLimitReached(['id' => 1], 5, $this->createRequest());

        $this->assertSame('Chatbot failure could not be written to the system log (RuntimeException)', $plain[0][0]);
        $this->assertSame([], $plain[0][1], 'The fallback must not carry a ContaoContext.');
    }

    #[DataProvider('provideNotificationSettings')]
    public function testDoesNotDependOnTheNotificationSetting(array $config): void
    {
        $handler = new TestHandler();

        $this->createLogger($handler)->logChatFailure($config, $this->quotaException(), $this->createRequest());

        $this->assertCount(1, self::capturedRecords($handler));
    }

    public static function provideNotificationSettings(): iterable
    {
        yield 'enabled' => [['id' => 1, 'admin_notify_enabled' => '1']];
        yield 'disabled' => [['id' => 1, 'admin_notify_enabled' => '']];
        yield 'column missing' => [['id' => 1]];
    }

    private function createLogger(TestHandler $handler, ResolvedSite|null $site = null, CacheItemPoolInterface|null $cache = null, \Closure|null $clock = null): ChatFailureSystemLogger
    {
        return new ChatFailureSystemLogger(
            new Logger('test', [$handler]),
            $cache ?? new ArrayAdapter(),
            $this->mockResolver($site),
            $clock ?? fn (): \DateTimeImmutable => $this->now,
        );
    }

    private function mockResolver(ResolvedSite|null $site = null): NotificationSiteResolver
    {
        $resolver = $this->createMock(NotificationSiteResolver::class);
        $resolver
            ->method('resolve')
            ->willReturn($site ?? new ResolvedSite(ResolvedSite::CASE_VERIFIED_DOMAIN, 'example.com', 1, 'Main site', 'example.com', true))
        ;

        return $resolver;
    }

    private function quotaException(): \Throwable
    {
        return new UnbilledRequestException('Responses API returned HTTP 429: quota', 0, null, ChatFailureDetails::fromHttpStatus(429, ['error' => ['code' => 'insufficient_quota']]));
    }

    private function createRequest(): Request
    {
        return Request::create('https://example.com/ai-chat/send', 'POST');
    }

    private function dumpRecord(array $record): string
    {
        $context = $record['context']['contao'] ?? null;

        return $record['message'].' '.print_r($record['context'], true).' '.($context instanceof ContaoContext
            ? implode(' ', [$context->getBrowser(), $context->getUsername(), $context->getUri(), $context->getIp(), $context->getFunc()])
            : '');
    }
}
