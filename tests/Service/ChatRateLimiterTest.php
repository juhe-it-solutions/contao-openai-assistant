<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant bundle.
 *
 * (c) JUHE IT-solutions
 *
 * @license LGPL-3.0-or-later
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Service;

use JuheItSolutions\ContaoOpenaiAssistant\Service\ChatRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ChatRateLimiterTest extends TestCase
{
    public function testClientIpIsAllowedUpToTheLimitThenRejected(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        // The per-IP window allows 10 requests; the 11th within the window is rejected.
        for ($i = 0; $i < 10; ++$i) {
            $this->assertTrue($limiter->acceptClientIp('203.0.113.7'), 'request '.$i.' should be accepted');
        }

        $this->assertFalse($limiter->acceptClientIp('203.0.113.7'));
    }

    public function testDifferentIpsHaveIndependentBudgets(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 10; ++$i) {
            $limiter->acceptClientIp('203.0.113.7');
        }

        // A second IP is unaffected by the first IP exhausting its window.
        $this->assertTrue($limiter->acceptClientIp('198.51.100.4'));
    }

    public function testEmptyClientIpCollapsesToSharedBucketWithoutBypass(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 10; ++$i) {
            $this->assertTrue($limiter->acceptClientIp(''));
        }

        // An unresolved IP must not be an unlimited bypass.
        $this->assertFalse($limiter->acceptClientIp(''));
    }

    public function testConfiguredIpLimitIsRespected(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        // Intranet-style raised limit: more than the default 10 must pass.
        for ($i = 0; $i < 25; ++$i) {
            $this->assertTrue($limiter->acceptClientIp('203.0.113.7', 25), 'request '.$i.' should be accepted');
        }

        $this->assertFalse($limiter->acceptClientIp('203.0.113.7', 25));
    }

    public function testIpLimitOfZeroDisablesIpLimiting(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 50; ++$i) {
            $this->assertTrue($limiter->acceptClientIp('203.0.113.7', 0));
        }
    }

    public function testConfigDailyLimitIsEnforcedPerConfig(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        $this->assertTrue($limiter->acceptConfigDaily(1, 2));
        $this->assertTrue($limiter->acceptConfigDaily(1, 2));
        $this->assertFalse($limiter->acceptConfigDaily(1, 2));

        // A different config keeps its own budget.
        $this->assertTrue($limiter->acceptConfigDaily(2, 2));
    }

    public function testConfigDailyLimitOfZeroIsUncapped(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 50; ++$i) {
            $this->assertTrue($limiter->acceptConfigDaily(1, 0));
        }
    }

    /**
     * The check must not spend budget: a request that never reaches OpenAI (bad
     * key, outage) may not consume the day's ceiling.
     */
    public function testCheckingTheDailyBudgetDoesNotConsumeIt(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 20; ++$i) {
            $this->assertTrue($limiter->hasConfigDailyBudget(1, 2));
        }

        // Nothing was booked, so both completions are still available.
        $limiter->consumeConfigDaily(1, 2);
        $this->assertTrue($limiter->hasConfigDailyBudget(1, 2));

        $limiter->consumeConfigDaily(1, 2);
        $this->assertFalse($limiter->hasConfigDailyBudget(1, 2));
    }

    public function testConsumingTheDailyBudgetIsUncappedAtZero(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        for ($i = 0; $i < 50; ++$i) {
            $limiter->consumeConfigDaily(1, 0);
        }

        $this->assertTrue($limiter->hasConfigDailyBudget(1, 0));
    }

    /**
     * Reported bug: Tageslimit set to 2, both messages sent, budget exhausted -
     * then raising it back to 1000 in the backend left the chat permanently
     * refusing with "daily limit reached" for the rest of the day, with no way
     * for an admin to recover short of waiting it out (the limit baked into
     * the cached window at exhaustion time never changes just because the
     * config value does). Raising the limit must unstick it immediately.
     */
    public function testRaisingTheDailyLimitAfterExhaustionUnsticksTheBudget(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        $limiter->consumeConfigDaily(1, 2);
        $limiter->consumeConfigDaily(1, 2);
        $this->assertFalse($limiter->hasConfigDailyBudget(1, 2), 'sanity check: budget is exhausted at the old limit');

        $this->assertTrue($limiter->hasConfigDailyBudget(1, 1000), 'raising the limit must unstick the config immediately');
    }

    /**
     * Each distinct limit value a config has been run with today keeps its own
     * counter (the fix for the bug above folds the limit into the cache key).
     * So lowering the limit mid-day does not carry usage already booked under
     * the old, higher limit over as a head start against the new one - it
     * starts that lower limit's own counter from zero, which is the honest
     * trade-off documented on ChatRateLimiter::dailyLimiter(): a config can
     * momentarily send a few more messages than the newly lowered limit right
     * after it changes, but it is bounded by that new limit from then on, and
     * it can never end up stuck the way the reported bug describes.
     */
    public function testLoweringTheDailyLimitMidDayStartsThatLimitsOwnCounter(): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter());

        $limiter->consumeConfigDaily(1, 1000);
        $limiter->consumeConfigDaily(1, 1000);
        $this->assertTrue($limiter->hasConfigDailyBudget(1, 1000), 'sanity check: nowhere near the old, high limit');

        // The lower limit has never been consumed against today, so it has
        // its own fresh budget rather than inheriting the two hits above.
        $this->assertTrue($limiter->hasConfigDailyBudget(1, 2));
        $limiter->consumeConfigDaily(1, 2);
        $limiter->consumeConfigDaily(1, 2);
        $this->assertFalse($limiter->hasConfigDailyBudget(1, 2), 'but it is bounded by the new limit from that point on');
    }
}
