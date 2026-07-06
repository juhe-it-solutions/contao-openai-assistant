<?php

declare(strict_types=1);

/*
 * This file is part of Contao Open Source CMS.
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
}
