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

use Contao\Config;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Monolog\ContaoContext;
use Contao\StringUtil;
use Contao\Validator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Emails the administrators of an installation when the chatbot cannot answer visitors or
 * the daily message limit is reached. Opt-in per configuration; off by default.
 *
 * Three rules shape everything here:
 *
 *   - It must never affect the visitor. Every public method catches everything, and the
 *     actual sending happens on kernel.terminate, after the response: the chat turn may
 *     already have used most of its 110-second budget, and a hanging mail server must not
 *     push the visitor past the browser's 120-second abort.
 *   - It must not flood. One failure email per hour and one daily-limit email per budget
 *     day and configuration, claimed in the cache BEFORE anything else happens - so a
 *     misconfiguration or a broken mail transport also costs one attempt per window, and
 *     during an attack each rejected request costs one cache read, never a query.
 *   - No visitor data. Only the website, the reason and OpenAI's status/error code are
 *     mailed (AdminNotificationComposer); never message text or an exception message.
 *
 * Recipients are only ever the addresses entered in the configuration. There is
 * deliberately no fallback to the administrator email: agencies often put their
 * customer's address there.
 */
#[AsEventListener(KernelEvents::TERMINATE, method: 'sendPending')]
class AdminNotifier implements ResetInterface
{
    public const MAX_RECIPIENTS = 10;

    private const FAILURE_WINDOW = 3600;

    /**
     * The daily key carries the UTC date, so it only has to outlive its own day.
     */
    private const DAILY_LIMIT_WINDOW = 90000;

    private const TEST_WINDOW = 30;

    /**
     * Mails composed during the request, sent on kernel.terminate. Each keeps the request
     * path for the system log entry, because the request is gone by then.
     *
     * @var list<array{email: Email, path: string, config_id: int}>
     */
    private array $pending = [];

    private readonly \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly CacheItemPoolInterface $cache,
        private readonly ContaoFramework $framework,
        private readonly NotificationSiteResolver $siteResolver,
        private readonly LoggerInterface $logger,
        private readonly AdminNotificationComposer $composer,
        \Closure|null $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Never throws. The mail itself goes out on kernel.terminate.
     *
     * @param array<string, mixed> $config
     */
    public function notifyChatFailure(array $config, \Throwable $e, Request $request): void
    {
        try {
            if (!self::isEnabled($config)) {
                return;
            }

            $now = ($this->clock)();

            if (!$this->claim('oaa_admin_notify_failure_'.self::configId($config), self::FAILURE_WINDOW, self::FAILURE_WINDOW + 60, $now)) {
                return;
            }

            $details = ChatFailureDetails::fromThrowable($e);

            $this->queue(
                $config,
                $request,
                __FUNCTION__,
                fn (NotificationSite $site, string $locale): array => $this->composer->composeFailure($site, $config, $details, $now, $locale),
            );
        } catch (\Throwable $exception) {
            $this->logSendFailure($exception, __FUNCTION__, PrivacySafeContaoContext::path($request));
        }
    }

    /**
     * Never throws. The mail itself goes out on kernel.terminate.
     *
     * @param array<string, mixed> $config
     */
    public function notifyDailyLimitReached(array $config, int $dailyLimit, Request $request): void
    {
        try {
            if (!self::isEnabled($config)) {
                return;
            }

            $now = ($this->clock)();
            $key = 'oaa_admin_notify_daily_limit_'.self::configId($config).'_'.$now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd');

            if (!$this->claim($key, self::DAILY_LIMIT_WINDOW, self::DAILY_LIMIT_WINDOW, $now)) {
                return;
            }

            $this->queue(
                $config,
                $request,
                __FUNCTION__,
                fn (NotificationSite $site, string $locale): array => $this->composer->composeDailyLimit($site, $config, $dailyLimit, $now, $locale),
            );
        } catch (\Throwable $exception) {
            $this->logSendFailure($exception, __FUNCTION__, PrivacySafeContaoContext::path($request));
        }
    }

