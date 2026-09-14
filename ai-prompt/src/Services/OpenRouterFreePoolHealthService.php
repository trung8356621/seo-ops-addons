<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use App\Support\RuntimeLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiFailureDecision;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\FreePoolHealthState;

/**
 * Per-connection OpenRouter Free Pool circuit breaker.
 * SSOT: ApiConnection.metadata.openrouter_free_pool_health
 */
final class OpenRouterFreePoolHealthService
{
    public const META_KEY = 'openrouter_free_pool_health';

    public const SKIP_HARD_LOCKED = 'free_pool_hard_locked';

    public const SKIP_DAILY_QUOTA = 'free_pool_daily_quota_locked';

    public const SKIP_RESYNCING = 'free_pool_resyncing';

    public const SKIP_WAITING_PROBE = 'free_pool_waiting_probe';

    public const SKIP_PROBE_IN_PROGRESS = 'free_pool_probe_in_progress';

    public const SKIP_UNAVAILABLE = 'free_pool_unavailable';

    public const REASON_NO_HEALTHY_FREE_POOL = 'NO_HEALTHY_FREE_POOL';

    private const PROBE_LOCK_TTL_SECONDS = 120;

    private const RESYNC_LOCK_TTL_SECONDS = 300;

    private const PERSIST_RETRIES = 3;

    /** @var array<int, string> connection_id => probe owner token for this process */
    private static array $probeOwners = [];

    public function __construct(
        private readonly FreePoolResilienceSettingsService $settings = new FreePoolResilienceSettingsService(),
    ) {}

