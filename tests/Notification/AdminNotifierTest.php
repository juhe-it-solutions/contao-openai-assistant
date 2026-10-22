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

use Contao\Config;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use JuheItSolutions\ContaoOpenaiAssistant\Exception\UnbilledRequestException;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotificationComposer;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotificationTestResult;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotifier;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureDetails;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\NotificationSiteResolver;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ResolvedSite;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Contracts\Translation\TranslatorInterface;

class AdminNotifierTest extends TestCase
{
    use CapturedLogTrait;
    use HostileInputTrait;

    private const CONFIG = [
        'id' => 1,
        'title' => 'Main config',
        'admin_notify_enabled' => '1',
        'admin_notify_recipients' => 'a:2:{i:0;s:17:"admin@example.com";i:1;s:18:"agency@example.org";}',
        'admin_notify_language' => 'en',
    ];

    private string $timezone;

    private \DateTimeImmutable $now;

    private TestHandler $logs;

    private RecordingMailer $mailer;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
        $this->now = new \DateTimeImmutable('2026-10-22 13:20:00', new \DateTimeZone('UTC'));
        $this->logs = new TestHandler();
        $this->mailer = new RecordingMailer();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    #[DataProvider('provideDisabledConfigs')]
    public function testDisabledNotificationsSendNothing(array $config): void
    {
        $resolver = $this->createMock(NotificationSiteResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('getAdapter');

        $notifier = $this->createNotifier(resolver: $resolver, framework: $framework);
        $notifier->notifyChatFailure($config, $this->quotaException(), $this->createRequest());
        $notifier->notifyDailyLimitReached($config, 5, $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);
        $this->assertSame([], self::capturedRecords($this->logs));
    }

    public static function provideDisabledConfigs(): iterable
    {
        yield 'flag off' => [['admin_notify_enabled' => ''] + self::CONFIG];
        yield 'flag zero' => [['admin_notify_enabled' => '0'] + self::CONFIG];
        yield 'column missing (not migrated yet)' => [array_diff_key(self::CONFIG, ['admin_notify_enabled' => true])];
    }

    public function testAFailureMailIsOnlySentAfterTheResponse(): void
    {
        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());

        $this->assertSame([], $this->mailer->sent, 'Nothing may be sent while the visitor waits.');

        $notifier->sendPending();

        $this->assertCount(1, $this->mailer->sent);
        $email = $this->mailer->sent[0];

        $this->assertSame(['admin@example.com', 'agency@example.org'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame('webmaster@example.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('Site Admin', $email->getFrom()[0]->getName());
        $this->assertSame('auto-generated', $email->getHeaders()->getHeaderBody('Auto-Submitted'));
        $this->assertSame('All', $email->getHeaders()->getHeaderBody('X-Auto-Response-Suppress'));
        $this->assertNull($email->getHtmlBody());
        $this->assertSame('[Chatbot] Main site (example.com): chatbot not available', $email->getSubject());
        $this->assertStringContainsString('Reason: OpenAI rejected the request because the OpenAI account has no credit or quota left.', (string) $email->getTextBody());
        $this->assertStringContainsString("HTTP status: 429\nOpenAI error code: insufficient_quota", (string) $email->getTextBody());
        $this->assertStringContainsString('Time: 2026-10-22 15:20:00 CEST', (string) $email->getTextBody());
        $this->assertStringContainsString('OpenAI configuration: "Main config" (ID 1)', (string) $email->getTextBody());
    }

    public function testFailureMailsAreThrottledToOnePerHour(): void
    {
        $clock = $this->now;
        $notifier = $this->createNotifier(clock: static function () use (&$clock): \DateTimeImmutable {
            return $clock;
        });

        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $clock = $this->now->modify('+59 minutes');
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertCount(1, $this->mailer->sent);

        $clock = $this->now->modify('+3600 seconds');
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertCount(2, $this->mailer->sent);
    }

    public function testDailyLimitMailsAreThrottledToOnePerUtcDay(): void
    {
        $clock = $this->now;
        $notifier = $this->createNotifier(clock: static function () use (&$clock): \DateTimeImmutable {
            return $clock;
        });

        $notifier->notifyDailyLimitReached(self::CONFIG, 500, $this->createRequest());
        $notifier->notifyDailyLimitReached(self::CONFIG, 500, $this->createRequest());
        // Independent of the failure throttle: both go out in the same hour.
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertCount(2, $this->mailer->sent);
        $this->assertSame('[Chatbot] Main site (example.com): daily message limit reached', $this->mailer->sent[0]->getSubject());
        $this->assertStringContainsString('has reached its daily limit of 500 messages', (string) $this->mailer->sent[0]->getTextBody());
        $this->assertStringContainsString('Until it resets at 2026-10-23 02:00 CEST', (string) $this->mailer->sent[0]->getTextBody());
        $this->assertSame('[Chatbot] Main site (example.com): chatbot not available', $this->mailer->sent[1]->getSubject());

        // Later the same UTC day: still nothing.
        $clock = new \DateTimeImmutable('2026-10-22 23:59:00', new \DateTimeZone('UTC'));
        $notifier->notifyDailyLimitReached(self::CONFIG, 500, $this->createRequest());
        $notifier->sendPending();
        $this->assertCount(2, $this->mailer->sent);

        $clock = new \DateTimeImmutable('2026-10-23 00:00:01', new \DateTimeZone('UTC'));
        $notifier->notifyDailyLimitReached(self::CONFIG, 500, $this->createRequest());
        $notifier->sendPending();
        $this->assertCount(3, $this->mailer->sent);
    }

    public function testACacheThatCannotStoreTheClaimSendsNothing(): void
    {
        $cache = new class() extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };

        $notifier = $this->createNotifier(cache: $cache);
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);

        $records = self::capturedRecords($this->logs);
        $this->assertCount(2, $records);
        $this->assertSame('WARNING', $records[0]['level']);
        $this->assertSame('Administrator notification skipped: throttle could not be stored', $records[0]['message']);
        $this->assertArrayNotHasKey('contao', $records[0]['context'], 'Must not reach tl_log: it could happen on every request.');
    }

    public function testAThrowingCacheSendsNothing(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willThrowException(new \RuntimeException('cache down'));

        $notifier = $this->createNotifier(cache: $cache);
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);
        $this->assertArrayNotHasKey('contao', self::capturedRecords($this->logs)[0]['context']);
    }