    /**
     * Sent synchronously: the backend user waits for the result. Ignores the "enabled" flag
     * (the button only shows when it is ticked, and the saved state may lag behind the
     * form) and has its own throttle, so a test never uses up the failure or daily window.
     *
     * Problems go to var/logs only: the user sees the result directly.
     *
     * @param array<string, mixed> $config
     */
    public function sendTestNotification(array $config, Request $request): AdminNotificationTestResult
    {
        try {
            if (!$this->claim('oaa_admin_notify_test_'.self::configId($config), self::TEST_WINDOW, self::TEST_WINDOW + 60, ($this->clock)())) {
                return AdminNotificationTestResult::TooFrequent;
            }

            $recipients = self::recipients($config);

            if ([] === $recipients) {
                return AdminNotificationTestResult::NoRecipients;
            }

            $sender = $this->sender();

            if (null === $sender) {
                return AdminNotificationTestResult::NoSender;
            }

            $locale = AdminNotificationComposer::normaliseLocale($config['admin_notify_language'] ?? '');
            $site = $this->composer->buildSite($this->siteResolver->resolve($request), $locale);
            $message = $this->composer->composeTest($site, $recipients, $locale);

            $this->mailer->send($this->createEmail($sender, $recipients, $message['subject'], $message['body']));
            $this->logger->info('Administrator test notification sent', ['config_id' => self::configId($config), 'recipient_count' => \count($recipients)]);

            return AdminNotificationTestResult::Sent;
        } catch (\Throwable $e) {
            try {
                $this->logger->error('Administrator test notification could not be sent ('.self::shortName($e).')', ['config_id' => self::configId($config)]);
            } catch (\Throwable) {
            }

            return AdminNotificationTestResult::Failed;
        }
    }

