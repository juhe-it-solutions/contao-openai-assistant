<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Abuse protection for the public, anonymous AI chat endpoint.
 *
 * The frontend chat is _allow_anonymous and spends the site owner's OpenAI credits on
 * every message, so a per-session throttle alone (bypassable by dropping the session
 * cookie) is not enough. Two independent, cache-backed limiters bound the worst case:
 *
 *   - a per-client-IP sliding window (stops trivial scripted bursts), and
 *   - a per-configuration fixed daily window (an absolute ceiling on how many
 *     completions one config can spend in a day, surviving a distributed attack).
 *
 * State lives in the shared application cache (cache.app), so limits hold across web
 * workers and requests. Both limiters fail closed only on their own key: exhausting one
 * IP or one config never affects another.
 */
class ChatRateLimiter
{
    /**
     * Default per-IP messages/minute, used when the config row does not carry a value
     * yet (pre-migration) or no config exists. Comfortable for real conversation
     * cadence; a hard stop on automated flooding. Operators of installations where
     * many users share one egress IP (corporate intranets, NAT, proxies) raise the
     * limit via tl_openai_config.chat_ip_rate_limit or disable it with 0.
     */
    public const DEFAULT_IP_LIMIT = 10;

    private const IP_INTERVAL = '1 minute';

    /**
     * The connection is optional so the IP limiter keeps working in contexts that have no
     * database wired; the daily cap then degrades to its cache-backed check.
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly Connection|null $connection = null,
    ) {
    }

    /**
     * Consume one token for the given client IP. Returns false when the IP is over its
     * per-minute limit and the request should be rejected (HTTP 429). A non-positive
     * limit disables IP limiting entirely (intranet/NAT setups where one IP is shared
     * by many legitimate users). The configured limit is applied at consume time, so
     * changing it in the backend takes effect immediately, mid-window.
     */
    public function acceptClientIp(string $clientIp, int $perMinuteLimit = self::DEFAULT_IP_LIMIT): bool
    {
        if ($perMinuteLimit <= 0) {
            return true;
        }

        $factory = new RateLimiterFactory(
            [
                'id' => 'oaa_chat_ip',
                'policy' => 'sliding_window',
                'limit' => $perMinuteLimit,
                'interval' => self::IP_INTERVAL,
            ],
            new CacheStorage($this->cache),
        );

        // A missing/unresolvable IP collapses to one shared bucket rather than bypassing
        // the limit entirely.
        $key = '' !== $clientIp ? $clientIp : 'unknown';

        return $factory->create($key)->consume(1)->isAccepted();
    }

    /**
     * Consume one token from the per-configuration daily budget. A non-positive limit
     * means "uncapped" (the operator disabled the ceiling). Returns false when the
     * config has already spent its daily allowance.
     */
    public function acceptConfigDaily(int $configId, int $dailyLimit): bool
    {
        if ($dailyLimit <= 0) {
            return true;
        }

        return $this->dailyLimiter($configId, $dailyLimit)->consume(1)->isAccepted();
    }

    /**
     * Atomically reserve one completion from today's budget.
     *
     * Returns false when the ceiling is already reached, in which case NOTHING was booked
     * and the caller must reject the request without calling OpenAI.
     *
     * This replaces a check-then-consume pair that was not atomic: the request asked "is
     * there budget left?", made the paid call, and only then booked it. Between those two
     * points nothing was reserved, so any number of concurrent requests could all observe
     * the same last token and all pay for a completion - on a cap documented as the backstop
     * against exactly that, a distributed attack.
     *
     * The booking is one conditional UPDATE, so the database decides the winner: "add one,
     * but only while we are still below the ceiling". The affected-row count is the answer -
     * exactly one request can take the last slot, however many ask at once.
     *
     * Compensation, not prevention, is what keeps failures cheap: releaseConfigDaily() hands
     * the slot back when the call provably never reached OpenAI. Booking up front WITHOUT
     * that release would be atomic too, and would let anyone take the chatbot offline until
     * midnight with requests that never cost a cent.
     */
    public function reserveConfigDaily(int $configId, int $dailyLimit): bool
    {
        if ($dailyLimit <= 0) {
            return true;
        }

        if (null === $this->connection) {
            // No database wired (unit tests, or a caller that only uses the IP limiter):
            // fall back to the cache limiter's non-atomic check rather than failing open.
            return $this->hasConfigDailyBudget($configId, $dailyLimit);
        }

        try {
            $day = $this->today();

            // INSERT IGNORE, so a race to create the row cannot fail the request; the unique
            // key on (pid, day) makes at most one of them win and the rest are no-ops.
            $this->connection->executeStatement(
                'INSERT IGNORE INTO tl_openai_chat_budget (pid, tstamp, day, spent) VALUES (?, ?, ?, 0)',
                [$configId, time(), $day],
            );

            $granted = $this->connection->executeStatement(
                'UPDATE tl_openai_chat_budget SET spent = spent + 1, tstamp = ? WHERE pid = ? AND day = ? AND spent < ?',
                [time(), $configId, $day, $dailyLimit],
            );

            if ($granted > 0) {
                $this->pruneOldBudgetRows($day);
            }

            return $granted > 0;
        } catch (\Throwable) {
            // The table is missing (bundle updated without contao:migrate) or the database is
            // unreachable. Degrade to the previous, non-atomic behaviour rather than taking
            // the chat offline: a bounded overshoot beats a dead endpoint on every install
            // that has not migrated yet.
            return $this->hasConfigDailyBudget($configId, $dailyLimit);
        }
    }