    public function testWithoutValidRecipientsNothingIsSentAndTheSystemLogSaysSo(): void
    {
        $config = ['admin_notify_recipients' => serialize(['admin@localhost', 'no-at-sign', ''])] + self::CONFIG;

        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure($config, $this->quotaException(), $this->createRequest());
        $notifier->notifyChatFailure($config, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);

        $records = self::capturedRecords($this->logs);
        $this->assertCount(1, $records, 'The second occurrence within the hour is throttled.');
        $this->assertSame('Chatbot notification email not sent: no valid recipient address is configured.', $records[0]['message']);
        $this->assertPrivacySafeSystemLogContext($records[0], 'notifyChatFailure');
    }

    #[DataProvider('provideBrokenSenders')]
    public function testWithoutAValidSenderNothingIsSentAndThereIsNoFallback(string|null $adminEmail): void
    {
        $notifier = $this->createNotifier(framework: $this->mockFramework($adminEmail));
        $notifier->notifyDailyLimitReached(self::CONFIG, 5, $this->createRequest());
        $notifier->notifyDailyLimitReached(self::CONFIG, 5, $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);

        $records = self::capturedRecords($this->logs);
        $this->assertCount(1, $records);
        $this->assertSame('Chatbot notification email not sent: no administrator email address is set in System > Settings.', $records[0]['message']);
        $this->assertPrivacySafeSystemLogContext($records[0], 'notifyDailyLimitReached');
    }

    public static function provideBrokenSenders(): iterable
    {
        yield 'not set' => [null];
        yield 'empty' => [''];
        yield 'invalid' => ['not-an-address'];
        yield 'invalid friendly format' => ['Site Admin <broken>'];
    }

