<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Cache;

/**
 * Site-scoped durable UI lock/status for explicit Topic recluster.
 *
 * State machine: queued → running → succeeded|failed
 * Survives Livewire F5 via Cache (not session-only).
 */
final class TopicReclusterUiState
{
    private const TTL_SECONDS = 3600;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    /** @deprecated BC alias — prefer STATUS_SUCCEEDED */
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public static function cacheKey(int $siteId): string
    {
        return 'topic_core_recluster_ui:'.$siteId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function put(int $siteId, array $payload): void
    {
        if ($siteId <= 0) {
            return;
        }
        Cache::put(self::cacheKey($siteId), $payload, self::TTL_SECONDS);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }
        $state = Cache::get(self::cacheKey($siteId));
        if (! is_array($state)) {
            return null;
        }

        // Normalize legacy "completed" → succeeded for consumers.
        if (($state['status'] ?? '') === self::STATUS_COMPLETED) {
            $state['status'] = self::STATUS_SUCCEEDED;
        }

        return $state;
    }

    public static function clear(int $siteId): void
    {
        if ($siteId <= 0) {
            return;
        }
        Cache::forget(self::cacheKey($siteId));
    }

    public static function isMutationLocked(int $siteId): bool
    {
        $state = self::get($siteId);
        if ($state === null) {
            return false;
        }
        $status = (string) ($state['status'] ?? '');

        return $status === self::STATUS_QUEUED || $status === self::STATUS_RUNNING;
    }

    public static function isActiveStatus(string $status): bool
    {
        return $status === self::STATUS_QUEUED || $status === self::STATUS_RUNNING;
    }

    public static function markQueued(int $siteId, ?string $algorithmVersion = null): void
    {
        self::put($siteId, [
            'status' => self::STATUS_QUEUED,
            'site_id' => $siteId,
            'algorithm_version' => $algorithmVersion ?? TopicReclusterAlgorithm::VERSION,
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'metrics' => null,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    public static function markRunning(int $siteId, ?string $algorithmVersion = null): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_RUNNING,
            'site_id' => $siteId,
            'algorithm_version' => $algorithmVersion
                ?? (string) ($prior['algorithm_version'] ?? TopicReclusterAlgorithm::VERSION),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => null,
            'metrics' => null,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function markSucceeded(int $siteId, array $metrics, ?string $algorithmVersion = null): void
    {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_SUCCEEDED,
            'site_id' => $siteId,
            'algorithm_version' => $algorithmVersion
                ?? (string) ($prior['algorithm_version'] ?? TopicReclusterAlgorithm::VERSION),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'error' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @deprecated Use markSucceeded
     */
    public static function markCompleted(int $siteId, array $metrics): void
    {
        self::markSucceeded($siteId, $metrics);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function markFailed(
        int $siteId,
        string $error,
        array $metrics = [],
        ?string $failureReason = null,
        ?string $algorithmVersion = null,
    ): void {
        $prior = self::get($siteId) ?? [];
        self::put($siteId, [
            'status' => self::STATUS_FAILED,
            'site_id' => $siteId,
            'algorithm_version' => $algorithmVersion
                ?? (string) ($prior['algorithm_version'] ?? TopicReclusterAlgorithm::VERSION),
            'started_at' => (string) ($prior['started_at'] ?? now()->toIso8601String()),
            'finished_at' => now()->toIso8601String(),
            'metrics' => $metrics,
            'error' => $error,
            'failure_reason' => $failureReason,
        ]);
    }
}
