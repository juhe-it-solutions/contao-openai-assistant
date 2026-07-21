<?php

declare(strict_types=1);

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

namespace JuheItSolutions\ContaoOpenaiAssistant\Tests\Premium\Service;

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Premium\Service\LicenseValidationService;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
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

        $this->assertFalse($service->isLicenseActive(1));
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

        $this->assertTrue($service->isLicenseActive(1));
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
            'max_crawl_pages' => 20,
        ]);

        $this->assertFalse($service->isLicenseActive(1));
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
        $http = new MockHttpClient(
            static function (): MockResponse {
                self::fail('No network call expected on a fresh cache hit.');
            },
        );

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

        $service = new LicenseValidationService($connection, $http, $this->createMock(EncryptionService::class));

        $this->assertTrue($service->isLicenseActive(1));
    }

    /**
     * On a fresh cache hit whose plan data is older than the 1-hour plan TTL, the service
     * revalidates inline. The returned entitlement must reflect the row the revalidation
     * just wrote, not the pre-refresh snapshot — otherwise a plan/status change made during
     * this very call is ignored until the next one.
     */
    public function testInlinePlanRefreshReflectsFreshStatusNotStaleSnapshot(): void
    {
        $active = $this->licenseRow([
            'premium_license_status' => 'active',
            'premium_license_checked_at' => time() - 4000, // fresh for "active" (7d) but > 1h plan TTL
            'premium_license_last_success' => time() - 4000,
            'premium_license_valid_until' => time() + 30 * 86400,
        ]);
        $canceled = $this->licenseRow([
            'premium_license_status' => 'canceled',
            'premium_license_checked_at' => time(),
            'premium_license_last_success' => time() - 4000,
            'premium_license_valid_until' => time() - 86400,
        ]);

        $connection = $this->createMock(Connection::class);

        // 1: initial isLicenseActive SELECT, 2: revalidate() $previous SELECT, 3: re-read.
        $fetchSequence = [$active, $active, $canceled];
        $fetchCall = 0;
        $connection
            ->method('fetchAssociative')
            ->willReturnCallback(
                static function () use ($fetchSequence, &$fetchCall) {
                    $result = $fetchSequence[$fetchCall] ?? end($fetchSequence);
                    ++$fetchCall;

                    return $result;
                },
            )
        ;

        $connection
            ->method('fetchOne')
            ->willReturnCallback(
                static fn (string $sql) => str_contains($sql, 'premium_license_install_id') ? 'abc123' : false,
            )
        ;

        $connection
            ->method('executeStatement')
            ->willReturn(1)
        ;

        $http = new MockHttpClient(new MockResponse(json_encode([
            'valid' => false,
            'status' => 'canceled',
            'expires_at' => date('c', time() - 86400),
            'plan' => 'starter',
            'max_crawl_pages' => 20,
        ], JSON_THROW_ON_ERROR)));

        $encryption = $this->createMock(EncryptionService::class);
        $encryption
            ->method('decryptLicenseKey')
            ->willReturn('JUHE-AI-TESTKEY1')
        ;

        $service = new LicenseValidationService($connection, $http, $encryption);

        $this->assertFalse(
            $service->isLicenseActive(1),
            'The freshly revalidated (now canceled) status must win over the stale active snapshot.',
        );
    }

    /**
     * A 429 from the rate-limited /validate endpoint is a temporary server condition,
     * not an entitlement verdict. It must flow into the grace handling instead of
     * overwriting a valid cached license with "inactive" (the response body is JSON
     * but carries no "valid" field, which used to be read as valid=false).
     */
    public function testRateLimited429KeepsLicenseActiveWithinGrace(): void
    {
        $service = $this->createService(
            $this->staleActiveRowWithinGrace(),
            rawResponse: new MockResponse('{"error":"rate_limited"}', ['http_code' => 429]),
        );

        $this->assertTrue($service->isLicenseActive(1));
    }

    public function testServerError500KeepsLicenseActiveWithinGrace(): void
    {
        $service = $this->createService(
            $this->staleActiveRowWithinGrace(),
            rawResponse: new MockResponse('{"statusCode":500,"error":"Internal Server Error"}', ['http_code' => 500]),
        );

        $this->assertTrue($service->isLicenseActive(1));
    }

    public function testMalformedResponseBodyKeepsLicenseActiveWithinGrace(): void
    {
        $service = $this->createService(
            $this->staleActiveRowWithinGrace(),
            rawResponse: new MockResponse('<html>502 Bad Gateway</html>', ['http_code' => 200]),
        );

        $this->assertTrue($service->isLicenseActive(1));
    }

    /**
     * A 2xx response whose schema lacks the boolean "valid" field is a contract
     * violation and must be treated as a server failure (grace), not as valid=false.
     */
    public function testSchemaWithoutValidFieldKeepsLicenseActiveWithinGrace(): void
    {
        $service = $this->createService(
            $this->staleActiveRowWithinGrace(),
            rawResponse: new MockResponse('{"status":"active"}', ['http_code' => 200]),
        );

        $this->assertTrue($service->isLicenseActive(1));
    }

    /**
     * Grace only bridges server failures. An explicit 200 valid:false is a real
     * entitlement verdict and must deactivate immediately, even within the window.
     */
    public function testExplicitValidFalseDeactivatesDespiteRecentSuccess(): void
    {
        $service = $this->createService($this->staleActiveRowWithinGrace(), serverResponse: [
            'valid' => false,
            'status' => 'canceled',
            'plan' => 'starter',
            'max_crawl_pages' => 20,
        ]);

        $this->assertFalse($service->isLicenseActive(1));
    }

    public function testRateLimited429DeniesWhenGraceWindowHasExpired(): void
    {
        $row = $this->licenseRow([
            'premium_license_status' => 'active',
            'premium_license_checked_at' => time() - 8 * 86400, // stale -> forces revalidation
            'premium_license_last_success' => time() - 8 * 86400, // > 7-day grace
            'premium_license_valid_until' => time() - 86400, // paid period also over
        ]);

        $service = $this->createService(
            $row,
            rawResponse: new MockResponse('{"error":"rate_limited"}', ['http_code' => 429]),
        );

        $this->assertFalse($service->isLicenseActive(1));
    }

    public function testResolvePageLimitFallsBackToPlanDefaults(): void
    {
        $this->assertSame(20, LicenseValidationService::resolvePageLimit('starter', 0));
        $this->assertSame(50, LicenseValidationService::resolvePageLimit('business', 0));
        $this->assertSame(250, LicenseValidationService::resolvePageLimit('business', 250));
        $this->assertNull(LicenseValidationService::resolvePageLimit('enterprise', 0));
        $this->assertNull(LicenseValidationService::resolvePageLimit('', 0));
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
     * A row whose "active" cache is stale (forces a revalidation call) but whose last
     * server-confirmed success is recent enough for the 7-day grace window.
     *
     * @return array<string, int|string>
     */
    private function staleActiveRowWithinGrace(): array
    {
        return $this->licenseRow([
            'premium_license_status' => 'active',
            'premium_license_checked_at' => time() - 8 * 86400, // stale -> forces revalidation
            'premium_license_last_success' => time() - 2 * 86400, // within 7-day grace
            'premium_license_valid_until' => time() + 30 * 86400,
        ]);
    }

    /**
     * @param array<string, int|string> $row
     * @param array<string, mixed>|null $serverResponse
     */
    private function createService(array $row, bool $unreachable = false, array|null $serverResponse = null, MockResponse|null $rawResponse = null): LicenseValidationService
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

        // resolveInstallId / resolveSiteDomain lookups during header building
        $connection
            ->method('fetchOne')
            ->willReturnCallback(
                static fn (string $sql) => str_contains($sql, 'premium_license_install_id') ? (string) $row['premium_license_install_id'] : false,
            )
        ;

        $connection
            ->method('executeStatement')
            ->willReturn(1)
        ;

        if ($unreachable) {
            $http = new MockHttpClient(
                static function (): MockResponse {
                    throw new TransportException('connection refused');
                },
            );
        } elseif (null !== $rawResponse) {
            $http = new MockHttpClient($rawResponse);
        } else {
            $http = new MockHttpClient(new MockResponse(json_encode($serverResponse, JSON_THROW_ON_ERROR)));
        }

        $encryption = $this->createMock(EncryptionService::class);
        $encryption
            ->method('decryptLicenseKey')
            ->willReturn('JUHE-AI-TESTKEY1')
        ;

        return new LicenseValidationService($connection, $http, $encryption);
    }
}
