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

    public function __construct(private readonly CacheItemPoolInterface $cache)
    {
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
     * Is there budget left for today, without spending any of it?
     *
     * Used to reject a request before the paid call. The matching consume() runs
     * only once the completion actually succeeded, so a misconfiguration or an
     * OpenAI outage cannot burn the day's ceiling - which used to let anyone shut
     * the chatbot down for the rest of the day with requests that never cost a cent.
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
