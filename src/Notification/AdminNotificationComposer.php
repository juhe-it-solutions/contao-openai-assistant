<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Notification;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds subject and plain-text body of the administrator notifications.
 *
 * Pure apart from the translator: every value comes in as an argument, so the wording can
 * be tested line by line. The texts live in oaa_notify.xlf (domain contao_oaa_notify), one
 * key per line, so the language files hold no line breaks.
 *
 * Only the values listed in the data protection rules ever reach a mail: website, domain,
 * configuration, time, a fixed reason and, when known, the HTTP status and OpenAI's error
 * code. Every free-text value is sanitised again here, whatever the caller already did.
 */
class AdminNotificationComposer
{
    public const DOMAIN = 'contao_oaa_notify';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * Anything but "de" is English, including an empty value from a row saved before the
     * language field existed.
     */
    public static function normaliseLocale(mixed $language): string
    {
        return 'de' === $language ? 'de' : 'en';
    }

    public function buildSite(ResolvedSite $site, string $locale): NotificationSite
    {
        $host = null !== $site->host
            ? NotificationSiteResolver::sanitise($site->host, NotificationSiteResolver::MAX_HOST_LENGTH)
            : $this->t('unknown_host', [], $locale);

        if (ResolvedSite::CASE_NO_ROOT === $site->case) {
            $roots = null;

            if ([] !== $site->installationRootTitles) {
                $titles = array_map(fn (string $title): string => $this->title($title, $locale), $site->installationRootTitles);
                $roots = implode(', ', $titles).($site->moreInstallationRoots ? ', ...' : '');
            }

            return new NotificationSite(
                $host,
                $host,
                $this->t('page_name_unknown', [], $locale),
                $this->t('domain_unverified_no_root', [$host], $locale),
                $roots,
            );
        }

        $title = $this->title((string) $site->rootTitle, $locale);
        $pageNameLine = $this->t('page_name_value', [$title, (string) $site->rootId], $locale);

        if (ResolvedSite::CASE_VERIFIED_DOMAIN === $site->case) {
            $dns = NotificationSiteResolver::sanitise((string) $site->rootDns, NotificationSiteResolver::MAX_HOST_LENGTH);
            $url = ($site->useSsl ? 'https' : 'http').'://'.$dns;

            return new NotificationSite(
                $title.' ('.$dns.')',
                $this->t('site_intro_with_root', [$title, $url], $locale),
                $pageNameLine,
                $url,
            );
        }

        return new NotificationSite(
            $title.' ('.$host.')',
            $this->t('site_intro_with_root', [$title, $host], $locale),
            $pageNameLine,
            $this->t('domain_unverified_no_dns', [$host], $locale),
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{subject: string, body: string}
     */
    public function composeFailure(NotificationSite $site, array $config, ChatFailureDetails $details, \DateTimeImmutable $time, string $locale): array
    {
        $reason = $details->reason->value;

        $lines = [
            $this->t('intro_failure', [$site->siteIntro], $locale),
            '',
            $this->t('label_reason', [], $locale).': '.$this->t('reason_'.$reason, [], $locale),
            $this->t('label_action', [], $locale).': '.$this->t('action_'.$reason, [], $locale),
            '',
            ...$this->detailLines($site, $config, $time, $locale),
        ];

        if (null !== $details->httpStatus) {
            $lines[] = $this->t('label_http_status', [], $locale).': '.$details->httpStatus;
        }

        if (null !== $details->openAiErrorCode) {
            $lines[] = $this->t('label_error_code', [], $locale).': '.NotificationSiteResolver::sanitise($details->openAiErrorCode, 64);
        }

        $lines[] = '';
        $lines[] = $this->t('throttle_failure', [], $locale);
        $lines[] = $this->t('log_hint', [], $locale);

        return [
            'subject' => $this->t('subject_failure', [$site->siteLabel], $locale),
            'body' => $this->body($lines, $locale),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{subject: string, body: string}
     */
    public function composeDailyLimit(NotificationSite $site, array $config, int $dailyLimit, \DateTimeImmutable $time, string $locale): array
    {
        $reset = ChatFailureSystemLogger::nextBudgetReset($time)->format('Y-m-d H:i T');

        $lines = [
            $this->t('daily_intro', [$site->siteIntro, (string) $dailyLimit], $locale),
            '',
            $this->t('daily_explain', [$reset], $locale),
            '',
            $this->t('label_action', [], $locale).': '.$this->t('daily_action', [], $locale),
            '',
            ...$this->detailLines($site, $config, $time, $locale),
            '',
            $this->t('daily_once', [], $locale),
        ];

        return [
            'subject' => $this->t('subject_daily_limit', [$site->siteLabel], $locale),
            'body' => $this->body($lines, $locale),
        ];
    }

    /**
     * @param list<string> $recipients
     *
     * @return array{subject: string, body: string}
     */
    public function composeTest(NotificationSite $site, array $recipients, string $locale): array
    {
        $lines = [
            $this->t('test_intro', [$site->siteIntro], $locale),
            '',
            ...$this->siteLines($site, $locale),
            $this->t('label_recipients', [], $locale).': '.implode(', ', $recipients),
            $this->t('label_language', [], $locale).': '.$this->t('language_'.$locale, [], $locale),
        ];

        return [
            'subject' => $this->t('subject_test', [$site->siteLabel], $locale),
            'body' => $this->body($lines, $locale),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function detailLines(NotificationSite $site, array $config, \DateTimeImmutable $time, string $locale): array
    {
        $title = $this->title((string) ($config['title'] ?? ''), $locale);
        $localTime = $time->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return [
            $this->t('label_details', [], $locale),
            ...$this->siteLines($site, $locale),
            $this->t('label_time', [], $locale).': '.$localTime->format('Y-m-d H:i:s T'),
            $this->t('label_config', [], $locale).': "'.$title.'" (ID '.(int) ($config['id'] ?? 0).')',
        ];
    }

    /**
     * @return list<string>
     */
    private function siteLines(NotificationSite $site, string $locale): array
    {
        $lines = [
            $this->t('label_page_name', [], $locale).': '.$site->pageNameLine,
            $this->t('label_domain', [], $locale).': '.$site->domainLine,
        ];

        if (null !== $site->installationRoots) {
            $lines[] = $this->t('label_installation_roots', [], $locale).': '.$site->installationRoots;
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function body(array $lines, string $locale): string
    {
        $lines[] = '';
        $lines[] = '-- ';
        $lines[] = $this->t('footer', [], $locale);

        return implode("\n", $lines)."\n";
    }

    private function title(string $title, string $locale): string
    {
        $title = NotificationSiteResolver::sanitise($title);

        return '' !== $title ? $title : $this->t('untitled', [], $locale);
    }

    /**
     * @param list<string> $parameters
     */
    private function t(string $key, array $parameters, string $locale): string
    {
        return $this->translator->trans('oaa_notify.'.$key, $parameters, self::DOMAIN, $locale);
    }
}
