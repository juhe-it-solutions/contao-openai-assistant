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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Cross-project contract test: consumes the SAME fixture as the licensing server's
 * route tests (juhe-reviews tests/fixtures/openai-assistant-validate-contract.json,
 * mirrored to tests/Premium/Fixtures/validate-contract.json - keep both identical).
 *
 * The server side asserts each license row produces the fixture response; this side
 * asserts the Contao client grants or denies sync for exactly those responses, and
 * that the request itself matches the contract (GET, key in the X-License-Key header,
 * never in the URL).
 */
class LicenseValidationContractTest extends TestCase
{
    private const TEST_KEY = 'JUHE-AI-CONTRACTKEY1';

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function entitlementCaseProvider(): iterable
    {
        foreach (self::fixture()['entitlement_cases'] as $case) {
            yield (string) $case['name'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('entitlementCaseProvider')]
    public function testEntitlementCaseMatchesServerContract(array $case): void
    {
        $daysFromNow = $case['current_period_end_days_from_now'];
        $body = [
            'valid' => $case['expected_valid'],
            'status' => $case['status'],
            'expires_at' => null === $daysFromNow ? null : date('c', time() + ((int) $daysFromNow) * 86400),
            'plan' => $case['plan'],
            'max_crawl_pages' => $case['max_crawl_pages'],
            'cancel_at_period_end' => $case['cancel_at_period_end'],
        ];

        $service = $this->createService(new MockResponse(json_encode($body, JSON_THROW_ON_ERROR)));

        $this->assertSame(
            $case['expected_valid'],
            $service->isLicenseActive(1),
            \sprintf('Contract case "%s" must grant/deny sync exactly as the server decided.', $case['name']),
        );
    }

    public function testValidationRequestSendsKeyInHeaderAndNeverInUrl(): void
    {
        $seen = [];
        $http = new MockHttpClient(
            static function (string $method, string $url, array $options) use (&$seen): MockResponse {
                $seen = ['method' => $method, 'url' => $url, 'headers' => $options['headers'] ?? []];

                return new MockResponse(json_encode([
                    'valid' => true,
                    'status' => 'active',
                    'expires_at' => date('c', time() + 86400),
                    'plan' => 'business',
                    'max_crawl_pages' => 50,
                    'cancel_at_period_end' => false,
                ], JSON_THROW_ON_ERROR));
            },
        );

        $service = new LicenseValidationService($this->connectionMock(), $http, $this->encryptionMock());

        $this->assertTrue($service->isLicenseActive(1));
        $this->assertSame('GET', $seen['method']);
        $this->assertStringNotContainsString(self::TEST_KEY, $seen['url'], 'The key must never travel in the URL.');
        $this->assertStringNotContainsString('key=', $seen['url']);

        $headerLines = array_map('strtolower', (array) $seen['headers']);
        $this->assertNotEmpty(
            array_filter($headerLines, static fn (string $line) => str_contains($line, 'x-license-key: '.strtolower(self::TEST_KEY))),
            'The key must be sent in the X-License-Key header.',
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function serverFailureCaseProvider(): iterable
    {
        foreach (self::fixture()['server_failure_cases'] as $case) {
            yield (string) $case['name'] => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('serverFailureCaseProvider')]
    public function testServerFailureCasesFallIntoGrace(array $case): void
    {
        $this->assertSame('grace', $case['expected_client_behavior']);

        // A license that validated successfully two days ago (within the 7-day grace
        // window) but whose cache is stale, forcing a live revalidation attempt.
        $row = $this->licenseRow([
            'premium_license_status' => 'active',
            'premium_license_checked_at' => time() - 8 * 86400,
            'premium_license_last_success' => time() - 2 * 86400,
            'premium_license_valid_until' => time() + 30 * 86400,
        ]);

        $service = $this->createService(
            new MockResponse((string) $case['body'], ['http_code' => (int) $case['http_status']]),
            $row,
        );

        $this->assertTrue(
            $service->isLicenseActive(1),
            \sprintf('Server failure "%s" must trigger the grace period, not deactivate the license.', $case['name']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $json = file_get_contents(__DIR__.'/../Fixtures/validate-contract.json');

        if (false === $json) {
            self::fail('Contract fixture tests/Premium/Fixtures/validate-contract.json is missing.');
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, int|string> $overrides
     *
     * @return array<string, int|string>
     */
    private function licenseRow(array $overrides = []): array
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
                'premium_license_install_id' => 'contract-install',
            ],
            $overrides,
        );
    }

    /**
     * @param array<string, int|string>|null $row
     */
    private function createService(MockResponse $response, array|null $row = null): LicenseValidationService
    {
        return new LicenseValidationService(
            $this->connectionMock($row),
            new MockHttpClient($response),
            $this->encryptionMock(),
        );
    }

    /**
     * @param array<string, int|string>|null $row
     */
    private function connectionMock(array|null $row = null): Connection
    {
        $row ??= $this->licenseRow();

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn($row)
        ;

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

        return $connection;
    }

    private function encryptionMock(): EncryptionService
    {
        $encryption = $this->createMock(EncryptionService::class);
        $encryption
            ->method('decryptLicenseKey')
            ->willReturn(self::TEST_KEY)
        ;

        return $encryption;
    }
}