    public function testAFailingTransportDoesNotEscapeAndOnlyTheClassNameIsLogged(): void
    {
        $this->mailer->failures = [new TransportException('Connection to smtp.internal.example:25 refused for admin@example.com')];

        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $records = self::capturedRecords($this->logs);
        $this->assertCount(1, $records);
        $this->assertSame('Chatbot notification email could not be sent (TransportException). Check the mail settings of the installation.', $records[0]['message']);
        $this->assertStringNotContainsString('smtp.internal', print_r($records[0]['context'], true));
        $this->assertPrivacySafeSystemLogContext($records[0], 'sendPending');
    }

    public function testAThrowingTranslatorDoesNotEscape(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willThrowException(new \InvalidArgumentException('broken catalogue'));

        $notifier = $this->createNotifier(composer: new AdminNotificationComposer($translator));
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);
        $this->assertSame('Chatbot notification email could not be sent (InvalidArgumentException). Check the mail settings of the installation.', self::capturedRecords($this->logs)[0]['message']);
        $this->assertPrivacySafeSystemLogContext(self::capturedRecords($this->logs)[0], 'notifyChatFailure');
    }

    public function testAThrowingResolverDoesNotEscape(): void
    {
        $resolver = $this->createMock(NotificationSiteResolver::class);
        $resolver->method('resolve')->willThrowException(new \RuntimeException('database down'));

        $notifier = $this->createNotifier(resolver: $resolver);
        $notifier->notifyDailyLimitReached(self::CONFIG, 5, $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);
        $this->assertSame('Chatbot notification email could not be sent (RuntimeException). Check the mail settings of the installation.', self::capturedRecords($this->logs)[0]['message']);
    }

    public function testTheSentLineDoesNotReachTheSystemLog(): void
    {
        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $records = self::capturedRecords($this->logs);
        $this->assertCount(1, $records);
        $this->assertSame('INFO', $records[0]['level']);
        $this->assertSame('Administrator notification sent', $records[0]['message']);
        $this->assertArrayNotHasKey('contao', $records[0]['context']);
        $this->assertSame(['config_id' => 1, 'recipient_count' => 2], $records[0]['context']);
    }

    public function testSendPendingEmptiesTheQueueAndAttemptsEveryMail(): void
    {
        $this->mailer->failures = [new TransportException('down')];

        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->notifyDailyLimitReached(self::CONFIG, 5, $this->createRequest());
        $notifier->sendPending();

        // The first mail failed, the second was still attempted.
        $this->assertCount(1, $this->mailer->sent);
        $this->assertStringContainsString('daily message limit reached', $this->mailer->sent[0]->getSubject());

        $notifier->sendPending();
        $this->assertCount(1, $this->mailer->sent, 'A second call must not send again.');
    }

    public function testResetDropsQueuedMailsWithoutSending(): void
    {
        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->reset();
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);
    }

    public function testAFailureAtTerminateIsLoggedWithTheKeptPath(): void
    {
        $this->mailer->failures = [new TransportException('down')];

        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), self::createHostileRequest());

        // By kernel.terminate the request stack is empty; the notifier never consults it.
        $notifier->sendPending();

        $context = self::capturedRecords($this->logs)[0]['context']['contao'];
        $this->assertInstanceOf(ContaoContext::class, $context);
        $this->assertSame('/ai-chat/send', $context->getUri());
        $this->assertSame('N/A', $context->getBrowser());
        $this->assertSame('N/A', $context->getUsername());
    }

    public function testWithTheThrottleTakenNeitherTheWebsiteNorTheDatabaseIsTouched(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('oaa_admin_notify_failure_1')->set($this->now->getTimestamp() - 10));
        $cache->save($cache->getItem('oaa_admin_notify_daily_limit_1_20261022')->set($this->now->getTimestamp() - 10));

        $resolver = $this->createMock(NotificationSiteResolver::class);
        $resolver->expects($this->never())->method('resolve');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('getAdapter');
        $framework->expects($this->never())->method('initialize');

        $notifier = $this->createNotifier(cache: $cache, resolver: $resolver, framework: $framework);
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->notifyDailyLimitReached(self::CONFIG, 5, $this->createRequest());
        $notifier->sendPending();

        $this->assertSame([], $this->mailer->sent);
    }

    #[DataProvider('provideWebsites')]
    public function testIdentifiesTheWebsite(ResolvedSite $site, string $subject, array $lines, array $absent): void
    {
        $notifier = $this->createNotifier(resolver: $this->mockResolver($site));
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertSame($subject, $this->mailer->sent[0]->getSubject());

        $body = (string) $this->mailer->sent[0]->getTextBody();

        foreach ($lines as $line) {
            $this->assertStringContainsString($line, $body);
        }

        foreach ($absent as $line) {
            $this->assertStringNotContainsString($line, $body);
        }
    }

    public static function provideWebsites(): iterable
    {
        yield 'A with SSL' => [
            new ResolvedSite(ResolvedSite::CASE_VERIFIED_DOMAIN, 'example.com', 1, 'Main site', 'example.com', true),
            '[Chatbot] Main site (example.com): chatbot not available',
            [
                'The chatbot on the website "Main site" (https://example.com) could not answer',
                'Website (page name): "Main site" (ID 1)',
                "Domain: https://example.com\n",
            ],
            ['unverified', 'Websites in this installation'],
        ];

        yield 'A without SSL' => [
            new ResolvedSite(ResolvedSite::CASE_VERIFIED_DOMAIN, 'example.com', 1, 'Main site', 'example.com', false),
            '[Chatbot] Main site (example.com): chatbot not available',
            ['(http://example.com)', "Domain: http://example.com\n"],
            ['https://'],
        ];

        yield 'B' => [
            new ResolvedSite(ResolvedSite::CASE_ROOT_WITHOUT_DOMAIN, 'example.com', 4, 'Main site'),
            '[Chatbot] Main site (example.com): chatbot not available',
            [
                'The chatbot on the website "Main site" (example.com) could not answer',
                'Website (page name): "Main site" (ID 4)',
                'Domain: example.com (unverified: no domain is set in this website root)',
            ],
            ['http://', 'https://', 'Websites in this installation'],
        ];

        yield 'C' => [
            new ResolvedSite(ResolvedSite::CASE_NO_ROOT, 'example.com', installationRootTitles: ['Main site', 'Shop']),
            '[Chatbot] example.com: chatbot not available',
            [
                'The chatbot on example.com could not answer',
                'Website (page name): unknown: no website root matches this domain',
                'Domain: example.com (unverified)',
                'Websites in this installation: Main site, Shop',
            ],
            ['http://', 'https://'],
        ];

        yield 'C with more than ten roots' => [
            new ResolvedSite(ResolvedSite::CASE_NO_ROOT, 'example.com', installationRootTitles: array_map(static fn (int $i): string => 'Site '.$i, range(1, 10)), moreInstallationRoots: true),
            '[Chatbot] example.com: chatbot not available',
            ['Websites in this installation: Site 1, Site 2, Site 3, Site 4, Site 5, Site 6, Site 7, Site 8, Site 9, Site 10, ...'],
            [],
        ];

        yield 'C with an invalid host' => [
            new ResolvedSite(ResolvedSite::CASE_NO_ROOT, null, installationRootTitles: ['Main site']),
            '[Chatbot] (unknown host): chatbot not available',
            ['The chatbot on (unknown host) could not answer', 'Domain: (unknown host) (unverified)'],
            [],
        ];

        yield 'empty page name' => [
            new ResolvedSite(ResolvedSite::CASE_VERIFIED_DOMAIN, 'example.com', 1, '', 'example.com', true),
            '[Chatbot] (untitled) (example.com): chatbot not available',
            ['Website (page name): "(untitled)" (ID 1)'],
            [],
        ];
    }

    #[DataProvider('provideLanguages')]
    public function testWritesInTheConfiguredLanguage(array $config, string $expectedLocale, string $subject): void
    {
        $translator = new XlfTranslator();

        $notifier = $this->createNotifier(composer: new AdminNotificationComposer($translator));
        $notifier->notifyChatFailure($config + self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();

        $this->assertSame($subject, $this->mailer->sent[0]->getSubject());

        foreach ($translator->calls as $call) {
            $this->assertSame($expectedLocale, $call['locale']);
            $this->assertSame('contao_oaa_notify', $call['domain']);
        }
    }

    public static function provideLanguages(): iterable
    {
        yield 'German' => [['admin_notify_language' => 'de'], 'de', '[Chatbot] Main site (example.com): Chatbot nicht verfügbar'];
        yield 'English' => [['admin_notify_language' => 'en'], 'en', '[Chatbot] Main site (example.com): chatbot not available'];
        yield 'empty falls back to English' => [['admin_notify_language' => ''], 'en', '[Chatbot] Main site (example.com): chatbot not available'];
        yield 'unknown falls back to English' => [['admin_notify_language' => 'fr'], 'en', '[Chatbot] Main site (example.com): chatbot not available'];
    }

    public function testNoVisitorDataReachesTheMailOrTheLogs(): void
    {
        $notifier = $this->createNotifier();
        $notifier->notifyChatFailure(self::CONFIG, self::hostileException(), self::createHostileRequest());
        $notifier->notifyDailyLimitReached(self::CONFIG, 5, self::createHostileRequest());
        $notifier->sendPending();

        $this->mailer->failures = [new TransportException('down')];
        $notifier->sendTestNotification(self::CONFIG, self::createHostileRequest());

        $this->assertCount(2, $this->mailer->sent);

        foreach ($this->mailer->sent as $email) {
            $this->assertNoVisitorData($email->getSubject().' '.$email->getTextBody().' '.$email->getHeaders()->toString());
        }

        foreach (self::capturedRecords($this->logs) as $record) {
            $this->assertNoVisitorData($this->dumpRecord($record));
        }

        // The classification itself still arrives.
        $this->assertStringContainsString('OpenAI error code: invalid_value', (string) $this->mailer->sent[0]->getTextBody());
    }

    public function testTheTestMailResults(): void
    {
        $clock = $this->now;
        $notifier = $this->createNotifier(clock: static function () use (&$clock): \DateTimeImmutable {
            return $clock;
        });

        $this->assertSame(AdminNotificationTestResult::Sent, $notifier->sendTestNotification(self::CONFIG, $this->createRequest()));
        $this->assertCount(1, $this->mailer->sent, 'The test mail is sent synchronously.');
        $this->assertSame('[Chatbot] Main site (example.com): test notification', $this->mailer->sent[0]->getSubject());
        $this->assertStringContainsString('Recipients: admin@example.com, agency@example.org', (string) $this->mailer->sent[0]->getTextBody());
        $this->assertStringContainsString('Language: English', (string) $this->mailer->sent[0]->getTextBody());

        $this->assertSame(AdminNotificationTestResult::TooFrequent, $notifier->sendTestNotification(self::CONFIG, $this->createRequest()));

        // The test ignores the enabled flag and never uses up the failure window.
        $notifier->notifyChatFailure(self::CONFIG, $this->quotaException(), $this->createRequest());
        $notifier->sendPending();
        $this->assertCount(2, $this->mailer->sent);
        $this->assertSame('[Chatbot] Main site (example.com): chatbot not available', $this->mailer->sent[1]->getSubject());

        $clock = $this->now->modify('+31 seconds');
        $disabled = ['admin_notify_enabled' => ''] + self::CONFIG;
        $this->assertSame(AdminNotificationTestResult::Sent, $notifier->sendTestNotification($disabled, $this->createRequest()));
    }

    public function testTheTestMailFailures(): void
    {
        $noRecipients = ['id' => 2, 'admin_notify_recipients' => null] + self::CONFIG;
        $this->assertSame(AdminNotificationTestResult::NoRecipients, $this->createNotifier()->sendTestNotification($noRecipients, $this->createRequest()));

        $this->assertSame(
            AdminNotificationTestResult::NoSender,
            $this->createNotifier(framework: $this->mockFramework(''))->sendTestNotification(['id' => 3] + self::CONFIG, $this->createRequest()),
        );

        $this->mailer->failures = [new TransportException('Connection refused')];
        $this->assertSame(AdminNotificationTestResult::Failed, $this->createNotifier()->sendTestNotification(['id' => 4] + self::CONFIG, $this->createRequest()));

        $this->assertSame([], $this->mailer->sent);

        // Test failures go to var/logs only: the backend user sees the result directly.
        foreach (self::capturedRecords($this->logs) as $record) {
            $this->assertArrayNotHasKey('contao', $record['context']);
        }
    }

    public function testTheTestMailDoesNotUseTheFailureOrDailyKeys(): void
    {
        $cache = new ArrayAdapter();

        $this->createNotifier(cache: $cache)->sendTestNotification(self::CONFIG, $this->createRequest());

        $this->assertSame(['oaa_admin_notify_test_1'], array_keys($cache->getValues()));
    }

    public function testRecipientsAreNormalised(): void
    {
        $config = ['admin_notify_recipients' => serialize([' Admin@Example.com ', 'admin@example.com', 'admin@example', 'x@y.co', ...array_map(static fn (int $i): string => 'r'.$i.'@example.com', range(1, 12))])];

        $recipients = AdminNotifier::recipients($config);

        $this->assertCount(10, $recipients);
        $this->assertSame(['Admin@Example.com', 'x@y.co', 'r1@example.com'], \array_slice($recipients, 0, 3));
        $this->assertSame([], AdminNotifier::recipients([]));
        $this->assertSame([], AdminNotifier::recipients(['admin_notify_recipients' => 'garbage']));
    }

    private function assertPrivacySafeSystemLogContext(array $record, string $method): void
    {
        $this->assertSame('ERROR', $record['level']);

        $context = $record['context']['contao'] ?? null;
        $this->assertInstanceOf(ContaoContext::class, $context);
        $this->assertSame(ContaoContext::ERROR, $context->getAction());
        $this->assertSame(AdminNotifier::class.'::'.$method, $context->getFunc());
        $this->assertSame('N/A', $context->getBrowser());
        $this->assertSame('N/A', $context->getUsername());
        $this->assertSame('/ai-chat/send', $context->getUri());
    }

    private function dumpRecord(array $record): string
    {
        $context = $record['context']['contao'] ?? null;

        return $record['message'].' '.print_r(array_diff_key($record['context'], ['contao' => true]), true).' '.($context instanceof ContaoContext
            ? implode(' ', [$context->getBrowser(), $context->getUsername(), $context->getUri(), $context->getIp(), $context->getFunc()])
            : '');
    }

    private function quotaException(): \Throwable
    {
        return new UnbilledRequestException('Responses API returned HTTP 429: quota', 0, null, ChatFailureDetails::fromHttpStatus(429, ['error' => ['code' => 'insufficient_quota']]));
    }

    private function createRequest(): Request
    {
        return Request::create('https://example.com/ai-chat/send', 'POST');
    }

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    private function createNotifier(CacheItemPoolInterface|null $cache = null, NotificationSiteResolver|null $resolver = null, ContaoFramework|null $framework = null, AdminNotificationComposer|null $composer = null, \Closure|null $clock = null): AdminNotifier
    {
        return new AdminNotifier(
            $this->mailer,
            $cache ?? new ArrayAdapter(),
            $framework ?? $this->mockFramework('Site Admin <webmaster@example.com>'),
            $resolver ?? $this->mockResolver(new ResolvedSite(ResolvedSite::CASE_VERIFIED_DOMAIN, 'example.com', 1, 'Main site', 'example.com', true)),
            new Logger('test', [$this->logs]),
            $composer ?? new AdminNotificationComposer(new XlfTranslator()),
            $clock ?? fn (): \DateTimeImmutable => $this->now,
        );
    }

    private function mockResolver(ResolvedSite $site): NotificationSiteResolver
    {
        $resolver = $this->createMock(NotificationSiteResolver::class);
        $resolver->method('resolve')->willReturn($site);

        return $resolver;
    }

    private function mockFramework(string|null $adminEmail): ContaoFramework
    {
        $config = $this->createMock(Adapter::class);
        $config
            ->method('__call')
            ->willReturnCallback(static fn (string $method, array $arguments): string|null => 'get' === $method && ['adminEmail'] === $arguments ? $adminEmail : null)
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(Config::class)->willReturn($config);

        return $framework;
    }
}
