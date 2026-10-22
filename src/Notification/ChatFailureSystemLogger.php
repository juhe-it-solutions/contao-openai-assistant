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

use Contao\CoreBundle\Monolog\ContaoContext;
use JuheItSolutions\ContaoOpenaiAssistant\Controller\AiChatController;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Writes failed visitor chat requests to the Contao system log (System > System log).
 *
 * Until now these failures only reached var/logs, which administrators without server
 * access never see. This is always on and has no setting.
 *
 * The entries are English fixed texts built only from privacy-safe values: the website,
 * the reason, the HTTP status and OpenAI's error code. Never the exception message, which
 * can quote the visitor. The full-detail line in AiChatController stays in var/logs.
 *
 * Both paths do their cheap cache check before resolving the website, which queries the
 * database: once the daily budget is exhausted every further request lands here, and
 * during an attack that must cost one cache read, not a query.
 */
class ChatFailureSystemLogger
{
    /**
     * Entries per configuration and hour. After that one summary entry, then nothing until
     * the next hour; var/logs keeps every failure regardless.
     */
    private const HOURLY_CAP = 100;

    private const HOURLY_COUNTER_TTL = 4000;

    /**
     * The daily-limit key carries the date, so it only has to outlive its own UTC day.
     */
    private const DAILY_LIMIT_TTL = 90000;

    private const FUNC = AiChatController::class.'::send';

    private readonly \Closure $clock;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CacheItemPoolInterface $cache,
        private readonly NotificationSiteResolver $siteResolver,
        \Closure|null $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Never throws.
     *
     * @param array<string, mixed>|null $config null when no OpenAI configuration exists
     */
    public function logChatFailure(array|null $config, \Throwable $e, Request $request): void
    {
        try {
            $configId = null !== $config ? (int) ($config['id'] ?? 0) : 0;
            $now = $this->now();
            $counterKey = 'oaa_syslog_failures_'.$configId.'_'.$now->setTimezone(new \DateTimeZone('UTC'))->format('YmdH');

            // null = cache unavailable. Then the entry is written without a cap: losing
            // failure entries is worse than an uncapped log for the length of a cache
            // outage, and Contao purges tl_log after the log period anyway.
            $count = $this->readCounter($counterKey);

            if (null !== $count && $count > self::HOURLY_CAP) {
                return;
            }

            $site = $this->renderSite($this->siteResolver->resolve($request));

            if (self::HOURLY_CAP === $count) {
                $this->writeCounter($counterKey, $count + 1);

                $this->write(
                    'error',
                    \sprintf(
                        'Chatbot failures on %s: more than %d entries this hour. Further failures until %s are only written to var/logs.',
                        $site,
                        self::HOURLY_CAP,
                        $this->endOfHour($now)->format('H:i T'),
                    ),
                    ContaoContext::ERROR,
                    $request,
                );

                return;
            }

            $details = ChatFailureDetails::fromThrowable($e);
            $reason = $details->reason->value;

            if (null !== $details->httpStatus) {
                $reason .= ', HTTP '.$details->httpStatus;
            }

            if (null !== $details->openAiErrorCode) {
                $reason .= ', OpenAI error code '.$details->openAiErrorCode;
            }

            $this->write(
                'error',
                \sprintf(
                    'Chatbot could not answer a visitor on %s: %s.%s Details are in var/logs.',
                    $site,
                    $reason,
                    null === $config ? ' No OpenAI configuration exists.' : '',
                ),
                ContaoContext::ERROR,
                $request,
            );

            if (null !== $count) {
                $this->writeCounter($counterKey, $count + 1);
            }
        } catch (\Throwable $e) {
            $this->logFallback($e);
        }
    }

