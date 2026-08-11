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

use Doctrine\DBAL\Connection;
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

    /**
     * THE race this whole mechanism exists for.
     *
     * Under the old check-then-consume pair, every one of these calls would have run its
     * check before any of them booked anything, so all five would have been told to go ahead
     * and all five would have paid for a completion against a cap of two. Reserving in a
     * single conditional UPDATE means the database picks the winners: whatever the
     * interleaving, exactly two get through.
     */
    public function testConcurrentRequestsCannotAllTakeTheLastSlot(): void
    {
        $rows = [];
        $limiter = new ChatRateLimiter(new ArrayAdapter(), $this->createBudgetConnection($rows));

        $granted = 0;

        // Five requests in flight, none of them having finished: exactly the state in which
        // the old code handed the same last token to all of them.
        for ($i = 0; $i < 5; ++$i) {
            if ($limiter->reserveConfigDaily(1, 2)) {
                ++$granted;
            }
        }

        $this->assertSame(2, $granted, 'The cap is the cap, however many ask at once.');
        $this->assertSame(2, $rows[$this->budgetKey(1)]);
    }

    public function testEachConfigurationHasItsOwnDailyBudget(): void
    {
        $rows = [];
        $limiter = new ChatRateLimiter(new ArrayAdapter(), $this->createBudgetConnection($rows));

        $this->assertTrue($limiter->reserveConfigDaily(1, 1));
        $this->assertFalse($limiter->reserveConfigDaily(1, 1), 'config 1 is exhausted');
        $this->assertTrue($limiter->reserveConfigDaily(2, 1), 'config 2 is untouched by it');
    }

    /**
     * The compensation half. Booking up front without this would be atomic too - and would
     * let anyone take the chatbot offline until midnight with requests that never cost a
     * cent, which is the trade the previous design deliberately refused.
     */
    public function testAReleasedSlotBecomesAvailableAgain(): void
    {
        $rows = [];
        $limiter = new ChatRateLimiter(new ArrayAdapter(), $this->createBudgetConnection($rows));

        $this->assertTrue($limiter->reserveConfigDaily(1, 1));
        $this->assertFalse($limiter->reserveConfigDaily(1, 1));

        // The call never reached OpenAI, so the slot goes back.
        $limiter->releaseConfigDaily(1, 1);

        $this->assertTrue($limiter->reserveConfigDaily(1, 1), 'an unbilled failure must not spend the day');
    }

    /**
     * An unsigned column would wrap a negative value into something enormous, which would
     * silently disable the cap for the rest of the day.
     */
    public function testReleasingMoreThanWasReservedCannotDriveTheCounterNegative(): void
    {
        $rows = [];
        $limiter = new ChatRateLimiter(new ArrayAdapter(), $this->createBudgetConnection($rows));

        $limiter->reserveConfigDaily(1, 5);
        $limiter->releaseConfigDaily(1, 5);
        $limiter->releaseConfigDaily(1, 5);
        $limiter->releaseConfigDaily(1, 5);

        $this->assertSame(0, $rows[$this->budgetKey(1)]);
    }

    public function testAnUncappedConfigurationNeverTouchesTheCounter(): void
    {
        $rows = [];
        $limiter = new ChatRateLimiter(new ArrayAdapter(), $this->createBudgetConnection($rows));

        for ($i = 0; $i < 20; ++$i) {
            $this->assertTrue($limiter->reserveConfigDaily(1, 0));
        }

        $limiter->releaseConfigDaily(1, 0);

        $this->assertSame([], $rows, 'A disabled ceiling must not write budget rows at all.');
    }

    /**
     * An installation that updated the bundle without running contao:migrate has no budget
     * table. Taking the chat offline over that would be far worse than the bounded overshoot
     * of the old behaviour, so the daily cap degrades instead of failing.
     */
    /**
     * The upgrade window: code deployed, contao:migrate not run yet, so the budget table does
     * not exist. The cap has to keep working on its own, because nothing else books against
     * the cache window any more - a fallback that only CHECKED would answer "budget left" to
     * every request forever and silently switch the daily cost ceiling off.
     *
     * @dataProvider provideUnusableConnections
     */
    public function testTheCapStillHoldsWithoutTheBudgetTable(Connection|null $connection): void
    {
        $limiter = new ChatRateLimiter(new ArrayAdapter(), $connection);

        $this->assertTrue($limiter->reserveConfigDaily(1, 2));
        $this->assertTrue($limiter->reserveConfigDaily(1, 2));
        $this->assertFalse(
            $limiter->reserveConfigDaily(1, 2),
            'The fallback must BOOK each message, not merely look at the budget.',
        );

        // And it is still per configuration.
        $this->assertTrue($limiter->reserveConfigDaily(2, 2));
    }

    /**
     * @return iterable<string, array{Connection|null}>
     */
    public static function provideUnusableConnections(): iterable
    {
        yield 'no connection wired at all' => [null];
    }

    public function testTheCapStillHoldsWhenTheBudgetTableIsMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willThrowException(new \RuntimeException("Table 'tl_openai_chat_budget' doesn't exist"))
        ;

        $limiter = new ChatRateLimiter(new ArrayAdapter(), $connection);

        $this->assertTrue($limiter->reserveConfigDaily(1, 2));
        $this->assertTrue($limiter->reserveConfigDaily(1, 2));
        $this->assertFalse($limiter->reserveConfigDaily(1, 2), 'A missing table must not disable the ceiling.');
    }

    /**
     * Housekeeping runs after the booking, so a failure in it must not be read as a failed
     * reservation - that would book a second message and answer with the wrong verdict.
     */
    public function testAFailingCleanupDoesNotCorruptTheVerdict(): void
    {
        $rows = [];
        $inner = $this->createBudgetConnection($rows);

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params) use ($inner, &$rows): int {
                    if (str_starts_with($sql, 'DELETE')) {
                        throw new \RuntimeException('housekeeping blew up');
                    }

                    return $inner->executeStatement($sql, $params);
                },
            )
        ;

        $limiter = new ChatRateLimiter(new ArrayAdapter(), $connection);

        // Enough attempts that the 1-in-100 cleanup is overwhelmingly likely to have fired.
        $granted = 0;

        for ($i = 0; $i < 300; ++$i) {
            if ($limiter->reserveConfigDaily(1, 500)) {
                ++$granted;
            }
        }

        $this->assertSame(300, $granted);
        $this->assertSame(300, $rows[$this->budgetKey(1)], 'Every grant booked exactly one message.');
    }

    private function budgetKey(int $configId): string
    {
        return $configId.':'.gmdate('Ymd');
    }

    /**
     * An in-memory stand-in for tl_openai_chat_budget.
     *
     * It models the two statements that carry the guarantee - INSERT IGNORE creating at most
     * one row per configuration and day, and the conditional UPDATE that books a slot only
     * while the counter is below the ceiling - because a fake that ignored those conditions
     * would pass whether or not the reservation is atomic.
     *
     * @param array<string, int> $rows "configId:day" => spent
     */
    private function createBudgetConnection(array &$rows): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params) use (&$rows): int {
                    if (str_starts_with($sql, 'INSERT IGNORE')) {
                        $key = $params[0].':'.$params[2];

                        if (isset($rows[$key])) {
                            return 0;
                        }

                        $rows[$key] = 0;

                        return 1;
                    }

                    if (str_contains($sql, 'spent = spent + 1')) {
                        $key = $params[1].':'.$params[2];

                        if (!isset($rows[$key]) || $rows[$key] >= $params[3]) {
                            return 0;
                        }

                        ++$rows[$key];

                        return 1;
                    }

                    if (str_contains($sql, 'spent = spent - 1')) {
                        $key = $params[1].':'.$params[2];

                        if (!isset($rows[$key]) || $rows[$key] <= 0) {
                            return 0;
                        }

                        --$rows[$key];

                        return 1;
                    }

                    // The housekeeping DELETE for previous days.
                    $before = \count($rows);

                    foreach (array_keys($rows) as $key) {
                        if ((int) explode(':', $key)[1] < $params[0]) {
                            unset($rows[$key]);
                        }
                    }

                    return $before - \count($rows);
                },
            )
        ;

        return $connection;
    }
}
