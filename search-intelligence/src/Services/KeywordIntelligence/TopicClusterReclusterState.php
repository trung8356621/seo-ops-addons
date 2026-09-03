<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Omnichannel\Addons\SearchIntelligence\Jobs\ReclusterTopicClustersJob;

/**
 * Site-scoped recluster job state + Topic mutation lock.
 * Source of truth: {@see ReclusterTopicClustersJob::resultCacheKey()}.
 */
final class TopicClusterReclusterState
{
    /** Queued job never picked up — same threshold as legacy Livewire poll. */
    public const STALE_QUEUED_SECONDS = 90;

    /**
     * Running without finish past job timeout (600s) + safety margin.
     * Do not unlock a legitimately slow job early.
     */
    public const STALE_RUNNING_SECONDS = 900;

    /**
     * @return array{
     *     status: string,
     *     queued_at?: string,
     *     started_at?: string,
     *     finished_at?: string,
     *     error?: string|null,
     *     metrics?: array<string, mixed>,
     *     queue?: string
     * }|null
     */
    public static function stateForSite(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }

        $cached = Cache::get(ReclusterTopicClustersJob::resultCacheKey($siteId));
        if (! is_array($cached)) {
            return null;
        }

        $status = (string) ($cached['status'] ?? '');
        if ($status === 'queued' && self::isQueuedStale($cached)) {
            return self::markFailed($siteId, __('seo-content-ai::filament.keyword.topic_recluster_stale_queue', [
                'queue' => ReclusterTopicClustersJob::QUEUE_NAME,
            ]), $cached);
        }

        if ($status === 'running' && self::isRunningStale($cached)) {
            return self::markFailed($siteId, __('seo-content-ai::filament.keyword.topic_recluster_stale_running'), $cached);
        }

        return $cached;
    }

    public static function isActive(int $siteId): bool
    {
        $state = self::stateForSite($siteId);
        if ($state === null) {
            return false;
        }

        $status = (string) ($state['status'] ?? '');

        return $status === 'queued' || $status === 'running';
    }

    public static function isMutationLocked(int $siteId): bool
    {
        return self::isActive($siteId);
    }

    /**
     * Backend guard for Topic topology mutations.
     * Returns true when mutation may proceed; otherwise notifies and returns false (no throw).
     */
    public static function assertMutationAllowed(int $siteId): bool
    {
        if (! self::isMutationLocked($siteId)) {
            return true;
        }

        Notification::make()
            ->title(__('seo-content-ai::filament.keyword.topic_recluster_mutations_locked'))
            ->warning()
            ->send();

        return false;
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    public static function isQueuedStale(array $cached): bool
    {
        $queuedAt = (string) ($cached['queued_at'] ?? '');
        if ($queuedAt === '') {
            return true;
        }

        try {
            return Carbon::parse($queuedAt)->lt(now()->subSeconds(self::STALE_QUEUED_SECONDS));
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  array<string, mixed>  $cached
     */
    public static function isRunningStale(array $cached): bool
    {
        $startedAt = (string) ($cached['started_at'] ?? '');
        if ($startedAt === '') {
            // No started_at — treat conservatively as not stale (worker may have just written status).
            return false;
        }

        try {
            return Carbon::parse($startedAt)->lt(now()->subSeconds(self::STALE_RUNNING_SECONDS));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return array<string, mixed>
     */
    private static function markFailed(int $siteId, string $error, array $previous): array
    {
        $payload = [
            'status' => 'failed',
            'error' => $error,
            'finished_at' => now()->toIso8601String(),
            'queue' => (string) ($previous['queue'] ?? ReclusterTopicClustersJob::QUEUE_NAME),
        ];

        Cache::put(ReclusterTopicClustersJob::resultCacheKey($siteId), $payload, 3600);

        return $payload;
    }
}
