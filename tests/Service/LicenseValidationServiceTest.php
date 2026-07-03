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

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\LicenseValidationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LicenseValidationServiceTest extends TestCase
{
    /**
     * The grace window must be anchored on the last SERVER-CONFIRMED validation
     * (premium_license_last_success), never on checked_at: the error path refreshes
     * checked_at on every failed attempt, which used to re-extend the grace period
     * indefinitely while the licensing endpoint was blocked.
     */
    public function testGraceExpiresWhenLastSuccessfulValidationIsTooOld(): void
    {
        $row = $this->licenseRow([
            'premium_license_status' => 'error',
            'premium_license_checked_at' => time() - 7200, // refreshed by failed checks
            'premium_license_last_success' => time() - 8 * 86400, // > 7-day grace
            'premium_license_valid_until' => 0,
        ]);

        $service = $this->createService($row, unreachable: true);

        self::assertFalse($service->isLicenseActive(1));
    }

    public function testGraceAllowsSyncWhileLastSuccessIsRecent(): void
    {
        $row = $this->licenseRow([
            'premium_license_status' => 'error',
            'premium_license_checked_at' => time() - 7200,
            'premium_license_last_success' => time() - 2 * 86400, // within grace
            'premium_license_valid_until' => 0,
        ]);

        $service = $this->createService($row, unreachable: true);

        self::assertTrue($service->isLicenseActive(1));
    }

    /**
     * A cached entitled status whose paid period has ended must not be served from the
     * cache: the service has to revalidate and honour the server's valid=false verdict
     * (previously a "past_due" row stayed entitled for the full 7-day cache TTL).
     */
    public function testExpiredEntitledStatusIsRevalidatedAndDenied(): void
    {
        $row = $this->licenseRow([
            'premium_license_status' => 'past_due',
            'premium_license_checked_at' => time() - 60, // cache would still be "fresh"
            'premium_license_last_success' => time() - 30 * 86400,
            'premium_license_valid_until' => time() - 86400, // period ended yesterday
        ]);

        $service = $this->createService($row, serverResponse: [
            'valid' => false,
            'status' => 'past_due',
            'expires_at' => date('c', time() - 86400),
            'plan' => 'starter',
            'max_crawl_pages' => 30,
        ]);

        self::assertFalse($service->isLicenseActive(1));
    }

    public function testFreshEntitledCacheIsAcceptedWithoutNetworkCall(): void
    {
        $row = $this->licenseRow([
            'premium_license_status' => 'active',
            'premium_license_checked_at' => time() - 60,
            'premium_license_last_success' => time() - 60,
            'premium_license_valid_until' => time() + 86400,
        ]);

        // Any HTTP request would fail the test.
        $http = new MockHttpClient(static function (): MockResponse {
            self::fail('No network call expected on a fresh cache hit.');
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($row);

        $service = new LicenseValidationService($connection, $http, $this->createMock(EncryptionService::class));

        self::assertTrue($service->isLicenseActive(1));
    }

    public function testResolvePageLimitFallsBackToPlanDefaults(): void
    {
        self::assertSame(30, LicenseValidationService::resolvePageLimit('starter', 0));
        self::assertSame(100, LicenseValidationService::resolvePageLimit('business', 0));
        self::assertSame(250, LicenseValidationService::resolvePageLimit('business', 250));
        self::assertNull(LicenseValidationService::resolvePageLimit('enterprise', 0));
        self::assertNull(LicenseValidationService::resolvePageLimit('', 0));
    }

    /**
     * @param array<string, int|string> $overrides
     *
     * @return array<string, int|string>
     */
    private function licenseRow(array $overrides): array
    {
        return array_merge(
            [
                'premium_license_key' => 'encrypted-value',
                'premium_license_status' => '',
                'premium_license_valid_until' => 0,
                'premium_license_checked_at' => 0,
                'premium_license_last_success' => 0,
                'premium_license_plan' => '',
                'premium_license_max_pages' => 0,
                'premium_license_cancel_at_period_end' => 0,
                'premium_license_install_id' => 'abc123',
            ],
            $overrides,
        );
    }

    /**
     * @param array<string, int|string>  $row
     * @param array<string, mixed>|null $serverResponse
     */
    private function createService(array $row, bool $unreachable = false, array|null $serverResponse = null): LicenseValidationService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($row);
        // resolveInstallId / resolveSiteDomain lookups during header building
        $connection->method('fetchOne')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'premium_license_install_id') ? (string) $row['premium_license_install_id'] : false,
        );
        $connection->method('executeStatement')->willReturn(1);

        if ($unreachable) {
            $http = new MockHttpClient(static function (): MockResponse {
                throw new TransportException('connection refused');
            });
        } else {
            $http = new MockHttpClient(new MockResponse(json_encode($serverResponse, JSON_THROW_ON_ERROR)));
        }

        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('decryptLicenseKey')->willReturn('JUHE-AI-TESTKEY1');

        return new LicenseValidationService($connection, $http, $encryption);
    }
}
