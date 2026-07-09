<?php

/*
 * This file is part of the JUHE Contao OpenAI Assistant premium add-on.
 *
 * (c) JUHE IT-solutions
 *
 * @license Proprietary - see LICENSE-PREMIUM. Usage of the premium add-on
 *          requires a valid premium subscription from JUHE IT-solutions.
 */

declare(strict_types=1);

namespace JuheItSolutions\ContaoOpenaiAssistant\Premium\Service;

use Doctrine\DBAL\Connection;
use JuheItSolutions\ContaoOpenaiAssistant\Service\EncryptionService;
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
    /**
     * Page limits per subscription plan. Fallback only - the licensing server's
     * max_crawl_pages is authoritative whenever it was delivered. Shared by the
     * save-time enforcement (OpenAiConfigListener) and the runtime cap
     * (VectorStoreAutoUpdateService) so both always resolve the same limit.
     */
    public const PLAN_PAGE_LIMITS = [
        'starter' => 20,
        'business' => 50,
    ];

    private const VALIDATION_URL = 'https://licenses.juhe-it-solutions.at/api/openai-assistant/validate';

    private const DEACTIVATE_URL = 'https://licenses.juhe-it-solutions.at/api/openai-assistant/deactivate';

    private const CACHE_TTL_ACTIVE = 604800; // 7 days after a successful "active" validation

    private const CACHE_TTL_ERROR = 3600; // 1 hour after network/endpoint errors — retry sooner

    private const CACHE_TTL_PLAN = 3600; // 1 hour: plan/max_pages re-fetched this often so upgrades propagate quickly

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
            'SELECT premium_license_key, premium_license_status, premium_license_valid_until, premium_license_checked_at, premium_license_last_success FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        if (!$config || empty($config['premium_license_key'])) {
            return false;
        }

        $checkedAt = (int) ($config['premium_license_checked_at'] ?? 0);
        $status = (string) ($config['premium_license_status'] ?? '');
        $validUntil = (int) ($config['premium_license_valid_until'] ?? 0);

        // An entitled status whose paid period has ended must not be served from the
        // cache: the server may have renewed the subscription in the meantime (new
        // current_period_end), so force a revalidation instead of reporting inactive
        // - or, worse, active - from stale data.
        $expiredEntitlement = $this->isEntitledStatus($status) && $validUntil > 0 && time() >= $validUntil;

        // Fresh cache hit: decide from the cached status alone — no decryption, no
        // network call. This is the common path on every cron tick.
        if ($checkedAt > 0 && !$expiredEntitlement && $this->isCacheFresh($status, $checkedAt)) {
            // Even on a binary cache hit, refresh plan/max_pages hourly so plan
            // upgrades and downgrades propagate without waiting 7 days (BUG-05).
            if ($this->isEntitledStatus($status) && time() - $checkedAt > self::CACHE_TTL_PLAN) {
                $plainKey = $this->encryption->decryptLicenseKey((string) $config['premium_license_key']);
                if (null !== $plainKey) {
                    $this->revalidate($configId, $plainKey);

                    // Re-read the row so the returned entitlement reflects the value the
                    // revalidation just wrote — otherwise a plan/status change made during
                    // this refresh is answered from the pre-refresh snapshot and only takes
                    // effect on the next call.
                    $refreshed = $this->connection->fetchAssociative(
                        'SELECT premium_license_status, premium_license_valid_until, premium_license_checked_at, premium_license_last_success FROM tl_openai_config WHERE id = ?',
                        [$configId],
                    );
                    if ($refreshed) {
                        $config = $refreshed;
                        $status = (string) ($refreshed['premium_license_status'] ?? '');
                    }
                }
            }

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
            'SELECT premium_license_status, premium_license_valid_until, premium_license_checked_at, premium_license_last_success, premium_license_plan, premium_license_max_pages, premium_license_cancel_at_period_end FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        try {
            $response = $this->http->request(
                'GET',
                self::VALIDATION_URL,
                [
                    // Send the key (and the non-secret install signals) in headers, not the
                    // URL, so they never land in access logs. The domain + stable install id
                    // let the licensing server tell legitimate domain moves apart from one
                    // key shared across several live installs (spec §16).
                    'headers' => $this->buildValidationHeaders($key, $configId),
                    'timeout' => 5,
                ],
            );

            $data = $response->toArray(false);
            $active = ($data['valid'] ?? false) === true;
            $status = (string) ($data['status'] ?? ($active ? 'active' : 'inactive'));
            $validUntil = isset($data['expires_at'])
                ? (new \DateTimeImmutable((string) $data['expires_at']))->getTimestamp()
                : 0;
            // Plan + page limit drive the crawl-page-selection enforcement (max_crawl_pages
            // null = enterprise/unlimited → stored as 0; the plan disambiguates).
            $plan = (string) ($data['plan'] ?? '');
            $maxPages = isset($data['max_crawl_pages']) ? (int) $data['max_crawl_pages'] : 0;
            $cancelAtPeriodEnd = ($data['cancel_at_period_end'] ?? false) === true;
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
            // Keep the previously stored plan/limit on error.
            $plan = (string) ($previous['premium_license_plan'] ?? '');
            $maxPages = (int) ($previous['premium_license_max_pages'] ?? 0);
            $cancelAtPeriodEnd = (bool) ($previous['premium_license_cancel_at_period_end'] ?? false);
        }

        $now = time();
        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET premium_license_status = ?, premium_license_valid_until = ?, premium_license_checked_at = ?, premium_license_plan = ?, premium_license_max_pages = ?, premium_license_cancel_at_period_end = ? WHERE id = ?',
            [$status, $validUntil, $now, $plan, $maxPages, $cancelAtPeriodEnd ? 1 : 0, $configId],
        );

        // Anchor for the grace window: only a validation the SERVER confirmed as valid
        // moves it. Failed or invalid checks must never refresh it, otherwise the grace
        // period would re-anchor on itself and never expire while the endpoint is
        // unreachable (permanent free premium by simply blocking the licensing domain).
        if ($active) {
            $this->connection->executeStatement(
                'UPDATE tl_openai_config SET premium_license_last_success = ? WHERE id = ?',
                [$now, $configId],
            );
        }

        return $active || ('error' === $status && $this->wasRecentlyActive($previous));
    }

    /**
     * Force an immediate remote revalidation, bypassing the cache. Used by the
     * dashboard "Refresh license status" button so plan changes (upgrades/downgrades)
     * are reflected instantly without the admin re-entering their key.
     *
     * Returns an array with:
     *   - active (bool)
     *   - plan (string) the current plan slug after revalidation
     *   - plan_changed (bool) true when the plan slug or page limit changed
     */
    public function forceRevalidate(int $configId): array
    {
        $encrypted = $this->connection->fetchOne(
            'SELECT premium_license_key FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        if (empty($encrypted)) {
            return ['active' => false, 'plan' => '', 'plan_changed' => false];
        }

        $plainKey = $this->encryption->decryptLicenseKey((string) $encrypted);
        if (null === $plainKey) {
            return ['active' => false, 'plan' => '', 'plan_changed' => false];
        }

        $before = $this->connection->fetchAssociative(
            'SELECT premium_license_plan, premium_license_max_pages FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        $active = $this->revalidate($configId, $plainKey);

        $after = $this->connection->fetchAssociative(
            'SELECT premium_license_plan, premium_license_max_pages FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        $planChanged = false !== $before && false !== $after && (
            (string) ($before['premium_license_plan'] ?? '') !== (string) ($after['premium_license_plan'] ?? '')
            || ((int) ($before['premium_license_max_pages'] ?? 0)) !== (int) ($after['premium_license_max_pages'] ?? 0)
        );

        return [
            'active' => $active,
            'plan' => (string) (false !== $after ? ($after['premium_license_plan'] ?? '') : ''),
            'plan_changed' => $planChanged,
        ];
    }

    /**
     * Remote validation without persisting status (used by the backend "check key"
     * button before the config record is saved).
     */
    public function validatePlainKey(string $key): bool
    {
        try {
            $response = $this->http->request(
                'GET',
                self::VALIDATION_URL,
                [
                    // Pre-save "check key" button: send the key + domain, but no install id, so
                    // the server records no seat for a key that may not yet be saved/owned.
                    'headers' => $this->buildValidationHeaders($key, null),
                    'timeout' => 5,
                ],
            );

            $data = $response->toArray(false);

            return ($data['valid'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Release this install's seat on the licensing server. Called once, from the config's
     * ondelete_callback, while the row still exists. Best-effort: a failed/unreachable call
     * only leaves the seat claimed server-side until it naturally expires - it never blocks
     * or rolls back the local config deletion.
     */
    public function deactivate(int $configId): void
    {
        $config = $this->connection->fetchAssociative(
            'SELECT premium_license_key, premium_license_install_id FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        if (!$config || empty($config['premium_license_key']) || empty($config['premium_license_install_id'])) {
            // No license was ever activated for this install - nothing to release.
            return;
        }

        $plainKey = $this->encryption->decryptLicenseKey((string) $config['premium_license_key']);
        if (null === $plainKey) {
            return;
        }

        $headers = ['X-License-Key' => $plainKey, 'X-Install-Id' => (string) $config['premium_license_install_id']];
        $domain = $this->resolveSiteDomain();
        if (null !== $domain) {
            $headers['X-Install-Domain'] = $domain;
        }

        try {
            $this->http->request('POST', self::DEACTIVATE_URL, ['headers' => $headers, 'timeout' => 10]);
        } catch (\Throwable) {
            // Best-effort only: the seat may remain claimed server-side until it expires.
        }
    }

    /**
     * Cache-only entitlement check: decides from the persisted license state without
     * ever decrypting the key or making a network call. Used on hot render paths
     * (backend menu build, config form load) where a blocking HTTP request is not
     * acceptable.
     *
     * Deliberately OPTIMISTIC: an entitled status whose valid_until has passed still
     * counts, because the subscription may have renewed since the last check and only
     * a remote revalidation can tell. Hiding the premium UI here would also hide the
     * dashboard entry - the very place where the full check (and the "Refresh
     * license" button) would heal the stale cache. Every enforcement path (save
     * guards, manual dispatch, sync run, dashboard render) uses the strict
     * isLicenseActive() instead.
     */
    public function isLicenseActiveCached(int $configId): bool
    {
        $config = $this->connection->fetchAssociative(
            'SELECT premium_license_key, premium_license_status, premium_license_valid_until, premium_license_checked_at, premium_license_last_success FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        if (!$config || empty($config['premium_license_key'])) {
            return false;
        }

        $status = (string) ($config['premium_license_status'] ?? '');

        if ($this->isEntitledStatus($status)) {
            return true;
        }

        return 'error' === $status && $this->wasRecentlyActive($config);
    }

    /**
     * Resolve the effective crawl-page limit for a plan. Returns null when no limit
     * applies: empty plan (not yet validated) or "enterprise" (unlimited). Prefers the
     * server-delivered max_crawl_pages; falls back to PLAN_PAGE_LIMITS so a missing
     * value never silently grants an unlimited scope on a limited plan.
     */
    public static function resolvePageLimit(string $plan, int $maxPages): int|null
    {
        if ('' === $plan || 'enterprise' === $plan) {
            return null;
        }

        if ($maxPages > 0) {
            return $maxPages;
        }

        return self::PLAN_PAGE_LIMITS[$plan] ?? null;
    }

    /**
     * Build the headers for a validation request. The key is always sent; the install id is
     * added only when a config id is given (persisted revalidation), and the domain whenever
     * it can be resolved. All three go in headers so they stay out of access-log URLs.
     *
     * @return array<string, string>
     */
    private function buildValidationHeaders(string $key, int|null $configId): array
    {
        $headers = ['X-License-Key' => $key];

        if (null !== $configId) {
            $installId = $this->resolveInstallId($configId);
            if ('' !== $installId) {
                $headers['X-Install-Id'] = $installId;
            }
        }

        $domain = $this->resolveSiteDomain();
        if (null !== $domain) {
            $headers['X-Install-Domain'] = $domain;
        }

        return $headers;
    }

    /**
     * Stable, non-secret per-installation id. Generated once on first validation and stored
     * on the config record; identifies "the same install" even if its domain later changes.
     */
    private function resolveInstallId(int $configId): string
    {
        $existing = $this->connection->fetchOne(
            'SELECT premium_license_install_id FROM tl_openai_config WHERE id = ?',
            [$configId],
        );

        if (\is_string($existing) && '' !== $existing) {
            return $existing;
        }

        $installId = bin2hex(random_bytes(16));
        $this->connection->executeStatement(
            'UPDATE tl_openai_config SET premium_license_install_id = ? WHERE id = ?',
            [$installId, $configId],
        );

        return $installId;
    }

    /**
     * Resolve the site's canonical domain on the CLI (no request available): prefer the
     * configured root-page DNS, then fall back to the host of the most recently indexed
     * search URL. Returns null if neither is set (seat then stays untracked — fine for soft
     * enforcement). The licensing server normalizes/strips the value further.
     */
    private function resolveSiteDomain(): string|null
    {
        $dns = $this->connection->fetchOne(
            "SELECT dns FROM tl_page WHERE type = 'root' AND dns != '' ORDER BY id ASC LIMIT 1",
        );
        if (\is_string($dns) && '' !== trim($dns)) {
            return trim($dns);
        }

        $url = $this->connection->fetchOne('SELECT url FROM tl_search ORDER BY tstamp DESC LIMIT 1');
        if (\is_string($url) && '' !== $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (\is_string($host) && '' !== $host) {
                return $host;
            }
        }

        return null;
    }

    private function isCacheFresh(string $status, int $checkedAt): bool
    {
        $ttl = 'error' === $status ? self::CACHE_TTL_ERROR : self::CACHE_TTL_ACTIVE;

        return time() - $checkedAt < $ttl;
    }

    /**
     * Mirrors the server-side entitlement rule (isLocationSubscriptionEntitled): an
     * entitled status only counts while its paid period has not ended. Without the
     * valid_until check, a cached "past_due"/"active" status would keep granting
     * premium for up to CACHE_TTL_ACTIVE although the server already answered
     * valid=false for it.
     *
     * @param array<string, mixed> $config
     */
    private function isActiveStatus(string $status, array $config): bool
    {
        if ($this->isEntitledStatus($status)) {
            $validUntil = (int) ($config['premium_license_valid_until'] ?? 0);

            return 0 === $validUntil || time() < $validUntil;
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

        $status = (string) ($config['premium_license_status'] ?? '');

        // Accept entitled statuses (normal path) and 'error' (grace already triggered once:
        // revalidate() writes 'error' to the DB when grace fires, so subsequent cache-hit
        // calls within CACHE_TTL_ERROR must still honour the grace window — REV-01).
        if (!$this->isEntitledStatus($status) && 'error' !== $status) {
            return false;
        }

        $lastSuccess = (int) ($config['premium_license_last_success'] ?? 0);
        $checkedAt = (int) ($config['premium_license_checked_at'] ?? 0);
        $validUntil = (int) ($config['premium_license_valid_until'] ?? 0);

        // The grace window is anchored on the last SERVER-CONFIRMED validation, never on
        // checked_at: the error path refreshes checked_at on every failed attempt, so
        // anchoring on it would let the grace period re-extend itself indefinitely.
        // For rows written before premium_license_last_success existed (value 0), an
        // entitled status falls back to checked_at — under the old semantics that was
        // the time of the last successful check. 'error' rows get no such fallback.
        $anchor = max($lastSuccess, $this->isEntitledStatus($status) ? $checkedAt : 0);

        return $anchor > 0
            && (
                time() - $anchor < self::GRACE_PERIOD
                || ($validUntil > 0 && time() < $validUntil)
            );
    }

    private function isEntitledStatus(string $status): bool
    {
        return \in_array($status, ['active', 'trialing', 'past_due', 'complimentary'], true);
    }
}