    /**
     * One entry per configuration and UTC budget day. Never throws.
     *
     * @param array<string, mixed> $config
     */
    public function logDailyLimitReached(array $config, int $dailyLimit, Request $request): void
    {
        try {
            $now = $this->now();
            $utcDay = $now->setTimezone(new \DateTimeZone('UTC'))->format('Ymd');
            $key = 'oaa_syslog_daily_limit_'.(int) ($config['id'] ?? 0).'_'.$utcDay;

            if (!$this->claim($key, $now)) {
                return;
            }

            $this->write(
                'warning',
                \sprintf(
                    'Chatbot reached its daily limit of %d messages on %s. Visitors get no answers until the limit resets at %s.',
                    $dailyLimit,
                    $this->renderSite($this->siteResolver->resolve($request)),
                    self::nextBudgetReset($now)->format('Y-m-d H:i T'),
                ),
                ContaoContext::GENERAL,
                $request,
            );
        } catch (\Throwable $e) {
            $this->logFallback($e);
        }
    }

    /**
     * The next UTC midnight (the budget day is UTC, see ChatRateLimiter), in the
     * installation's timezone.
     */
    public static function nextBudgetReset(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now
            ->setTimezone(new \DateTimeZone('UTC'))
            ->setTime(0, 0)
            ->modify('+1 day')
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
        ;
    }

    private function renderSite(ResolvedSite $site): string
    {
        $host = $site->host ?? '(unknown host)';
        $title = '' !== (string) $site->rootTitle ? $site->rootTitle : '(untitled)';

        return match ($site->case) {
            ResolvedSite::CASE_VERIFIED_DOMAIN => \sprintf('"%s" (%s)', $title, $site->rootDns),
            ResolvedSite::CASE_ROOT_WITHOUT_DOMAIN => \sprintf('"%s" (%s, unverified)', $title, $host),
            default => $host.' (unverified, no matching website root)',
        };
    }

    private function write(string $level, string $message, string $action, Request $request): void
    {
        $context = ['contao' => PrivacySafeContaoContext::create(self::FUNC, $action, $request)];

        $this->logger->log($level, $message, $context);
    }

    /**
     * The system log itself failed (database down, for one). Fall back to a plain line that
     * only reaches var/logs, never to another tl_log attempt.
     */
    private function logFallback(\Throwable $e): void
    {
        try {
            $this->logger->error('Chatbot failure could not be written to the system log ('.(new \ReflectionClass($e))->getShortName().')');
        } catch (\Throwable) {
            // Nothing left to report to.
        }
    }

    private function readCounter(string $key): int|null
    {
        try {
            $item = $this->cache->getItem($key);

            return $item->isHit() ? (int) $item->get() : 0;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Not atomic: concurrent requests may overshoot the cap by a few entries. The cap is a
     * safety net against flooding, not an exact count.
     */
    private function writeCounter(string $key, int $count): void
    {
        try {
            $item = $this->cache->getItem($key);
            $item->set($count);
            $item->expiresAfter(self::HOURLY_COUNTER_TTL);
            $this->cache->save($item);
        } catch (\Throwable) {
            // The entry is written already; a lost increment only loosens the cap.
        }
    }

    /**
     * Same claim as AdminNotifier: a broken cache means "do not write", so it can never turn
     * into one daily-limit entry per rejected request.
     */
    private function claim(string $key, \DateTimeImmutable $now): bool
    {
        try {
            $item = $this->cache->getItem($key);

            if ($item->isHit() && $now->getTimestamp() - (int) $item->get() < self::DAILY_LIMIT_TTL) {
                return false;
            }

            $item->set($now->getTimestamp());
            $item->expiresAfter(self::DAILY_LIMIT_TTL);

            if ($this->cache->save($item)) {
                return true;
            }
        } catch (\Throwable) {
            // Treated like a failed save below.
        }

        $this->logger->warning('Chatbot daily-limit system log entry skipped: throttle could not be stored');

        return false;
    }

    /**
     * When the hourly cap resets, in the installation's timezone. The counter key uses the
     * UTC hour, so the boundary is computed in UTC; that also holds for half-hour offsets.
     */
    private function endOfHour(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));

        return $utc
            ->setTime((int) $utc->format('H'), 0)
            ->modify('+1 hour')
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
        ;
    }

    private function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }
}
