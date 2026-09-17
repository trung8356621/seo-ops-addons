<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived UI lock/status for explicit site Topic recluster.
 */
final class TopicReclusterUiState
{
    private const TTL_SECONDS = 3600;

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

        return is_array($state) ? $state : null;
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

        return $status === 'queued' || $status === 'running';
    }

    public static function markQueued(int $siteId): void
    {
        self::put($siteId, [
            'status' => 'queued',
            'site_id' => $siteId,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    public static function markRunning(int $siteId): void
    {
        self::put($siteId, [
            'status' => 'running',
            'site_id' => $siteId,
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function markCompleted(int $siteId, array $metrics): void
    {
        self::put($siteId, [
            'status' => 'completed',
            'site_id' => $siteId,
            'metrics' => $metrics,
            'finished_at' => now()->toIso8601String(),
        ]);
    }

    public static function markFailed(int $siteId, string $error, array $metrics = []): void
    {
        self::put($siteId, [
            'status' => 'failed',
            'site_id' => $siteId,
            'error' => $error,
            'metrics' => $metrics,
            'finished_at' => now()->toIso8601String(),
        ]);
    }
}
