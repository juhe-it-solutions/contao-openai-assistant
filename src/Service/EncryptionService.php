<?php

/*
 * This file is part of Contao Open Source CMS.
 *  *
 *  * (c) JUHE IT-solutions
 *  *
 *  * @license LGPL-3.0-or-later
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

class EncryptionService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Connection|null $connection = null,
        private readonly string|null $projectDir = null,
        private readonly string|null $webDir = null,
    ) {
    }

    /**
     * Resolve the API key for a given config ID.
     *
     * Precedence:
     *   1. Environment variable OPENAI_API_KEY_{configId}
     *   2. Database (tl_openai_config.api_key), encrypted or legacy base64
     *
     * Returns null when no usable key is available or the stored key fails validation.
     */
    public function getApiKeyForConfig(int $configId, bool $logInvalidFormat = true): string|null
    {
        $envKey = \sprintf('OPENAI_API_KEY_%d', $configId);
        if (isset($_ENV[$envKey]) && '' !== $_ENV[$envKey]) {
            $candidate = (string) $_ENV[$envKey];
            if ($this->isValidApiKeyFormat($candidate)) {
                return $candidate;
            }
        }

        if (isset($_ENV['OPENAI_API_KEY']) && '' !== $_ENV['OPENAI_API_KEY']) {
            $candidate = (string) $_ENV['OPENAI_API_KEY'];
            if ($this->isValidApiKeyFormat($candidate)) {
                return $candidate;
            }
        }

        if (null === $this->connection) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT api_key FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        if (!$row || empty($row['api_key'])) {
            return null;
        }

        return $this->processApiKey((string) $row['api_key'], $logInvalidFormat);
    }

    /**
     * Generate encryption key (consistent across all services).
     */
    public function getEncryptionKey(): string
    {
        // Generate the same encryption key as in other services
        $serverName = $_SERVER['SERVER_NAME'] ?? 'localhost';
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/';

        return hash('sha256', $serverName.$documentRoot, true);
    }

    /**
     * Encrypt API key for storage.
     */
    public function encryptApiKey(string $apiKey): string
    {
        $key = $this->getEncryptionKey();
        $method = 'aes-256-cbc';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

        $encrypted = openssl_encrypt($apiKey, $method, $key, 0, $iv);

        // Combine IV and encrypted data
        return base64_encode($iv.$encrypted);
    }

    /**
     * Decrypt API key from storage.
     */
    public function decryptApiKey(string $encryptedData): string|null
    {
        try {
            $method = 'aes-256-cbc';

            $data = base64_decode($encryptedData, true);
            if (false === $data) {
                return null;
            }

            $ivLength = openssl_cipher_iv_length($method);
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            foreach ($this->getEncryptionKeyCandidates() as $key) {
                $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);
                if (false !== $decrypted && $this->isValidApiKeyFormat($decrypted)) {
                    return $decrypted;
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt API key: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Encrypt an arbitrary string value (AES-256-CBC + random IV, base64-encoded).
     *
     * Same on-disk format as encryptApiKey() but makes no assumptions about the
     * plaintext, so it can be reused for non-OpenAI secrets (e.g. the premium license
     * key, which does not start with "sk-").
     */
    public function encryptValue(string $plaintext): string
    {
        $key = $this->getEncryptionKey();
        $method = 'aes-256-cbc';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));

        $encrypted = openssl_encrypt($plaintext, $method, $key, 0, $iv);

        return base64_encode($iv.$encrypted);
    }

    /**
     * Decrypt a value produced by encryptValue().
     *
     * Tries every encryption-key candidate (web/CLI host + document-root variants) so
     * a value encrypted in web context can be decrypted from the CLI cron. The
     * $validator predicate confirms the correct candidate was used (decrypting with
     * the wrong key can return garbage rather than false).
     *
     * @param callable(string):bool $validator returns true when the decrypted value is well-formed
     */
    public function decryptValue(string $encryptedData, callable $validator): string|null
    {
        try {
            $method = 'aes-256-cbc';

            $data = base64_decode($encryptedData, true);
            if (false === $data) {
                return null;
            }

            $ivLength = openssl_cipher_iv_length($method);
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);

            foreach ($this->getEncryptionKeyCandidates() as $key) {
                $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);
                if (false !== $decrypted && $validator($decrypted)) {
                    return $decrypted;
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to decrypt value: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Validate the premium license key format ("JH-AI-" + uppercase hex/alphanumerics).
     */
    public function isValidLicenseKeyFormat(string $key): bool
    {
        return (bool) preg_match('/^JH-AI-[A-Z0-9]{8,}$/', trim($key));
    }

    /**
     * Encrypt a premium license key for storage.
     */
    public function encryptLicenseKey(string $key): string
    {
        return $this->encryptValue(trim($key));
    }

    /**
     * Decrypt a stored premium license key. Returns null when the value is empty,
     * cannot be decrypted, or does not match the expected license key format.
     */
    public function decryptLicenseKey(string $encrypted): string|null
    {
        if ('' === $encrypted) {
            return null;
        }

        return $this->decryptValue($encrypted, fn (string $v): bool => $this->isValidLicenseKeyFormat($v));
    }

    /**
     * Process API key - decrypt if encrypted, decode if base64.
     */
    public function processApiKey(string $storedApiKey, bool $logInvalidFormat = true): string|null
    {
        if (empty($storedApiKey)) {
            return null;
        }

        // Check if this is an encrypted key (longer than 100 chars) or legacy base64
        if (\strlen($storedApiKey) > 100) {
            // This is an encrypted key
            $apiKey = $this->decryptApiKey($storedApiKey);
        } else {
            // This is a legacy base64 encoded key
            $apiKey = base64_decode($storedApiKey, true);
        }

        if (!$apiKey || !$this->isValidApiKeyFormat($apiKey)) {
            if ($logInvalidFormat) {
                $this->logger->error(
                    'Invalid API key format detected',
                    [
                        'api_key_length' => \strlen($storedApiKey),
                    ],
                );
            }

            return null;
        }

        return $apiKey;
    }

    /**
     * Validate API key format - supports all OpenAI key formats.
     */
    public function isValidApiKeyFormat(string $apiKey): bool
    {
        if (empty($apiKey)) {
            return false;
        }

        // Support all current OpenAI API key formats
        $validPrefixes = ['sk-', 'sk-proj-', 'sk-None-', 'sk-svcacct-'];

        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($apiKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build candidate encryption keys so CLI migrations can still decrypt keys that
     * were encrypted in web context (different SERVER_NAME/DOCUMENT_ROOT).
     *
     * @return array<int, string>
     */
    private function getEncryptionKeyCandidates(): array
    {
        $keys = [
            $this->getEncryptionKey(),
        ];

        $hosts = $this->getHostCandidates();
        $roots = $this->getDocumentRootCandidates();

        foreach ($hosts as $host) {
            foreach ($roots as $root) {
                $keys[] = hash('sha256', $host.$root, true);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<int, string>
     */
    private function getHostCandidates(): array
    {
        $hosts = [
            (string) ($_SERVER['SERVER_NAME'] ?? ''),
            (string) ($_SERVER['HTTP_HOST'] ?? ''),
        ];

        $appUrl = (string) ($_ENV['APP_URL'] ?? '');
        if ('' !== $appUrl) {
            $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '');
            if ('' !== $host) {
                $hosts[] = $host;
            }
        }

        if (null !== $this->connection) {
            try {
                $rows = $this->connection->fetchFirstColumn('SELECT dns FROM tl_page WHERE dns IS NOT NULL AND dns <> ""');

                foreach ($rows as $dns) {
                    $host = trim((string) $dns);
                    if ('' !== $host) {
                        $hosts[] = $host;
                    }
                }
            } catch (\Throwable) {
                // tl_page may not exist during early install/migration phases.
            }
        }

        $hosts = array_values(array_filter(array_unique($hosts), static fn (string $v): bool => '' !== $v));
        if ([] === $hosts) {
            $hosts[] = 'localhost';
        }

        return $hosts;
    }

    /**
     * @return array<int, string>
     */
    private function getDocumentRootCandidates(): array
    {
        $roots = [
            (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
            '/',
        ];

        if (null !== $this->projectDir && '' !== $this->projectDir) {
            $roots[] = rtrim($this->projectDir, '/');
        }

        if (null !== $this->projectDir && '' !== $this->projectDir && null !== $this->webDir && '' !== $this->webDir) {
            $resolvedWebDir = str_starts_with($this->webDir, '/')
                ? $this->webDir
                : rtrim($this->projectDir, '/').'/'.ltrim($this->webDir, '/');
            $roots[] = rtrim($resolvedWebDir, '/');
        }

        // Older installs sometimes rotate release folders (e.g. contao56 -> contao57). Include
        // neighboring numeric path variants to preserve decryption compatibility.
        $expandedRoots = [];

        foreach ($roots as $root) {
            $root = rtrim($root, '/');
            if ('' === $root) {
                continue;
            }

            $expandedRoots[] = $root;

            $resolved = realpath($root);
            if (false !== $resolved) {
                $expandedRoots[] = rtrim($resolved, '/');
            }

            foreach ($this->getLegacyPathVariants($root) as $variant) {
                $expandedRoots[] = $variant;
            }
        }

        return array_values(array_filter(array_unique($expandedRoots), static fn (string $v): bool => '' !== $v));
    }

    /**
     * Create path variants by decrementing numeric suffixes in path segments.
     * Example: /var/www/contao57/web -> /var/www/contao56/web.
     *
     * @return array<int, string>
     */
    private function getLegacyPathVariants(string $path): array
    {
        $trimmed = rtrim($path, '/');
        if ('' === $trimmed) {
            return [];
        }

        $leadingSlash = str_starts_with($trimmed, '/');
        $segments = array_values(array_filter(explode('/', ltrim($trimmed, '/')), static fn (string $v): bool => '' !== $v));
        $variants = [];

        foreach ($segments as $index => $segment) {
            if (!preg_match('/^(.*?)(\d+)$/', $segment, $matches)) {
                continue;
            }

            $number = (int) $matches[2];
            if ($number <= 0) {
                continue;
            }

            $altSegments = $segments;
            $altSegments[$index] = $matches[1].(string) ($number - 1);
            $variant = implode('/', $altSegments);
            if ($leadingSlash) {
                $variant = '/'.$variant;
            }

            $variants[] = $variant;
        }

        return array_values(array_unique($variants));
    }
}
