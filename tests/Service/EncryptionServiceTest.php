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
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EncryptionServiceTest extends TestCase
{
    private const APP_SECRET = 'test-app-secret';

    /**
     * Long enough that the encrypted+base64 stored value exceeds the 100-char
     * "encrypted vs. legacy base64" threshold in processApiKey().
     */
    private const API_KEY = 'sk-proj-abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQR';

    /**
     * @var array<string, string|null>
     */
    private array $serverBackup = [];

    /**
     * @var array<string, string|null>
     */
    private array $envBackup = [];

    protected function setUp(): void
    {
        // Pin the web-context values the legacy (pre-app-secret) key derives from,
        // and make sure no env var short-circuits the DB lookup.
        foreach (['SERVER_NAME', 'HTTP_HOST', 'DOCUMENT_ROOT'] as $key) {
            $this->serverBackup[$key] = isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
        }

        $_SERVER['SERVER_NAME'] = 'example.com';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['DOCUMENT_ROOT'] = '/var/www/html/public';

        foreach (['OPENAI_API_KEY', 'OPENAI_API_KEY_1', 'APP_URL'] as $key) {
            $this->envBackup[$key] = isset($_ENV[$key]) ? (string) $_ENV[$key] : null;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->serverBackup as $key => $value) {
            if (null === $value) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        foreach ($this->envBackup as $key => $value) {
            if (null === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    public function testGetApiKeyForConfigReencryptsLegacyServerKeyValue(): void
    {
        $stored = $this->encryptWithKey(self::API_KEY, $this->legacyServerKey());
        $updates = [];
        $connection = $this->mockConnection($stored, $updates);

        $service = $this->createService($connection, self::APP_SECRET);

        $this->assertSame(self::API_KEY, $service->getApiKeyForConfig(1));

        $this->assertCount(1, $updates, 'legacy-encrypted key must be rotated exactly once');
        [$sql, $params] = $updates[0];
        $this->assertStringContainsString('UPDATE tl_openai_config SET api_key', $sql);
        $this->assertSame(1, $params[1]);
        $this->assertSame($stored, $params[2], 'rotation must be guarded by the previously read value');

        // The rewritten value must decrypt with the app-secret key alone - that is the
        // whole point: a CLI process without SERVER_NAME/DOCUMENT_ROOT can now read it.
        $this->assertSame(self::API_KEY, $this->decryptWithKey((string) $params[0], $this->primaryKey()));
    }

    public function testGetApiKeyForConfigReencryptsLegacyBase64Value(): void
    {
        $stored = base64_encode(self::API_KEY);
        $updates = [];
        $connection = $this->mockConnection($stored, $updates);

        $service = $this->createService($connection, self::APP_SECRET);

        $this->assertSame(self::API_KEY, $service->getApiKeyForConfig(1));

        $this->assertCount(1, $updates);
        $this->assertSame(self::API_KEY, $this->decryptWithKey((string) $updates[0][1][0], $this->primaryKey()));
    }

    public function testGetApiKeyForConfigResolvesAndReencryptsLongLegacyBase64Value(): void
    {
        // A modern long key stored as legacy base64 exceeds the 100-char threshold and
        // used to be misclassified as encrypted, failing resolution entirely.
        $longApiKey = 'sk-proj-'.str_repeat('abcdefgh', 15);
        $stored = base64_encode($longApiKey);
        $this->assertGreaterThan(100, \strlen($stored));

        $updates = [];
        $connection = $this->mockConnection($stored, $updates);

        $service = $this->createService($connection, self::APP_SECRET);

        $this->assertSame($longApiKey, $service->getApiKeyForConfig(1));

        $this->assertCount(1, $updates);
        $this->assertSame($longApiKey, $this->decryptWithKey((string) $updates[0][1][0], $this->primaryKey()));
    }

    public function testGetApiKeyForConfigLeavesPrimaryEncryptedValueUntouched(): void
    {
        // The fresh-install round trip: encryptApiKey() is exactly what the config
        // save callback stores, so resolving it must work and must never write.
        $updates = [];
        $connection = $this->mockConnection('', $updates);
        $stored = $this->createService($connection, self::APP_SECRET)->encryptApiKey(self::API_KEY);

        $connection = $this->mockConnection($stored, $updates);
        $service = $this->createService($connection, self::APP_SECRET);

        $this->assertSame(self::API_KEY, $service->getApiKeyForConfig(1));
        $this->assertSame([], $updates, 'an app-secret encrypted value must not be rewritten');
    }

    public function testGetApiKeyForConfigDoesNotRotateWithoutAppSecret(): void
    {
        $stored = $this->encryptWithKey(self::API_KEY, $this->legacyServerKey());
        $updates = [];
        $connection = $this->mockConnection($stored, $updates);

        $service = $this->createService($connection, null);

        $this->assertSame(self::API_KEY, $service->getApiKeyForConfig(1));
        $this->assertSame([], $updates, 'without an app secret the primary key is context-dependent - rotating would churn');
    }

    public function testRotationFailureDoesNotBreakKeyResolution(): void
    {
        $stored = $this->encryptWithKey(self::API_KEY, $this->legacyServerKey());

        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn(['api_key' => $stored])
        ;

        $connection
            ->method('fetchFirstColumn')
            ->willReturn([])
        ;

        $connection
            ->method('executeStatement')
            ->willThrowException(new \RuntimeException('db gone away'))
        ;

        $service = $this->createService($connection, self::APP_SECRET);

        $this->assertSame(self::API_KEY, $service->getApiKeyForConfig(1));
    }

    public function testEnvKeyTakesPrecedenceAndSkipsDatabase(): void
    {
        $_ENV['OPENAI_API_KEY_1'] = self::API_KEY;

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->never())
            ->method('fetchAssociative')
        ;

        $connection
            ->expects($this->never())
            ->method('executeStatement')
        ;

        $service = $this->createService($connection, self::APP_SECRET);

        $this->assertSame(self::API_KEY, $service->getApiKeyForConfig(1));
    }

    /**
     * @param array<int, array{0: string, 1: array<int, mixed>}> $updates
     */
    private function mockConnection(string $storedApiKey, array &$updates): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('fetchAssociative')
            ->willReturn(['api_key' => $storedApiKey])
        ;

        // Host candidates for decryption look up tl_page domains.
        $connection
            ->method('fetchFirstColumn')
            ->willReturn([])
        ;
        $connection
            ->method('executeStatement')
            ->willReturnCallback(
                static function (string $sql, array $params = []) use (&$updates): int {
                    $updates[] = [$sql, $params];

                    return 1;
                },
            )
        ;

        return $connection;
    }

    private function createService(Connection $connection, string|null $appSecret): EncryptionService
    {
        return new EncryptionService(
            new NullLogger(),
            $connection,
            '/var/www/html',
            'public',
            $appSecret,
        );
    }

    private function primaryKey(): string
    {
        return hash('sha256', 'contao-openai-assistant:'.self::APP_SECRET, true);
    }

    private function legacyServerKey(): string
    {
        return hash('sha256', $_SERVER['SERVER_NAME'].$_SERVER['DOCUMENT_ROOT'], true);
    }

    private function encryptWithKey(string $plaintext, string $key): string
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, 0, $iv);
        \assert(false !== $encrypted);

        return base64_encode($iv.$encrypted);
    }

    private function decryptWithKey(string $stored, string $key): string|null
    {
        $data = base64_decode($stored, true);
        \assert(false !== $data);

        $decrypted = openssl_decrypt(substr($data, 16), 'aes-256-cbc', $key, 0, substr($data, 0, 16));

        return false !== $decrypted ? $decrypted : null;
    }
}
