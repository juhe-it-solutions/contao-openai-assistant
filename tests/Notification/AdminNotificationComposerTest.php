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

use JuheItSolutions\ContaoOpenaiAssistant\Notification\AdminNotificationComposer;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureDetails;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ChatFailureReason;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\NotificationSite;
use JuheItSolutions\ContaoOpenaiAssistant\Notification\ResolvedSite;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class AdminNotificationComposerTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testFailureMailLineOrder(): void
    {
        $message = $this->createComposer()->composeFailure(
            $this->site(),
            ['id' => 7, 'title' => 'Main config'],
            ChatFailureDetails::fromHttpStatus(429, ['error' => ['code' => 'insufficient_quota']]),
            new \DateTimeImmutable('2026-10-22 13:20:05', new \DateTimeZone('UTC')),
            'en',
        );

        $this->assertSame('oaa_notify.subject_failure|LABEL', $message['subject']);
        $this->assertSame(
            [
                'oaa_notify.intro_failure|INTRO',
                '',
                'oaa_notify.label_reason: oaa_notify.reason_quota_exceeded',
                'oaa_notify.label_action: oaa_notify.action_quota_exceeded',
                '',
                'oaa_notify.label_details',
                'oaa_notify.label_page_name: PAGE',
                'oaa_notify.label_domain: DOMAIN',
                'oaa_notify.label_time: 2026-10-22 15:20:05 CEST',
                'oaa_notify.label_config: "Main config" (ID 7)',
                'oaa_notify.label_http_status: 429',
                'oaa_notify.label_error_code: insufficient_quota',
                '',
                'oaa_notify.throttle_failure',
                'oaa_notify.log_hint',
                '',
                '-- ',
                'oaa_notify.footer',
                '',
            ],
            explode("\n", $message['body']),
        );
    }

    public function testOptionalLinesOnlyAppearWhenKnown(): void
    {
        $body = $this->createComposer()->composeFailure(
            $this->site(),
            ['id' => 7, 'title' => 'Main config'],
            new ChatFailureDetails(ChatFailureReason::ConnectionFailed),
            new \DateTimeImmutable(),
            'en',
        )['body'];

        $this->assertStringContainsString('oaa_notify.reason_connection_failed', $body);
        $this->assertStringNotContainsString('label_http_status', $body);
        $this->assertStringNotContainsString('label_error_code', $body);
        $this->assertStringNotContainsString('label_installation_roots', $body);

        $withRoots = new NotificationSite('LABEL', 'INTRO', 'PAGE', 'DOMAIN', 'Main site, Shop');
        $body = $this->createComposer()->composeFailure($withRoots, [], new ChatFailureDetails(ChatFailureReason::Unknown), new \DateTimeImmutable(), 'en')['body'];

        $this->assertStringContainsString("oaa_notify.label_domain: DOMAIN\noaa_notify.label_installation_roots: Main site, Shop\noaa_notify.label_time", $body);
        $this->assertStringContainsString('oaa_notify.label_config: "oaa_notify.untitled" (ID 0)', $body);
    }

    public function testDailyLimitMailLineOrder(): void
    {
        $message = $this->createComposer()->composeDailyLimit(
            $this->site(),
            ['id' => 7, 'title' => 'Main config'],
            500,
            new \DateTimeImmutable('2026-10-22 13:20:05', new \DateTimeZone('UTC')),
            'en',
        );

        $this->assertSame('oaa_notify.subject_daily_limit|LABEL', $message['subject']);
        $this->assertSame(
            [
                'oaa_notify.daily_intro|INTRO|500',
                '',
                'oaa_notify.daily_explain|2026-10-23 02:00 CEST',
                '',
                'oaa_notify.label_action: oaa_notify.daily_action',
                '',
                'oaa_notify.label_details',
                'oaa_notify.label_page_name: PAGE',
                'oaa_notify.label_domain: DOMAIN',
                'oaa_notify.label_time: 2026-10-22 15:20:05 CEST',
                'oaa_notify.label_config: "Main config" (ID 7)',
                '',
                'oaa_notify.daily_once',
                '',
                '-- ',
                'oaa_notify.footer',
                '',
            ],
            explode("\n", $message['body']),
        );
    }

    public function testTestMailLineOrder(): void
    {
        $message = $this->createComposer()->composeTest($this->site(), ['a@example.com', 'b@example.com'], 'de');

        $this->assertSame('oaa_notify.subject_test|LABEL', $message['subject']);
        $this->assertSame(
            [
                'oaa_notify.test_intro|INTRO',
                '',
                'oaa_notify.label_page_name: PAGE',
                'oaa_notify.label_domain: DOMAIN',
                'oaa_notify.label_recipients: a@example.com, b@example.com',
                'oaa_notify.label_language: oaa_notify.language_de',
                '',
                '-- ',
                'oaa_notify.footer',
                '',
            ],
            explode("\n", $message['body']),
        );
    }

    public function testFreeTextIsSanitised(): void
    {
        $composer = $this->createComposer();

        $site = $composer->buildSite(
            new ResolvedSite(ResolvedSite::CASE_ROOT_WITHOUT_DOMAIN, "example.com\r\nBcc: x@example.org", 3, "Main\r\nsite\x07".str_repeat('x', 200)),
            'en',
        );

        $this->assertStringNotContainsString("\r", $site->siteLabel.$site->siteIntro.$site->pageNameLine.$site->domainLine);
        $this->assertStringNotContainsString("\n", $site->siteLabel.$site->siteIntro.$site->pageNameLine.$site->domainLine);
        $this->assertStringNotContainsString("\x07", $site->pageNameLine);
        $this->assertSame('oaa_notify.page_name_value|Main site '.str_repeat('x', 90).'|3', $site->pageNameLine);

        $body = $composer->composeFailure($site, ['id' => 1, 'title' => "Config\nBcc: evil@example.org\x00"], new ChatFailureDetails(ChatFailureReason::Unknown), new \DateTimeImmutable(), 'en')['body'];
        $this->assertStringContainsString('oaa_notify.label_config: "Config Bcc: evil@example.org" (ID 1)', $body);

        $rootsSite = $composer->buildSite(new ResolvedSite(ResolvedSite::CASE_NO_ROOT, 'example.com', installationRootTitles: ["Main\nsite", '']), 'en');
        $this->assertSame('Main site, oaa_notify.untitled', $rootsSite->installationRoots);
    }

    public function testTheResetTimeIsTheNextUtcMidnightInTheInstallationTimezone(): void
    {
        $winter = $this->createComposer()->composeDailyLimit($this->site(), ['id' => 1], 5, new \DateTimeImmutable('2026-12-01 23:59:00', new \DateTimeZone('UTC')), 'en');
        $this->assertStringContainsString('oaa_notify.daily_explain|2026-12-02 01:00 CET', $winter['body']);

        // 00:30 local time in Vienna is still the previous UTC day.
        $summer = $this->createComposer()->composeDailyLimit($this->site(), ['id' => 1], 5, new \DateTimeImmutable('2026-07-01 00:30:00', new \DateTimeZone('Europe/Vienna')), 'en');
        $this->assertStringContainsString('oaa_notify.daily_explain|2026-07-01 02:00 CEST', $summer['body']);
    }

    public function testEveryTranslationGetsTheRequestedLocaleAndDomain(): void
    {
        $translator = new XlfTranslator();
        $composer = new AdminNotificationComposer($translator);

        $composer->composeFailure($composer->buildSite(new ResolvedSite(ResolvedSite::CASE_NO_ROOT, null), 'de'), [], new ChatFailureDetails(ChatFailureReason::Unknown), new \DateTimeImmutable(), 'de');

        $this->assertNotEmpty($translator->calls);

        foreach ($translator->calls as $call) {
            $this->assertSame('contao_oaa_notify', $call['domain']);
            $this->assertSame('de', $call['locale']);
        }
    }

    public function testNormaliseLocale(): void
    {
        $this->assertSame('de', AdminNotificationComposer::normaliseLocale('de'));
        $this->assertSame('en', AdminNotificationComposer::normaliseLocale('en'));
        $this->assertSame('en', AdminNotificationComposer::normaliseLocale(''));
        $this->assertSame('en', AdminNotificationComposer::normaliseLocale('fr'));
        $this->assertSame('en', AdminNotificationComposer::normaliseLocale(null));
    }

    private function site(): NotificationSite
    {
        return new NotificationSite('LABEL', 'INTRO', 'PAGE', 'DOMAIN');
    }

    private function createComposer(): AdminNotificationComposer
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator
            ->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => implode('|', [$id, ...$parameters]))
        ;

        return new AdminNotificationComposer($translator);
    }
}
