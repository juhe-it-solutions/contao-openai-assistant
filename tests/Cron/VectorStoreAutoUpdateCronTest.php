<?php

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Cron;

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Cron\VectorStoreAutoUpdateCron;
use JuheItSolutions\ContaoOpenaiAssistant\Service\VectorStoreAutoUpdateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \JuheItSolutions\ContaoOpenaiAssistant\Cron\VectorStoreAutoUpdateCron
 */
class VectorStoreAutoUpdateCronTest extends TestCase
{
    public function testIsDueReturnsFalseWhenNeverRun(): void
    {
        $cron = new VectorStoreAutoUpdateCron(
            $this->createMock(Connection::class),
            $this->createMock(VectorStoreAutoUpdateService::class),
            new NullLogger(),
        );

        $method = new \ReflectionMethod(VectorStoreAutoUpdateCron::class, 'isDue');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($cron, [
            'auto_update_last_run' => 0,
            'auto_update_schedule' => '0 2 * * *',
            'auto_update_last_status' => '',
        ]));
    }

    public function testIsDueReturnsTrueWhenScheduleElapsedSinceLastRun(): void
    {
        $cron = new VectorStoreAutoUpdateCron(
            $this->createMock(Connection::class),
            $this->createMock(VectorStoreAutoUpdateService::class),
            new NullLogger(),
        );

        $method = new \ReflectionMethod(VectorStoreAutoUpdateCron::class, 'isDue');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($cron, [
            'auto_update_last_run' => time() - 86400 * 2,
            'auto_update_schedule' => '0 2 * * *',
            'auto_update_last_status' => 'success',
        ]));
    }

    public function testIsDueReturnsFalseWhileRunIsInFlight(): void
    {
        $cron = new VectorStoreAutoUpdateCron(
            $this->createMock(Connection::class),
            $this->createMock(VectorStoreAutoUpdateService::class),
            new NullLogger(),
        );

        $method = new \ReflectionMethod(VectorStoreAutoUpdateCron::class, 'isDue');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($cron, [
            'auto_update_last_run' => time() - 60,
            'auto_update_schedule' => '* * * * *',
            'auto_update_last_status' => 'running',
        ]));
    }
}