    public static function clearProbeOwners(): void
    {
        self::$probeOwners = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(ApiConnection $connection): array
    {
        $meta = is_array($connection->metadata ?? null) ? $connection->metadata : [];
        $bag = is_array($meta[self::META_KEY] ?? null) ? $meta[self::META_KEY] : [];

        return $this->normalizeBag($bag);
    }

    public function state(ApiConnection $connection): FreePoolHealthState
    {
        $fresh = $this->freshConnection($connection) ?? $connection;
        $snap = $this->snapshot($fresh);
        $state = FreePoolHealthState::tryFrom((string) ($snap['free_pool_state'] ?? ''))
            ?? FreePoolHealthState::Healthy;

        if ($state === FreePoolHealthState::DailyQuotaLocked && $this->lockUntilPassed($snap)) {
            $snap['free_pool_state'] = FreePoolHealthState::Healthy->value;
            $snap['free_pool_lock_reason'] = null;
            $snap['free_pool_lock_until'] = null;
            $this->persist($fresh, $snap);

            return FreePoolHealthState::Healthy;
        }

        if ($state === FreePoolHealthState::HardLocked && $this->lockUntilPassed($snap)) {
            $this->tryClaimProbe($fresh);

            return $this->stateAfterRefresh($fresh);
        }

        if ($state === FreePoolHealthState::WaitingProbe && $this->probeClaimExpired($snap)) {
            $this->clearProbeClaim($snap);
            $this->persist($fresh, $snap);
            $this->tryClaimProbe($fresh);

            return $this->stateAfterRefresh($fresh);
        }

        return $state;
    }

    /**
     * Whether normal Free Pool expansion may enumerate members for this connection.
     */
    public function allowsMemberExpansion(ApiConnection $connection): bool
    {
        $state = $this->state($connection);

        return match ($state) {
            FreePoolHealthState::Healthy,
            FreePoolHealthState::Degraded => true,
            FreePoolHealthState::WaitingProbe => $this->ownsProbeClaim($connection),
            default => false,
        };
    }

    /**
     * Skip reason for a free candidate on this connection, or null if runnable.
     */
    public function skipReasonForCandidate(RoutedAiCandidate $candidate): ?string
    {
        if (! $candidate->isFree) {
            return null;
        }

        $connection = $candidate->connection;
        $isOpenRouter = (string) $connection->provider === ApiConnectionProviders::OPENROUTER;

        if ($isOpenRouter) {
            $state = $this->state($connection);
            $skip = match ($state) {
                FreePoolHealthState::HardLocked => self::SKIP_HARD_LOCKED,
                FreePoolHealthState::DailyQuotaLocked => self::SKIP_DAILY_QUOTA,
                FreePoolHealthState::Resyncing => self::SKIP_RESYNCING,
                FreePoolHealthState::WaitingProbe => $this->ownsProbeClaim($connection)
                    ? null
                    : self::SKIP_PROBE_IN_PROGRESS,
                FreePoolHealthState::Unavailable => self::SKIP_UNAVAILABLE,
                default => null,
            };
            if ($skip !== null) {
                return $skip;
            }
        }

        return $this->modelCooldownSkip($candidate);
    }

    /**
     * Drop blocked pools; keep at most one probe candidate for the probe owner.
     *
     * @param  list<RoutedAiCandidate>  $candidates
     * @return list<RoutedAiCandidate>
     */
    public function filterCandidatesForCircuit(array $candidates): array
    {
        $out = [];
        $probeTaken = [];
        foreach ($candidates as $candidate) {
            if (! $candidate->isFree) {
                $out[] = $candidate;

                continue;
            }

            $connection = $candidate->connection;
            $isOpenRouter = (string) $connection->provider === ApiConnectionProviders::OPENROUTER;
            if (! $isOpenRouter) {
                if ($this->modelCooldownSkip($candidate) === null) {
                    $out[] = $candidate;
                }

                continue;
            }

            $state = $this->state($connection);
            if (in_array($state, [
                FreePoolHealthState::HardLocked,
                FreePoolHealthState::DailyQuotaLocked,
                FreePoolHealthState::Resyncing,
                FreePoolHealthState::Unavailable,
            ], true)) {
                continue;
            }

            if ($state === FreePoolHealthState::WaitingProbe) {
                if (! $this->ownsProbeClaim($connection)) {
                    continue;
                }
                $cid = (int) $connection->id;
                if (isset($probeTaken[$cid]) || $this->modelCooldownSkip($candidate) !== null) {
                    continue;
                }
                $probeTaken[$cid] = true;
                $out[] = $candidate;

                continue;
            }

            if ($this->modelCooldownSkip($candidate) !== null) {
                continue;
            }
            $out[] = $candidate;
        }

        return $out;
    }

    public function maybeForceCatalogResyncOnStrongStale(
        ApiConnection $connection,
        AiFailureDecision $decision,
        int $userId,
    ): void {
        if ($decision->category !== AiFailureClass::ModelNotFound) {
            return;
        }
        if ($this->isDailyQuotaActive($connection)) {
            return;
        }
        $cfg = $this->settings->get($userId);
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $this->maybeEnqueueCatalogResync($connection, $snap, $cfg, $userId, forced: true);
    }

    public function recordQualifyingFailure(
        int $userId,
        RoutedAiCandidate $candidate,
        AiFailureDecision $decision,
        int $eligiblePoolSize,
    ): void {
        if (! $candidate->isFree) {
            return;
        }
        if (! $this->countsTowardPoolRatio($decision)) {
            if ($decision->category === AiFailureClass::DailyFreeQuotaExhausted
                || $decision->suppressConnectionFree
            ) {
                $this->lockDailyQuota($candidate->connection, $decision);
            }

            return;
        }

        $connection = $candidate->connection;
        $cfg = $this->settings->get($userId);
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $now = Carbon::now();

        // Daily quota has absolute priority until reset.
        if ($this->isDailyQuotaSnapActive($snap)) {
            $this->applyModelStrike($snap, $this->physicalKey($candidate), $cfg, $now);
            $snap['free_pool_state'] = FreePoolHealthState::DailyQuotaLocked->value;
            $this->persist($connection, $snap);

            return;
        }

        $windowMinutes = (int) $cfg[FreePoolResilienceSettingsService::KEY_FAILURE_WINDOW_MINUTES];
        $cutoff = $now->copy()->subMinutes($windowMinutes)->toIso8601String();

        $modelKey = $this->physicalKey($candidate);
        $failures = is_array($snap['recent_failures'] ?? null) ? $snap['recent_failures'] : [];
        $failures[] = [
            'model_key' => $modelKey,
            'seo_ai_model_id' => $candidate->seoAiModelId,
            'at' => $now->toIso8601String(),
            'class' => $decision->category->value,
        ];
        $failures = array_values(array_filter(
            $failures,
            static fn (mixed $row): bool => is_array($row) && (string) ($row['at'] ?? '') >= $cutoff,
        ));

        $distinct = [];
        foreach ($failures as $row) {
            $k = (string) ($row['model_key'] ?? '');
            if ($k !== '') {
                $distinct[$k] = true;
            }
        }
        $failedDistinct = count($distinct);
        $eligible = max(0, $eligiblePoolSize);
        $ratio = $eligible > 0 ? ($failedDistinct / $eligible) * 100.0 : 0.0;

        $snap['recent_failures'] = $failures;
        $snap['failed_distinct_models'] = $failedDistinct;
        $snap['eligible_model_count'] = $eligible;
        $snap['failure_ratio'] = round($ratio, 2);
        $snap['last_failure_at'] = $now->toIso8601String();

        $this->applyModelStrike($snap, $modelKey, $cfg, $now);

        $minSample = (int) $cfg[FreePoolResilienceSettingsService::KEY_MIN_DISTINCT_FAILURE_MODELS];
        $threshold = (int) $cfg[FreePoolResilienceSettingsService::KEY_HARD_LOCK_RATIO_PERCENT];
        $allFail = (bool) $cfg[FreePoolResilienceSettingsService::KEY_HARD_LOCK_WHEN_ALL_ATTEMPTED_FAIL];

        $shouldHardLock = false;
        $lockReason = null;
        if ($eligible <= 0) {
            $snap['free_pool_state'] = FreePoolHealthState::Unavailable->value;
            $snap['free_pool_lock_reason'] = self::REASON_NO_HEALTHY_FREE_POOL;
            $this->persist($connection, $snap);

            return;
        }
        if ($failedDistinct >= $minSample && $ratio >= $threshold) {
            $shouldHardLock = true;
            $lockReason = sprintf('broad_failure_ratio_%.0f_percent', $ratio);
        } elseif ($allFail && $failedDistinct >= $eligible && $failedDistinct >= 1) {
            $shouldHardLock = true;
            $lockReason = 'all_eligible_attempted_failed';
        }

        if ($shouldHardLock) {
            $this->enterHardLock($connection, $snap, $lockReason ?? 'hard_lock', $cfg, $userId);
        } else {
            $state = FreePoolHealthState::tryFrom((string) ($snap['free_pool_state'] ?? ''))
                ?? FreePoolHealthState::Healthy;
            if ($state === FreePoolHealthState::WaitingProbe && $this->ownsProbeClaim($connection)) {
                $this->persist($connection, $snap);
                $this->recordProbeFailure($connection, $userId);

                return;
            }
            if ($failedDistinct > 0 && $state === FreePoolHealthState::Healthy) {
                $snap['free_pool_state'] = FreePoolHealthState::Degraded->value;
            }
            $this->persist($connection, $snap);
        }
    }

    public function recordSuccess(RoutedAiCandidate $candidate): void
    {
        if (! $candidate->isFree) {
            return;
        }
        $connection = $candidate->connection;
        $userId = (int) ($connection->user_id ?? 0);
        $cfg = $this->settings->get($userId);
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $modelKey = $this->physicalKey($candidate);
        $models = is_array($snap['model_strikes'] ?? null) ? $snap['model_strikes'] : [];
        unset($models[$modelKey]);
        $snap['model_strikes'] = $models;

        $state = FreePoolHealthState::tryFrom((string) ($snap['free_pool_state'] ?? ''))
            ?? FreePoolHealthState::Healthy;
        if ($state === FreePoolHealthState::WaitingProbe || $state === FreePoolHealthState::HardLocked) {
            $needed = max(1, (int) $cfg[FreePoolResilienceSettingsService::KEY_SUCCESS_PROBES_TO_UNLOCK]);
            $successes = (int) ($snap['consecutive_probe_successes'] ?? 0) + 1;
            $snap['consecutive_probe_successes'] = $successes;
            $snap['last_probe_at'] = Carbon::now()->toIso8601String();
            if ($successes >= $needed) {
                $snap['free_pool_state'] = FreePoolHealthState::Healthy->value;
                $snap['free_pool_locked_at'] = null;
                $snap['free_pool_lock_until'] = null;
                $snap['free_pool_lock_reason'] = null;
                $snap['next_probe_at'] = null;
                $snap['recent_failures'] = [];
                $snap['failed_distinct_models'] = 0;
                $snap['failure_ratio'] = 0;
                $snap['consecutive_probe_failures'] = 0;
                $this->clearProbeClaim($snap);
                unset(self::$probeOwners[(int) $connection->id]);
            }
        } elseif ($state === FreePoolHealthState::Degraded) {
            $snap['free_pool_state'] = FreePoolHealthState::Healthy->value;
        }

        $this->persist($connection, $snap);
    }

    public function lockDailyQuota(ApiConnection $connection, AiFailureDecision $decision): void
    {
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $until = null;
        if (is_string($decision->freeDailyResetAt) && $decision->freeDailyResetAt !== '') {
            try {
                $until = Carbon::parse($decision->freeDailyResetAt);
            } catch (\Throwable) {
                $until = null;
            }
        }
        if ($until === null && is_string($decision->rateLimitReset) && $decision->rateLimitReset !== '') {
            try {
                $until = Carbon::parse($decision->rateLimitReset);
            } catch (\Throwable) {
                $until = null;
            }
        }
        $until ??= Carbon::now()->addDay()->startOfDay();
        $snap['free_pool_state'] = FreePoolHealthState::DailyQuotaLocked->value;
        $snap['free_pool_locked_at'] = Carbon::now()->toIso8601String();
        $snap['free_pool_lock_until'] = $until->toIso8601String();
        $snap['free_pool_lock_reason'] = 'daily_free_quota_exhausted';
        $snap['next_probe_at'] = $until->toIso8601String();
        $this->clearProbeClaim($snap);
        $this->persist($connection, $snap);

        RuntimeLogger::info('ai.free_pool.daily_quota_locked', [
            'connection_id' => (int) $connection->id,
            'lock_until' => $snap['free_pool_lock_until'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function operationalAlerts(int $userId): array
    {
        $alerts = [];
        $pool = new OpenRouterFreePoolService();
        foreach ($pool->openRouterConnections($userId) as $connection) {
            $snap = $this->snapshot($connection);
            $state = FreePoolHealthState::tryFrom((string) ($snap['free_pool_state'] ?? ''))
                ?? FreePoolHealthState::Healthy;
            if (! $state->isLocked() && $state !== FreePoolHealthState::Degraded) {
                continue;
            }
            $alerts[] = [
                'type' => 'free_pool_health',
                'connection_id' => (int) $connection->id,
                'connection_name' => (string) $connection->name,
                'state' => $state->value,
                'pool_state' => $state->value,
                'lock_reason' => $snap['free_pool_lock_reason'] ?? null,
                'reason' => $snap['free_pool_lock_reason'] ?? null,
                'failure_ratio' => $snap['failure_ratio'] ?? null,
                'failed_distinct_models' => $snap['failed_distinct_models'] ?? null,
                'eligible_model_count' => $snap['eligible_model_count'] ?? null,
                'lock_until' => $snap['free_pool_lock_until'] ?? null,
                'next_probe_at' => $snap['next_probe_at'] ?? null,
                'last_catalog_sync_at' => $snap['last_catalog_sync_at'] ?? null,
                'last_forced_sync_at' => $snap['last_forced_sync_at'] ?? null,
                'message' => $this->userMessage($connection, $snap, $state),
            ];
        }

        return $alerts;
    }

    public function markCatalogSynced(ApiConnection $connection, bool $ok): void
    {
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $snap['last_catalog_sync_at'] = Carbon::now()->toIso8601String();
        $snap['last_catalog_sync_status'] = $ok ? 'ok' : 'failed';
        $snap['resync_in_progress'] = false;
        if (($snap['free_pool_state'] ?? '') === FreePoolHealthState::Resyncing->value) {
            $snap['free_pool_state'] = FreePoolHealthState::WaitingProbe->value;
            $cfg = $this->settings->get(0);
            $probeMin = (int) $cfg[FreePoolResilienceSettingsService::KEY_FIRST_PROBE_MINUTES];
            $snap['next_probe_at'] = Carbon::now()->addMinutes($probeMin)->toIso8601String();
        }
        $this->persist($connection, $snap);
    }

    public function recordProbeFailure(ApiConnection $connection, int $userId): void
    {
        $cfg = $this->settings->get($userId);
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        $failures = (int) ($snap['consecutive_probe_failures'] ?? 0) + 1;
        $snap['consecutive_probe_failures'] = $failures;
        $snap['consecutive_probe_successes'] = 0;
        $snap['last_probe_at'] = Carbon::now()->toIso8601String();

        $first = (int) $cfg[FreePoolResilienceSettingsService::KEY_FIRST_PROBE_MINUTES];
        $mult = (float) $cfg[FreePoolResilienceSettingsService::KEY_PROBE_BACKOFF_MULTIPLIER];
        $maxHours = (int) $cfg[FreePoolResilienceSettingsService::KEY_MAX_PROBE_HOURS];
        $delayMin = (int) min($first * ($mult ** max(0, $failures - 1)), $maxHours * 60);
        $until = Carbon::now()->addMinutes(max(1, $delayMin));
        $snap['free_pool_state'] = FreePoolHealthState::HardLocked->value;
        $snap['free_pool_lock_until'] = $until->toIso8601String();
        $snap['next_probe_at'] = $until->toIso8601String();
        $snap['free_pool_lock_reason'] = 'probe_failed';
        $this->clearProbeClaim($snap);
        unset(self::$probeOwners[(int) $connection->id]);
        $this->persist($connection, $snap);
    }

    public function ownsProbeClaim(ApiConnection $connection): bool
    {
        $cid = (int) $connection->id;
        $owner = self::$probeOwners[$cid] ?? null;
        if ($owner === null) {
            return false;
        }
        $snap = $this->snapshot($this->freshConnection($connection) ?? $connection);
        if (($snap['free_pool_state'] ?? '') !== FreePoolHealthState::WaitingProbe->value) {
            return false;
        }
        if ((string) ($snap['probe_owner'] ?? '') !== $owner) {
            return false;
        }
        if ($this->probeClaimExpired($snap)) {
            return false;
        }

        return true;
    }

    /**
     * Claim exclusive probe ownership after HARD_LOCKED lock_until expires.
     */
    public function tryClaimProbe(ApiConnection $connection): bool
    {
        $cid = (int) $connection->id;
        if ($cid <= 0) {
            return false;
        }

        $lock = Cache::lock($this->probeLockKey($cid), self::PROBE_LOCK_TTL_SECONDS);
        try {
            if (! $lock->get()) {
                return false;
            }
        } catch (\Throwable) {
            // Array/null cache drivers that lack locks — fall through to metadata claim only.
            $lock = null;
        }

        try {
            $fresh = $this->freshConnection($connection) ?? $connection;
            $snap = $this->snapshot($fresh);
            $state = FreePoolHealthState::tryFrom((string) ($snap['free_pool_state'] ?? ''))
                ?? FreePoolHealthState::Healthy;

            if ($state === FreePoolHealthState::WaitingProbe
                && ! $this->probeClaimExpired($snap)
                && is_string($snap['probe_owner'] ?? null)
                && (string) $snap['probe_owner'] !== ''
            ) {
                return false;
            }

            if ($state === FreePoolHealthState::HardLocked && ! $this->lockUntilPassed($snap)) {
                return false;
            }

            if (! in_array($state, [
                FreePoolHealthState::HardLocked,
                FreePoolHealthState::WaitingProbe,
            ], true)) {
                return false;
            }

            $owner = bin2hex(random_bytes(8));
            $now = Carbon::now();
            $snap['free_pool_state'] = FreePoolHealthState::WaitingProbe->value;
            $snap['probe_claimed_at'] = $now->toIso8601String();
            $snap['probe_owner'] = $owner;
            $snap['probe_expires_at'] = $now->copy()->addSeconds(self::PROBE_LOCK_TTL_SECONDS)->toIso8601String();
            $this->persist($fresh, $snap);
            self::$probeOwners[$cid] = $owner;

            return true;
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
     * @param  array<string, mixed>  $snap
     * @param  array<string, int|float|bool>  $cfg
     */
    private function enterHardLock(
        ApiConnection $connection,
        array $snap,
        string $reason,
        array $cfg,
        int $userId,
    ): void {
        if ($this->isDailyQuotaSnapActive($snap)) {
            $this->persist($connection, $snap);

            return;
        }

        $now = Carbon::now();
        $probeMin = (int) $cfg[FreePoolResilienceSettingsService::KEY_FIRST_PROBE_MINUTES];
        $snap['free_pool_state'] = FreePoolHealthState::HardLocked->value;
        $snap['free_pool_locked_at'] = $now->toIso8601String();
        $snap['free_pool_lock_until'] = $now->copy()->addMinutes($probeMin)->toIso8601String();
        $snap['free_pool_lock_reason'] = $reason;
        $snap['next_probe_at'] = $snap['free_pool_lock_until'];
        $snap['consecutive_probe_failures'] = (int) ($snap['consecutive_probe_failures'] ?? 0);
        $snap['consecutive_probe_successes'] = 0;
        $this->clearProbeClaim($snap);

        $this->persist($connection, $snap);
        $this->maybeEnqueueCatalogResync($connection, $snap, $cfg, $userId, forced: false);

        RuntimeLogger::warning('ai.free_pool.hard_locked', [
            'connection_id' => (int) $connection->id,
            'reason' => $reason,
            'failure_ratio' => $snap['failure_ratio'] ?? null,
            'failed_distinct' => $snap['failed_distinct_models'] ?? null,
            'eligible' => $snap['eligible_model_count'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $snap
     * @param  array<string, int|float|bool>  $cfg
     */
    private function maybeEnqueueCatalogResync(
        ApiConnection $connection,
        array &$snap,
        array $cfg,
        int $userId,
        bool $forced,
    ): void {
        $cid = (int) $connection->id;
        if ($cid <= 0) {
            return;
        }
        if ($this->isDailyQuotaSnapActive($snap)) {
            return;
        }

        $ttlHours = (int) $cfg[FreePoolResilienceSettingsService::KEY_CATALOG_FRESHNESS_HOURS];
        $minInterval = (int) $cfg[FreePoolResilienceSettingsService::KEY_FORCED_SYNC_MIN_INTERVAL_MINUTES];
        $now = Carbon::now();

        $fresh = $this->freshConnection($connection) ?? $connection;
        $snap = $this->snapshot($fresh);

        if ($forced) {
            $lastForced = $snap['last_forced_sync_at'] ?? null;
            if (is_string($lastForced) && $lastForced !== '') {
                try {
                    if (Carbon::parse($lastForced)->gt($now->copy()->subMinutes($minInterval))) {
                        return;
                    }
                } catch (\Throwable) {
                }
            }
        } else {
            $lastSync = $snap['last_catalog_sync_at'] ?? null;
            $stale = true;
            if (is_string($lastSync) && $lastSync !== '') {
                try {
                    $stale = Carbon::parse($lastSync)->lt($now->copy()->subHours($ttlHours));
                } catch (\Throwable) {
                    $stale = true;
                }
            }
            if (! $stale) {
                return;
            }
        }

        if (! empty($snap['resync_in_progress'])) {
            $snap['free_pool_state'] = FreePoolHealthState::Resyncing->value;

            return;
        }

        $lock = null;
        try {
            $lock = Cache::lock($this->resyncLockKey($cid), self::RESYNC_LOCK_TTL_SECONDS);
            if (! $lock->get()) {
                $snap['free_pool_state'] = FreePoolHealthState::Resyncing->value;

                return;
            }
        } catch (\Throwable) {
            $lock = null;
        }

        try {
            // Re-check after claim.
            $fresh = $this->freshConnection($connection) ?? $connection;
            $snap = $this->snapshot($fresh);
            if (! empty($snap['resync_in_progress'])) {
                $snap['free_pool_state'] = FreePoolHealthState::Resyncing->value;

                return;
            }
            if ($forced) {
                $lastForced = $snap['last_forced_sync_at'] ?? null;
                if (is_string($lastForced) && $lastForced !== '') {
                    try {
                        if (Carbon::parse($lastForced)->gt($now->copy()->subMinutes($minInterval))) {
                            return;
                        }
                    } catch (\Throwable) {
                    }
                }
            }

            $snap['free_pool_state'] = FreePoolHealthState::Resyncing->value;
            $snap['last_forced_sync_at'] = $now->toIso8601String();
            $snap['catalog_resync_requested_at'] = $now->toIso8601String();
            $snap['resync_in_progress'] = true;
            $this->persist($fresh, $snap);

            try {
                if (function_exists('app') && app()->bound(AiModelRouterService::class)) {
                    app(AiModelRouterService::class)->syncModelsForConnection($cid);
                    $snap['last_catalog_sync_at'] = Carbon::now()->toIso8601String();
                    $snap['last_catalog_sync_status'] = 'ok';
                    $snap['free_pool_state'] = FreePoolHealthState::WaitingProbe->value;
                }
            } catch (\Throwable $e) {
                $snap['last_catalog_sync_status'] = 'failed';
                RuntimeLogger::warning('ai.free_pool.catalog_resync_failed', [
                    'connection_id' => $cid,
                    'error' => $e->getMessage(),
                ]);
            }
            $snap['resync_in_progress'] = false;
            $this->persist($fresh, $snap);
        } finally {
            if ($lock !== null) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    private function countsTowardPoolRatio(AiFailureDecision $decision): bool
    {
        if ($decision->category === AiFailureClass::DailyFreeQuotaExhausted) {
            return false;
        }
        if (! $decision->affectsRuntimeHealth) {
            return false;
        }
        if (in_array($decision->category, [
            AiFailureClass::ProviderRefusal,
            AiFailureClass::ProviderEmptyOutput,
            AiFailureClass::ProviderInvalidOutput,
            AiFailureClass::OutputQuality,
            AiFailureClass::RequestInvalid,
            AiFailureClass::ContextLimitExceeded,
        ], true)) {
            return false;
        }

        $value = $decision->category->value;

        return in_array($value, [
            AiFailureClass::RateLimited->value,
            AiFailureClass::TransientProvider->value,
            AiFailureClass::SystemError->value,
        ], true)
            || $decision->applyCooldown
            || $decision->httpStatus === 429
            || ($decision->httpStatus !== null && $decision->httpStatus >= 500);
    }

    /**
     * @param  array<string, mixed>  $snap
     * @param  array<string, int|float|bool>  $cfg
     */
    private function applyModelStrike(array &$snap, string $modelKey, array $cfg, Carbon $now): void
    {
        $models = is_array($snap['model_strikes'] ?? null) ? $snap['model_strikes'] : [];
        $row = is_array($models[$modelKey] ?? null) ? $models[$modelKey] : ['strikes' => 0];
        $strikes = (int) ($row['strikes'] ?? 0) + 1;
        $first = (int) $cfg[FreePoolResilienceSettingsService::KEY_FIRST_COOLDOWN_MINUTES];
        $repeat = (int) $cfg[FreePoolResilienceSettingsService::KEY_REPEAT_COOLDOWN_MINUTES];
        $qThreshold = (int) $cfg[FreePoolResilienceSettingsService::KEY_QUARANTINE_FAILURE_THRESHOLD];
        $qHours = (int) $cfg[FreePoolResilienceSettingsService::KEY_QUARANTINE_HOURS];
        $maxQ = (int) $cfg[FreePoolResilienceSettingsService::KEY_MAX_QUARANTINE_HOURS];

        if ($strikes >= $qThreshold) {
            $hours = min($qHours * max(1, $strikes - $qThreshold + 1), $maxQ);
            $row['quarantine_until'] = $now->copy()->addHours($hours)->toIso8601String();
            $row['cooldown_until'] = $row['quarantine_until'];
        } else {
            $mins = $strikes === 1 ? $first : $repeat;
            $row['cooldown_until'] = $now->copy()->addMinutes($mins)->toIso8601String();
        }
        $row['strikes'] = $strikes;
        $row['last_failure_at'] = $now->toIso8601String();
        $models[$modelKey] = $row;
        $snap['model_strikes'] = $models;
    }

    private function modelCooldownSkip(RoutedAiCandidate $candidate): ?string
    {
        $snap = $this->snapshot($candidate->connection);
        $key = $this->physicalKey($candidate);
        $row = is_array(($snap['model_strikes'][$key] ?? null)) ? $snap['model_strikes'][$key] : null;
        if ($row === null) {
            return null;
        }
        foreach (['quarantine_until', 'cooldown_until'] as $field) {
            $until = $row[$field] ?? null;
            if (! is_string($until) || $until === '') {
                continue;
            }
            try {
                if (Carbon::parse($until)->isFuture()) {
                    return $field === 'quarantine_until' ? 'free_model_quarantined' : 'free_model_cooldown';
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function physicalKey(RoutedAiCandidate $candidate): string
    {
        return (int) $candidate->connection->id.'|'.$candidate->model;
    }

    /**
     * @param  array<string, mixed>  $bag
     * @return array<string, mixed>
     */
    private function normalizeBag(array $bag): array
    {
        return array_merge([
            'free_pool_state' => FreePoolHealthState::Healthy->value,
            'free_pool_locked_at' => null,
            'free_pool_lock_until' => null,
            'free_pool_lock_reason' => null,
            'failed_distinct_models' => 0,
            'eligible_model_count' => 0,
            'failure_ratio' => 0,
            'recent_failures' => [],
            'model_strikes' => [],
            'last_catalog_sync_at' => null,
            'last_catalog_sync_status' => null,
            'last_forced_sync_at' => null,
            'last_probe_at' => null,
            'next_probe_at' => null,
            'consecutive_probe_failures' => 0,
            'consecutive_probe_successes' => 0,
            'resync_in_progress' => false,
            'probe_claimed_at' => null,
            'probe_owner' => null,
            'probe_expires_at' => null,
        ], $bag);
    }

    /**
     * Merge only openrouter_free_pool_health into a fresh metadata blob (retry on conflict).
     *
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

        // Final best-effort merge without optimistic guard.
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

    private function stateAfterRefresh(ApiConnection $connection): FreePoolHealthState
    {
        $fresh = $this->freshConnection($connection) ?? $connection;

        return FreePoolHealthState::tryFrom((string) ($this->snapshot($fresh)['free_pool_state'] ?? ''))
            ?? FreePoolHealthState::Healthy;
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function lockUntilPassed(array $snap): bool
    {
        $until = $snap['free_pool_lock_until'] ?? $snap['next_probe_at'] ?? null;
        if (! is_string($until) || $until === '') {
            return true;
        }
        try {
            return ! Carbon::parse($until)->isFuture();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function isDailyQuotaSnapActive(array $snap): bool
    {
        $state = FreePoolHealthState::tryFrom((string) ($snap['free_pool_state'] ?? ''))
            ?? FreePoolHealthState::Healthy;
        if ($state !== FreePoolHealthState::DailyQuotaLocked) {
            return false;
        }

        return ! $this->lockUntilPassed($snap);
    }

    private function isDailyQuotaActive(ApiConnection $connection): bool
    {
        return $this->isDailyQuotaSnapActive(
            $this->snapshot($this->freshConnection($connection) ?? $connection),
        );
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function probeClaimExpired(array $snap): bool
    {
        $until = $snap['probe_expires_at'] ?? null;
        if (! is_string($until) || $until === '') {
            return true;
        }
        try {
            return ! Carbon::parse($until)->isFuture();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function clearProbeClaim(array &$snap): void
    {
        $snap['probe_claimed_at'] = null;
        $snap['probe_owner'] = null;
        $snap['probe_expires_at'] = null;
    }

    private function probeLockKey(int $connectionId): string
    {
        return 'openrouter_free_pool_probe:'.$connectionId;
    }

    private function resyncLockKey(int $connectionId): string
    {
        return 'openrouter_free_pool_resync:'.$connectionId;
    }

    /**
     * @param  array<string, mixed>  $snap
     */
    private function userMessage(ApiConnection $connection, array $snap, FreePoolHealthState $state): string
    {
        $name = trim((string) $connection->name) !== '' ? (string) $connection->name : 'OpenRouter';
        $failed = (int) ($snap['failed_distinct_models'] ?? 0);
        $eligible = (int) ($snap['eligible_model_count'] ?? 0);
        $reason = (string) ($snap['free_pool_lock_reason'] ?? '');

        return match ($state) {
            FreePoolHealthState::HardLocked => sprintf(
                '%s Free đang tạm khóa — %d/%d model miễn phí gặp lỗi trong thời gian ngắn%s. Hệ thống đã dừng thử Free để tránh retry lặp lại.',
                $name,
                $failed,
                max($eligible, $failed),
                $reason !== '' ? ' ('.$reason.')' : '',
            ),
            FreePoolHealthState::DailyQuotaLocked => sprintf(
                '%s Free đã hết hạn mức ngày — tạm khóa Free lane đến khi reset.',
                $name,
            ),
            FreePoolHealthState::WaitingProbe => sprintf(
                '%s Free đang chờ probe trước khi mở lại%s.',
                $name,
                $reason !== '' ? ' ('.$reason.')' : '',
            ),
            FreePoolHealthState::Resyncing => sprintf(
                '%s Free đang đồng bộ lại catalog.',
                $name,
            ),
            default => sprintf('%s Free: %s', $name, $state->value),
        };
    }
}