    /**
     * Hand back a reservation for a call that provably never reached OpenAI.
     *
     * Only ever called for failures the responder can prove were not billed - a connect-phase
     * transport error, an HTTP 429 or 503 rejection, or a failure before any request was made.
     * A response that may have been produced (and therefore charged) keeps its slot.
     *
     * "spent > 0" guards against a double release ever driving the counter negative, which on
     * an unsigned column would wrap into a very large number and disable the cap for the day.
     */
    public function releaseConfigDaily(int $configId, int $dailyLimit): void
    {
        if ($dailyLimit <= 0 || null === $this->connection) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'UPDATE tl_openai_chat_budget SET spent = spent - 1, tstamp = ? WHERE pid = ? AND day = ? AND spent > 0',
                [time(), $configId, $this->today()],
            );
        } catch (\Throwable) {
            // Losing a release only means the cap is one completion stricter today.
        }
    }

    /**
     * Is there budget left for today, without spending any of it?
     *
     * The cache-backed fallback for installations whose schema is not migrated yet. Kept
     * non-atomic on purpose - it is the strictly-better-than-nothing path, not the contract.
     */
    public function hasConfigDailyBudget(int $configId, int $dailyLimit): bool
    {
        if ($dailyLimit <= 0) {
            return true;
        }

        // consume(0) reads the window without spending from it. isAccepted() is not
        // usable here - "can I take zero tokens" is trivially true even on an
        // exhausted window - so the remaining count is what decides.
        return $this->dailyLimiter($configId, $dailyLimit)->consume(0)->getRemainingTokens() > 0;
    }

    /**
     * Book one completion against today's budget.
     */
    public function consumeConfigDaily(int $configId, int $dailyLimit): void
    {
        if ($dailyLimit <= 0) {
            return;
        }

        $this->dailyLimiter($configId, $dailyLimit)->consume(1);
    }

    /**
     * Today as YYYYMMDD in UTC.
     *
     * UTC rather than the site's timezone so the rollover is the same instant on every web
     * worker, whatever each one has configured.
     */
    private function today(): int
    {
        return (int) gmdate('Ymd');
    }

    /**
     * Drop budget rows from previous days.
     *
     * Only yesterday and earlier, and only on a successful reservation, so the cost is one
     * cheap DELETE on an indexed column amortised over the day's traffic. Without it a busy
     * installation accumulates one row per configuration per day forever.
     */
    private function pruneOldBudgetRows(int $today): void
    {
        if (null === $this->connection || 0 !== random_int(0, 99)) {
            // Roughly one request in a hundred does the cleanup; the rest skip the query.
            return;
        }

        try {
            $this->connection->executeStatement(
                'DELETE FROM tl_openai_chat_budget WHERE day < ?',
                [$today],
            );
        } catch (\Throwable) {
            // Housekeeping only.
        }
    }

    /**
     * The configured limit is folded into the cache key, not just passed to the
     * factory. Symfony's FixedWindowLimiter only uses the limit it is constructed
     * with to create a brand new Window; once a Window exists in storage for a
     * given key, reserve() reuses it as-is and the limit baked into that object
     * never changes. Without the limit in the key, raising chat_daily_limit after
     * a config had exhausted its (old, lower) budget did nothing - the exhausted
     * Window kept reporting zero tokens against its original ceiling for up to a
     * full day, with no way for an admin to unstick it short of waiting it out.
     * Folding the limit into the key means every distinct limit value a config
     * has been run with today keeps its own counter: changing the configured
     * value always starts a fresh window at the new ceiling rather than
     * inheriting hits booked under a different value. That is an unconditional
     * fix for raising the limit (a config can never get stuck again). Lowering
     * it mid-day is the honest trade-off: usage already booked under a higher
     * limit is not carried over as a head start against the new, lower one, so
     * a config can send a few more messages right after the change - but it is
     * bounded by the new limit from that point on, which is far preferable to
     * the alternative of reading a still-frozen ceiling from before the change.
     * A stable limit, the normal case, keeps the same key and therefore the
     * same running counter across requests and across an installation
     * updating mid-day, exactly as before.
     */
    private function dailyLimiter(int $configId, int $dailyLimit): LimiterInterface
    {
        $factory = new RateLimiterFactory(
            [
                'id' => 'oaa_chat_daily',
                'policy' => 'fixed_window',
                'limit' => $dailyLimit,
                'interval' => '1 day',
            ],
            new CacheStorage($this->cache),
        );

        return $factory->create($configId.':'.$dailyLimit);
    }
}
