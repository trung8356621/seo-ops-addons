<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;

/**
 * Generic per-connection model catalog freshness.
 *
 * Owns only metadata.model_catalog. Free Pool may READ this state;
 * it must not maintain a competing catalog freshness authority.
 */
final class AiModelCatalogFreshnessService
{
    public const META_KEY = 'model_catalog';

    public const STATUS_FRESH = 'fresh';

    public const STATUS_STALE = 'stale';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEVER_SYNCED = 'never_synced';

    private const PERSIST_RETRIES = 5;

    public function __construct(
        private readonly AiModelCatalogSettingsService $settings = new AiModelCatalogSettingsService(),
        private readonly AiProviderModelCatalogGateway $gateway = new AiProviderModelCatalogGateway(),
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(ApiConnection $connection): array
    {
        $meta = is_array($connection->metadata ?? null) ? $connection->metadata : [];
        $bag = is_array($meta[self::META_KEY] ?? null) ? $meta[self::META_KEY] : [];

        return $this->normalizeBag($bag);
    }

    public function isCatalogFresh(ApiConnection $connection, int $userId = 0): bool
    {
        if (! $this->gateway->supportsDiscovery($connection)) {
            return true;
        }

        $cfg = $this->settings->get($userId);
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $lastSuccess = $snap['last_success_at'] ?? null;
        if (! is_string($lastSuccess) || $lastSuccess === '') {
            return false;
        }

        try {
            $ttlHours = (int) $cfg[AiModelCatalogSettingsService::KEY_FRESHNESS_HOURS];

            return Carbon::parse($lastSuccess)->gte(Carbon::now()->subHours($ttlHours));
        } catch (\Throwable) {
            return false;
        }
    }

    public function catalogStatus(ApiConnection $connection, int $userId = 0): string
    {
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $stored = (string) ($snap['status'] ?? self::STATUS_NEVER_SYNCED);
        if ($stored === self::STATUS_SYNCING) {
            return self::STATUS_SYNCING;
        }
        if ($stored === self::STATUS_NEVER_SYNCED
            || (($snap['last_success_at'] ?? null) === null || $snap['last_success_at'] === '')) {
            return self::STATUS_NEVER_SYNCED;
        }
        if ($this->isCatalogFresh($connection, $userId)) {
            return self::STATUS_FRESH;
        }

        return $stored === self::STATUS_FAILED ? self::STATUS_FAILED : self::STATUS_STALE;
    }

    public function lastSuccessfulSync(ApiConnection $connection): ?Carbon
    {
        $at = $this->snapshot($connection)['last_success_at'] ?? null;
        if (! is_string($at) || $at === '') {
            return null;
        }
        try {
            return Carbon::parse($at);
        } catch (\Throwable) {
            return null;
        }
    }

    public function catalogAgeSeconds(ApiConnection $connection): ?int
    {
        $last = $this->lastSuccessfulSync($connection);
        if ($last === null) {
            return null;
        }

        return max(0, (int) $last->diffInSeconds(Carbon::now(), false));
    }

    /**
     * Routing read-path: if stale, try non-blocking single-flight refresh; always keep LKG usable.
     */
    public function ensureFreshEnough(ApiConnection $connection, int $userId = 0): void
    {
        if (! $this->gateway->supportsDiscovery($connection)) {
            return;
        }
        $cfg = $this->settings->get($userId);
        if (! (bool) $cfg[AiModelCatalogSettingsService::KEY_AUTO_REFRESH_ENABLED]) {
            return;
        }
        if ($this->isCatalogFresh($connection, $userId)) {
            return;
        }

        $this->requestRefresh($connection, $userId, forced: false, blocking: false);
    }

    /**
     * Canonical sync entry for manual + automatic paths.
     *
     * @return array{ok: bool, skipped: bool, reason: string, status: string}
     */
    public function requestRefresh(
        ApiConnection $connection,
        int $userId = 0,
        bool $forced = false,
        bool $blocking = true,
        bool $respectForcedDebounce = true,
    ): array {
        $cid = (int) $connection->id;
        if ($cid <= 0 || ! $this->gateway->supportsDiscovery($connection)) {
            return [
                'ok' => false,
                'skipped' => true,
                'reason' => 'unsupported_provider',
                'status' => self::STATUS_NEVER_SYNCED,
            ];
        }

        $cfg = $this->settings->get($userId);
        $fresh = $this->freshConnection($connection) ?? $connection;
        $snap = $this->snapshot($fresh);

        if ($forced && $respectForcedDebounce) {
            $minInterval = (int) $cfg[AiModelCatalogSettingsService::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES];
            $lastForced = $snap['last_forced_sync_at'] ?? null;
            if (is_string($lastForced) && $lastForced !== '') {
                try {
                    if (Carbon::parse($lastForced)->gt(Carbon::now()->subMinutes($minInterval))) {
                        return [
                            'ok' => false,
                            'skipped' => true,
                            'reason' => 'forced_sync_debounced',
                            'status' => $this->catalogStatus($fresh, $userId),
                        ];
                    }
                } catch (\Throwable) {
                }
            }
        }

        $lockTtl = max(60, (int) $cfg[AiModelCatalogSettingsService::KEY_SYNC_LOCK_MINUTES] * 60);
        $lock = null;
        try {
            $lock = Cache::lock($this->syncLockKey($cid), $lockTtl);
            if ($blocking) {
                if (! $lock->block(3)) {
                    return [
                        'ok' => false,
                        'skipped' => true,
                        'reason' => 'sync_in_progress',
                        'status' => self::STATUS_SYNCING,
                    ];
                }
            } elseif (! $lock->get()) {
                return [
                    'ok' => false,
                    'skipped' => true,
                    'reason' => 'sync_in_progress',
                    'status' => self::STATUS_SYNCING,
                ];
            }
        } catch (\Throwable) {
            $lock = null;
        }

        try {
            return $this->runSyncUnderLock($fresh, $userId, $forced, $cfg, $respectForcedDebounce);
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Strong-stale (model_not_found / unavailable): debounced forced refresh.
     * Generic 429 / 5xx / quality must NOT call this.
     *
     * @return array{ok: bool, skipped: bool, reason: string, status: string}|null
     */
    public function onStrongStaleModelError(
        ApiConnection $connection,
        AiFailureDecision $decision,
        int $userId = 0,
    ): ?array {
        if (! $this->isStrongStaleDecision($decision)) {
            return null;
        }
        $cfg = $this->settings->get($userId);
        if (! (bool) $cfg[AiModelCatalogSettingsService::KEY_SYNC_ON_STRONG_STALE_ERROR]) {
            return null;
        }

        return $this->requestRefresh($connection, $userId, forced: true, blocking: false);
    }

    public function isStrongStaleDecision(AiFailureDecision $decision): bool
    {
        if ($decision->category === AiFailureClass::ModelNotFound) {
            return true;
        }

        $msg = strtolower($decision->safeMessage.' '.($decision->errorCode ?? '').' '.($decision->providerErrorCode ?? ''));
        if (trim($msg) === '') {
            return false;
        }

        foreach ([
            'model_not_found',
            'unknown_model',
            'unsupported_model',
            'model_removed',
            'no_endpoint_for_model',
            'model is deprecated',
            'model has been deprecated',
            'model no longer available',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   connection_id: int,
     *   provider: string,
     *   authority_mode: string,
     *   status: string,
     *   last_success_at: ?string,
     *   catalog_age_seconds: ?int,
     *   catalog_count: int,
     *   active_count: int,
     *   last_error: ?string,
     *   syncing: bool
     * }
     */
    public function diagnostics(ApiConnection $connection, int $userId = 0): array
    {
        $fresh = $this->freshConnection($connection) ?? $connection;
        $snap = $this->snapshot($fresh);
        $counts = $this->gateway->inventoryCounts($fresh);
        $status = $this->catalogStatus($fresh, $userId);

        return [
            'connection_id' => (int) $fresh->id,
            'provider' => (string) $fresh->provider,
            'authority_mode' => $this->gateway->authorityMode($fresh)->value,
            'status' => $status,
            'last_success_at' => is_string($snap['last_success_at'] ?? null) ? $snap['last_success_at'] : null,
            'catalog_age_seconds' => $this->catalogAgeSeconds($fresh),
            'catalog_count' => (int) ($snap['catalog_count'] ?? $counts['total']),
            'active_count' => $counts['active'],
            'last_error' => is_string($snap['last_error'] ?? null) ? $snap['last_error'] : null,
            'syncing' => $status === self::STATUS_SYNCING,
        ];
    }

    private function syncLockKey(int $connectionId): string
    {
        return 'ai_model_catalog_sync:'.$connectionId;
    }

    /**
     * @param  array<string, int|bool>  $cfg
     * @return array{ok: bool, skipped: bool, reason: string, status: string}
     */
    private function runSyncUnderLock(
        ApiConnection $connection,
        int $userId,
        bool $forced,
        array $cfg,
        bool $respectForcedDebounce = true,
    ): array {
        $fresh = $this->freshConnection($connection) ?? $connection;
        $snap = $this->snapshot($fresh);
        $now = Carbon::now();

        // Re-check debounce after lock claim (strong-stale path only).
        if ($forced && $respectForcedDebounce) {
            $minInterval = (int) $cfg[AiModelCatalogSettingsService::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES];
            $lastForced = $snap['last_forced_sync_at'] ?? null;
            if (is_string($lastForced) && $lastForced !== '') {
                try {
                    if (Carbon::parse($lastForced)->gt($now->copy()->subMinutes($minInterval))) {
                        return [
                            'ok' => false,
                            'skipped' => true,
                            'reason' => 'forced_sync_debounced',
                            'status' => $this->catalogStatus($fresh, $userId),
                        ];
                    }
                } catch (\Throwable) {
                }
            }
        }

        if (! $forced && $this->isCatalogFresh($fresh, $userId)) {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'already_fresh',
                'status' => self::STATUS_FRESH,
            ];
        }

        $snap['status'] = self::STATUS_SYNCING;
        $snap['sync_started_at'] = $now->toIso8601String();
        $snap['last_sync_at'] = $now->toIso8601String();
        if ($forced) {
            $snap['last_forced_sync_at'] = $now->toIso8601String();
        }
        $this->persist($fresh, $snap);

        $ok = false;
        $error = null;
        try {
            $ok = $this->gateway->sync($fresh);
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }

        $fresh = $this->freshConnection($fresh) ?? $fresh;
        $snap = $this->snapshot($fresh);
        $counts = $this->gateway->inventoryCounts($fresh);
        $snap['last_sync_at'] = Carbon::now()->toIso8601String();
        $snap['catalog_count'] = $counts['active'];
        $snap['authority_mode'] = $this->gateway->authorityMode($fresh)->value;

        if ($ok) {
            $snap['status'] = self::STATUS_FRESH;
            $snap['last_success_at'] = $snap['last_sync_at'];
            $snap['last_error'] = null;
            $snap['last_error_at'] = null;
            $snap['catalog_hash'] = $this->computeCatalogHash($fresh);
            $this->persist($fresh, $snap);

            // OpenRouter Free Pool probe state may observe generic catalog success.
            try {
                if ((string) $fresh->provider === 'openrouter') {
                    (new OpenRouterFreePoolHealthService())->markCatalogSynced($fresh, true);
                }
            } catch (\Throwable) {
            }

            return [
                'ok' => true,
                'skipped' => false,
                'reason' => 'synced',
                'status' => self::STATUS_FRESH,
            ];
        }

        // Failed sync MUST NOT wipe last-known-good inventory.
        $keepLkg = (bool) $cfg[AiModelCatalogSettingsService::KEY_KEEP_LAST_KNOWN_GOOD];
        $snap['status'] = $keepLkg && ($snap['last_success_at'] ?? null)
            ? self::STATUS_STALE
            : self::STATUS_FAILED;
        $snap['last_error'] = mb_substr($error ?? 'catalog_sync_failed', 0, 500);
        $snap['last_error_at'] = Carbon::now()->toIso8601String();
        $this->persist($fresh, $snap);

        try {
            if ((string) $fresh->provider === 'openrouter') {
                (new OpenRouterFreePoolHealthService())->markCatalogSynced($fresh, false);
            }
        } catch (\Throwable) {
        }

        return [
            'ok' => false,
            'skipped' => false,
            'reason' => 'sync_failed',
            'status' => (string) $snap['status'],
        ];
    }

    private function computeCatalogHash(ApiConnection $connection): string
    {
        $cid = (int) $connection->id;
        $names = \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::query()
            ->where('api_connection_id', $cid)
            ->where('status', \Omnichannel\Addons\AiPrompt\Models\SeoAiModel::STATUS_ACTIVE)
            ->orderBy('raw_model_name')
            ->pluck('raw_model_name')
            ->all();

        return hash('sha256', implode("\n", array_map('strval', $names)));
    }

    /**
     * @param  array<string, mixed>  $bag
     * @return array<string, mixed>
     */
    private function normalizeBag(array $bag): array
    {
        return array_merge([
            'status' => self::STATUS_NEVER_SYNCED,
            'last_sync_at' => null,
            'last_success_at' => null,
            'last_error_at' => null,
            'last_error' => null,
            'last_forced_sync_at' => null,
            'sync_started_at' => null,
            'catalog_hash' => null,
            'catalog_count' => 0,
            'authority_mode' => null,
        ], $bag);
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function persist(ApiConnection $connection, array $snap): void
    {
        $id = (int) $connection->id;
        if ($id <= 0) {
            $meta = is_array($connection->metadata ?? null) ? $connection->metadata : [];
            $meta[self::META_KEY] = $this->normalizeBag($snap);
            $connection->setAttribute('metadata', $meta);

            return;
        }

        $normalized = $this->normalizeBag($snap);
        for ($attempt = 0; $attempt < self::PERSIST_RETRIES; $attempt++) {
            $fresh = ApiConnection::query()->find($id);
            if ($fresh === null) {
                return;
            }
            $meta = is_array($fresh->metadata ?? null) ? $fresh->metadata : [];
            // Own only model_catalog — never clobber free-pool / language / wallet keys.
            $meta[self::META_KEY] = $normalized;
            $previousUpdated = $fresh->getAttribute('updated_at');

            $query = ApiConnection::query()->whereKey($id);
            if ($previousUpdated !== null) {
                $query->where('updated_at', $previousUpdated);
            }
            $affected = $query->update([
                'metadata' => $meta,
                'updated_at' => now(),
            ]);
            if ($affected === 1 || $previousUpdated === null) {
                $connection->setAttribute('metadata', $meta);
                $connection->syncOriginalAttribute('metadata');

                return;
            }
        }

        $fresh = ApiConnection::query()->find($id);
        if ($fresh === null) {
            return;
        }
        $meta = is_array($fresh->metadata ?? null) ? $fresh->metadata : [];
        $meta[self::META_KEY] = $normalized;
        ApiConnection::query()->whereKey($id)->update([
            'metadata' => $meta,
            'updated_at' => now(),
        ]);
        $connection->setAttribute('metadata', $meta);
        $connection->syncOriginalAttribute('metadata');
    }

    private function freshConnection(ApiConnection $connection): ?ApiConnection
    {
        $id = (int) $connection->id;
        if ($id <= 0) {
            return null;
        }

        return ApiConnection::query()->find($id);
    }
}
