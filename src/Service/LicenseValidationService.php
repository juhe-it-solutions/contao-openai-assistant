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
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Validates the premium license key against the JUHE licensing server.
 *
 * Uses differentiated cache TTLs and a grace period so temporary outages do not
 * block paying customers:
 *   - a successful "active" status is cached for 7 days,
 *   - an "error" (unreachable endpoint) status is re-checked after 1 hour,
 *   - if the endpoint is unreachable but the license was active within the last
 *     7 days (or before its expiry), sync runs continue (fail-safe).
 *
 * The stored license key is encrypted at rest; it is decrypted only on the stale
 * cache path, immediately before the validation HTTP call.
 */
class LicenseValidationService
{
    private const VALIDATION_URL = 'https://licenses.juhe-it-solutions.at/api/ai-assistant/validate';

    private const CACHE_TTL_ACTIVE = 604800; // 7 days after a successful "active" validation

    private const CACHE_TTL_ERROR = 3600; // 1 hour after network/endpoint errors — retry sooner

    private const GRACE_PERIOD = 604800; // 7 days: allow sync if last known good validation was recent

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $http,
        private readonly EncryptionService $encryption,
    ) {
    }

    /**
     * Returns true if the license for the given config is currently active. Uses
     * cached status when within TTL; otherwise calls revalidate().
     */
    public function isLicenseActive(int $configId): bool
    {
        $config = $this->connection->fetchAssociative(
            'SELECT premium_license_key, premium_license_status, premium_license_valid_until, premium_license_checked_at FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        if (!$config || empty($config['premium_license_key'])) {
            return false;
        }

        $checkedAt = (int) ($config['premium_license_checked_at'] ?? 0);
        $status = (string) ($config['premium_license_status'] ?? '');

        // Fresh cache hit: decide from the cached status alone — no decryption, no
        // network call. This is the common path on every cron tick.
        if ($checkedAt > 0 && $this->isCacheFresh($status, $checkedAt)) {
            return $this->isActiveStatus($status, $config);
        }

        // Stale cache: we must call the endpoint, which needs the PLAINTEXT key.
        $plainKey = $this->encryption->decryptLicenseKey((string) $config['premium_license_key']);
        if (null === $plainKey) {
            // Stored value could not be decrypted (corrupt, or wrong server key) — treat
            // as inactive rather than sending garbage to the endpoint.
            return false;
        }

        return $this->revalidate($configId, $plainKey);
    }

    /**
     * Force remote validation. Called on config save (license key changed) and when cache
     * is stale. Public so OpenAiConfigListener can invoke it from config.onsubmit.
     *
     * @param string $key the PLAINTEXT license key (callers decrypt or read it from $_POST).
     *                    This method writes only status/valid_until/checked_at — never the key itself.
     */
    public function revalidate(int $configId, string $key): bool
    {
        $previous = $this->connection->fetchAssociative(
            'SELECT premium_license_status, premium_license_valid_until, premium_license_checked_at FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        try {
            $response = $this->http->request(
                'GET',
                self::VALIDATION_URL,
                [
                    'query' => [
                        'key' => $key,
                    ],
                    'timeout' => 10,
                ],
            );

            $data = $response->toArray(false);
            $active = ($data['valid'] ?? false) === true;
            $status = $active ? 'active' : (string) ($data['status'] ?? 'inactive');
            $validUntil = isset($data['expires_at'])
                ? (new \DateTimeImmutable((string) $data['expires_at']))->getTimestamp()
                : 0;
        } catch (\Throwable) {
            // Network error: fail safe — keep last known active status within grace period
            if ($this->wasRecentlyActive($previous)) {
                $this->connection->executeStatement(
                    'UPDATE tl_openai_config SET premium_license_status = ?, premium_license_checked_at = ? WHERE id = ?',
                    ['error', time(), $configId],
                );

                return true; // grace: allow sync despite unreachable endpoint
            }

            $active = false;
            $status = 'error';
            $validUntil = (int) ($previous['premium_license_valid_until'] ?? 0);
        }

        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET premium_license_status = ?, premium_license_valid_until = ?, premium_license_checked_at = ? WHERE id = ?',
            [$active ? 'active' : $status, $validUntil, time(), $configId],
        );

        return $active || ('error' === $status && $this->wasRecentlyActive($previous));
    }

    private function isCacheFresh(string $status, int $checkedAt): bool
    {
        $ttl = 'error' === $status ? self::CACHE_TTL_ERROR : self::CACHE_TTL_ACTIVE;

        return time() - $checkedAt < $ttl;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isActiveStatus(string $status, array $config): bool
    {
        if ('active' === $status) {
            return true;
        }

        // Cached error within grace period after a previously active license
        return 'error' === $status && $this->wasRecentlyActive($config);
    }

    /**
     * @param array<string, mixed>|null $config
     */
    private function wasRecentlyActive(array|null $config): bool
    {
        if (!$config) {
            return false;
        }

        $checkedAt = (int) ($config['premium_license_checked_at'] ?? 0);
        $validUntil = (int) ($config['premium_license_valid_until'] ?? 0);

        return 'active' === ($config['premium_license_status'] ?? '')
            && $checkedAt > 0
            && (
                time() - $checkedAt < self::GRACE_PERIOD
                || ($validUntil > 0 && time() < $validUntil)
            );
    }
}