    /**
     * kernel.terminate: send what the request queued, after the visitor has the response.
     *
     * On PHP-FPM Symfony has called fastcgi_finish_request() by now, so the visitor waits
     * for none of this. Each mail is attempted on its own, so one failure does not swallow
     * the next.
     */
    public function sendPending(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $item) {
            try {
                $this->mailer->send($item['email']);

                $this->logger->info(
                    'Administrator notification sent',
                    [
                        'config_id' => $item['config_id'],
                        'recipient_count' => \count($item['email']->getTo()),
                    ],
                );
            } catch (\Throwable $e) {
                $this->logSendFailure($e, __FUNCTION__, $item['path']);
            }
        }
    }

    /**
     * Long-running workers (FrankenPHP, RoadRunner) must not carry a mail into the next request.
     */
    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * The configured recipients: trimmed, validated, de-duplicated, at most ten.
     *
     * Re-validated although the backend already did, because the row may have been written
     * by something else (an import, an older version, a manual database edit).
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public static function recipients(array $config): array
    {
        $recipients = [];

        foreach (StringUtil::deserialize($config['admin_notify_recipients'] ?? null, true) as $address) {
            if (!\is_string($address)) {
                continue;
            }

            $address = trim($address);

            if ('' === $address || !self::isValidEmail($address)) {
                continue;
            }

            $recipients[strtolower($address)] ??= $address;
        }

        return \array_slice(array_values($recipients), 0, self::MAX_RECIPIENTS);
    }

    /**
     * Contao's validator plus an explicit "dot after the @": admin@localhost and
     * admin@example are not deliverable from a public website.
     */
    public static function isValidEmail(string $address): bool
    {
        return Validator::isEmail($address) && str_contains((string) substr($address, (int) strrpos($address, '@')), '.');
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function isEnabled(array $config): bool
    {
        // A missing column (bundle updated, database not yet migrated) means "disabled".
        return (bool) ($config['admin_notify_enabled'] ?? false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function configId(array $config): int
    {
        return (int) ($config['id'] ?? 0);
    }

    /**
     * Claim the throttle window, or report that it is taken.
     *
     * A cache that cannot store the claim counts as "taken": a broken cache must never turn
     * into one email per failed request.
     */
    private function claim(string $key, int $window, int $ttl, \DateTimeImmutable $now): bool
    {
        try {
            $item = $this->cache->getItem($key);

            if ($item->isHit() && $now->getTimestamp() - (int) $item->get() < $window) {
                return false;
            }

            $item->set($now->getTimestamp());
            $item->expiresAfter($ttl);

            if ($this->cache->save($item)) {
                return true;
            }
        } catch (\Throwable) {
            // Treated like a failed save below.
        }

        // var/logs only: this could happen on every request and must not flood tl_log.
        $this->logger->warning('Administrator notification skipped: throttle could not be stored');

        return false;
    }

    /**
     * Resolve recipients, sender and website, compose the mail and queue it for
     * kernel.terminate. Only reached after the throttle claim succeeded.
     *
     * @param array<string, mixed>                                                     $config
     * @param \Closure(NotificationSite, string): array{subject: string, body: string} $compose
     */
    private function queue(array $config, Request $request, string $method, \Closure $compose): void
    {
        $path = PrivacySafeContaoContext::path($request);
        $recipients = self::recipients($config);

        if ([] === $recipients) {
            $this->logSystemError('Chatbot notification email not sent: no valid recipient address is configured.', $method, $path);

            return;
        }

        $sender = $this->sender();

        if (null === $sender) {
            $this->logSystemError('Chatbot notification email not sent: no administrator email address is set in System > Settings.', $method, $path);

            return;
        }

        $locale = AdminNotificationComposer::normaliseLocale($config['admin_notify_language'] ?? '');
        $message = $compose($this->composer->buildSite($this->siteResolver->resolve($request), $locale), $locale);

        $this->pending[] = [
            'email' => $this->createEmail($sender, $recipients, $message['subject'], $message['body']),
            'path' => $path,
            'config_id' => self::configId($config),
        ];
    }

    /**
     * The global administrator email (System > Settings), never a recipient and never the
     * website root's admin email. Contao\Email would throw without one, too.
     */
    private function sender(): Address|null
    {
        $this->framework->initialize();

        $adminEmail = (string) $this->framework->getAdapter(Config::class)->get('adminEmail');

        if ('' === trim($adminEmail)) {
            return null;
        }

        [$name, $email] = StringUtil::splitFriendlyEmail($adminEmail);
        $email = trim((string) $email);

        if (!Validator::isEmail($email)) {
            return null;
        }

        return new Address($email, NotificationSiteResolver::sanitise((string) $name));
    }

    /**
     * @param list<string> $recipients
     */
    private function createEmail(Address $sender, array $recipients, string $subject, string $body): Email
    {
        $email = (new Email())
            ->from($sender)
            ->to(...$recipients)
            ->subject($subject)
            ->text($body)
        ;

        // Keep out-of-office and other auto-replies from coming back.
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');

        return $email;
    }

    /**
     * The exception's short class name only: transport messages can contain hostnames and
     * addresses.
     */
    private function logSendFailure(\Throwable $e, string $method, string|null $path): void
    {
        $this->logSystemError(
            \sprintf('Chatbot notification email could not be sent (%s). Check the mail settings of the installation.', self::shortName($e)),
            $method,
            $path,
        );
    }

    private function logSystemError(string $message, string $method, string|null $path): void
    {
        try {
            $this->logger->error($message, [
                'contao' => PrivacySafeContaoContext::createForPath(self::class.'::'.$method, ContaoContext::ERROR, $path),
            ]);
        } catch (\Throwable) {
            // The system log itself failed; a plain line reaches var/logs at least.
            try {
                $this->logger->error($message);
            } catch (\Throwable) {
            }
        }
    }

    private static function shortName(\Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName();
    }
}
