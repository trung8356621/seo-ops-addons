<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\SeoDatabaseConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing Queue Health — scoped per SEO database connection when possible.
 *
 * Global keys remain as legacy fallback; project UI MUST pass connection_id from
 * SeoConnectionContext so stale connection A cannot paint connection B unhealthy.
 */
final class ContentProjectQueueHealthService
{
    public const CACHE_LAST_WORKER_RUN = 'seo.content_project.publish_queue.last_worker_run';

    public const CACHE_LAST_SCANNER_RUN = 'seo.content_project.publish_queue.last_scanner_run';

    public const CACHE_LAST_PUBLISHER_PROCESSED = 'seo.content_project.publish_queue.last_publisher_processed';

    public const CACHE_DUE_BACKLOG = 'seo.content_project.publish_queue.due_backlog';

    public const CACHE_LAST_SUCCESS = 'seo.content_project.publish_queue.last_success';

    public const CACHE_LAST_FAILURE = 'seo.content_project.publish_queue.last_failure';

    public const CACHE_LAST_BOOTSTRAP_FAILURE = 'seo.content_project.publish_queue.last_bootstrap_failure';

    public const CACHE_SCAN_NO_PROGRESS = 'seo.content_project.publish_queue.scan_no_progress';

    public const RUNNER_STALE_MINUTES = 5;

    /**
     * @param  list<int>|null  $siteIds
     * @return array{
     *     waiting: int,
     *     processing: int,
     *     failed: int,
     *     retrying: int,
     *     stuck_publishing: int,
     *     runner_healthy: bool,
     *     connection_bootstrap_ok: bool,
     *     runner_status: string,
     *     runner_status_label: string,
     *     runner_last_ran_minutes_ago: int|null,
     *     last_worker_run: string|null,
     *     last_success: string|null,
     *     last_failure: string|null,
     *     last_bootstrap_failure: string|null,
     *     health_connection_id: int|null,
     *     health_hash_id: string|null,
     * }
     */
    public function snapshot(?array $siteIds = null, ?int $connectionId = null): array
    {
        $waiting = 0;
        $processing = 0;
        $failed = 0;
        $retrying = 0;
        $stuck = 0;

        try {
            if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_queue_status')) {
                $base = \Omnichannel\Addons\ContentProjects\Models\SeoProjectTask::query()
                    ->active()
                    ->whereHas('project', static function ($q) use ($siteIds): void {
                        $q->whereNull('archived_at');
                        if (is_array($siteIds) && $siteIds !== []) {
                            $q->whereIn('site_id', $siteIds);
                        }
                    });

                $waiting = (int) (clone $base)->where('publish_queue_status', \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Waiting->value)->count();
                $processing = (int) (clone $base)->where('publish_queue_status', \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Processing->value)->count();
                $failed = (int) (clone $base)->where('publish_queue_status', \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Failed->value)->count();
                $retrying = (int) (clone $base)->where('publish_queue_status', \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Retrying->value)->count();

                $ttl = \Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition::TTL_MINUTES;
                $pastDueGrace = \Omnichannel\Addons\Publishing\Support\PublishingQueue\PublishingQueueStuckPublishingDefinition::PAST_DUE_GRACE_MINUTES;
                $stuck = (int) (clone $base)
                    ->where('publish_queue_status', \Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus::Processing->value)
                    ->where(static function ($q) use ($ttl, $pastDueGrace): void {
                        if (\Illuminate\Support\Facades\Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_lease_expires_at')) {
                            $q->where(static function ($lease): void {
                                $lease->whereNotNull('publish_lease_expires_at')
                                    ->where('publish_lease_expires_at', '<=', now());
                            })->orWhere(static function ($legacy) use ($ttl, $pastDueGrace): void {
                                $legacy->whereNull('publish_lease_expires_at')
                                    ->where(static function ($inner) use ($ttl, $pastDueGrace): void {
                                        $inner->whereNull('last_publish_attempt_at')
                                            ->orWhere('last_publish_attempt_at', '<=', now()->subMinutes($ttl))
                                            ->orWhere('scheduled_publish_at', '<=', now()->subMinutes($pastDueGrace));
                                    });
                            });
                        } else {
                            $q->whereNull('last_publish_attempt_at')
                                ->orWhere('last_publish_attempt_at', '<=', now()->subMinutes($ttl))
                                ->orWhere('scheduled_publish_at', '<=', now()->subMinutes($pastDueGrace));
                        }
                    })
                    ->count();
            }
        } catch (\Throwable) {
            // Health UI must not crash when SEO DB is unavailable.
        }

        $resolvedConnectionId = $connectionId;
        $resolvedHash = null;
        if ($resolvedConnectionId === null || $resolvedConnectionId <= 0) {
            $current = SeoConnectionContext::current();
            if ($current instanceof SeoDatabaseConnection) {
                $resolvedConnectionId = (int) $current->getKey();
                $resolvedHash = (string) $current->hash_id;
            }
        } else {
            $resolvedHash = SeoDatabaseConnection::query()
                ->whereKey($resolvedConnectionId)
                ->value('hash_id');
            $resolvedHash = is_string($resolvedHash) ? $resolvedHash : null;
        }

        $scopeId = ($resolvedConnectionId !== null && $resolvedConnectionId > 0)
            ? $resolvedConnectionId
            : null;

        $lastRun = $this->cacheString($this->scopedKey(self::CACHE_LAST_WORKER_RUN, $scopeId));
        $lastScanner = $this->cacheString($this->scopedKey(self::CACHE_LAST_SCANNER_RUN, $scopeId)) ?? $lastRun;
        $lastPublisher = $this->cacheString($this->scopedKey(self::CACHE_LAST_PUBLISHER_PROCESSED, $scopeId));
        $lastSuccess = $this->cacheString($this->scopedKey(self::CACHE_LAST_SUCCESS, $scopeId));
        $lastBootstrapFailure = $this->cacheString($this->scopedKey(self::CACHE_LAST_BOOTSTRAP_FAILURE, $scopeId));
        $lastFailure = $this->cacheString($this->scopedKey(self::CACHE_LAST_FAILURE, $scopeId));
        $dueBacklog = $this->cacheString($this->scopedKey(self::CACHE_DUE_BACKLOG, $scopeId));
        $scanNoProgress = $this->cacheString($this->scopedKey(self::CACHE_SCAN_NO_PROGRESS, $scopeId));

        // Legacy unscoped keys only when no connection context (should be rare in panel).
        if ($scopeId === null) {
            $lastRun ??= $this->cacheString(self::CACHE_LAST_WORKER_RUN);
            $lastScanner ??= $this->cacheString(self::CACHE_LAST_SCANNER_RUN) ?? $lastRun;
            $lastPublisher ??= $this->cacheString(self::CACHE_LAST_PUBLISHER_PROCESSED);
            $lastSuccess ??= $this->cacheString(self::CACHE_LAST_SUCCESS);
            $lastBootstrapFailure ??= $this->cacheString(self::CACHE_LAST_BOOTSTRAP_FAILURE);
            $lastFailure ??= $this->cacheString(self::CACHE_LAST_FAILURE);
            $dueBacklog ??= $this->cacheString(self::CACHE_DUE_BACKLOG);
            $scanNoProgress ??= $this->cacheString(self::CACHE_SCAN_NO_PROGRESS);
        }

        $minutesAgo = $this->minutesSinceIsoPrefix($lastScanner ?? $lastRun);
        $successMinutesAgo = $this->minutesSinceIsoPrefix($lastSuccess);
        $publisherMinutesAgo = $this->minutesSinceIsoPrefix($lastPublisher);
        $bootstrapFailMinutesAgo = $this->minutesSinceIsoPrefix($lastBootstrapFailure);

        $overdueScheduled = 0;
        $overdueRetry = 0;
        if (is_string($dueBacklog) && str_contains($dueBacklog, '|')) {
            [$overdueScheduled, $overdueRetry] = array_map('intval', explode('|', $dueBacklog, 2) + [0, 0]);
        }
        $hasOverdue = ($overdueScheduled + $overdueRetry) > 0;
        $dueTotal = $overdueScheduled + $overdueRetry;

        $noProgressReason = null;
        $noProgressCounts = [];
        if (is_string($scanNoProgress) && $scanNoProgress !== '') {
            $decoded = json_decode($scanNoProgress, true);
            if (is_array($decoded)) {
                $noProgressReason = isset($decoded['dominant_reason']) ? (string) $decoded['dominant_reason'] : null;
                $noProgressCounts = is_array($decoded['skip_reason_counts'] ?? null)
                    ? $decoded['skip_reason_counts']
                    : [];
            }
        }

        $recentBootstrapFailure = $bootstrapFailMinutesAgo !== null
            && $bootstrapFailMinutesAgo <= self::RUNNER_STALE_MINUTES;
        $schedulerHeartbeat = $minutesAgo !== null && $minutesAgo <= self::RUNNER_STALE_MINUTES;
        $publisherRecent = $publisherMinutesAgo !== null
            && $publisherMinutesAgo <= self::RUNNER_STALE_MINUTES;
        $recentSuccess = $successMinutesAgo !== null
            && $successMinutesAgo <= self::RUNNER_STALE_MINUTES;

        // Healthy only when scanner runs AND no overdue backlog (or publisher recently cleared work).
        $runnerHealthy = $schedulerHeartbeat && ! $recentBootstrapFailure && ! $hasOverdue
            && ($publisherRecent || $recentSuccess || ($overdueScheduled + $overdueRetry) === 0);

        $connectionBootstrapOk = ! $recentBootstrapFailure;

        if ($recentBootstrapFailure) {
            $status = 'connection_failed';
            $label = 'Publishing connection failed';
        } elseif (! $schedulerHeartbeat) {
            $status = 'stopped';
            $label = 'Runner stopped — scanner heartbeat stale';
        } elseif ($hasOverdue) {
            $status = 'degraded';
            $label = $this->buildOverdueLabel($dueTotal, $overdueScheduled, $overdueRetry, $noProgressReason, $noProgressCounts);
            $runnerHealthy = false;
        } elseif ($runnerHealthy) {
            $status = 'healthy';
            $label = 'Runner healthy';
        } elseif ($schedulerHeartbeat) {
            $status = 'degraded';
            $label = 'Scheduler heartbeat only — no successful publish processing';
        } else {
            $status = 'stale';
            $label = 'Runner stale / unavailable';
        }

        return [
            'waiting' => $waiting,
            'processing' => $processing,
            'failed' => $failed,
            'retrying' => $retrying,
            'stuck_publishing' => $stuck,
            'runner_healthy' => $runnerHealthy,
            'connection_bootstrap_ok' => $connectionBootstrapOk,
            'runner_status' => $status,
            'runner_status_label' => $label,
            'runner_last_ran_minutes_ago' => $minutesAgo,
            'scanner_last_ran_at' => $lastScanner,
            'publisher_last_processed_at' => $lastPublisher,
            'overdue_scheduled_count' => $overdueScheduled,
            'overdue_retry_count' => $overdueRetry,
            'dominant_rejection_reason' => $noProgressReason,
            'skip_reason_counts' => $noProgressCounts,
            'last_worker_run' => $lastRun,
            'last_success' => $lastSuccess,
            'last_failure' => $lastFailure,
            'last_bootstrap_failure' => $lastBootstrapFailure,
            'health_connection_id' => $scopeId,
            'health_hash_id' => $resolvedHash,
        ];
    }

    public function rememberWorkerRun(?int $connectionId = null): void
    {
        $payload = now()->toIso8601String();
        Cache::put($this->scopedKey(self::CACHE_LAST_WORKER_RUN, $connectionId), $payload, now()->addDays(7));
        // Keep legacy key for older readers during rollout.
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_WORKER_RUN, $payload, now()->addDays(7));
        }
    }

    public function rememberScannerRun(?int $connectionId = null): void
    {
        $payload = now('UTC')->toIso8601String();
        Cache::put($this->scopedKey(self::CACHE_LAST_SCANNER_RUN, $connectionId), $payload, now()->addDays(7));
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_SCANNER_RUN, $payload, now()->addDays(7));
        }
        $this->rememberWorkerRun($connectionId);
    }

    public function rememberPublisherProcessed(int $count = 1, ?int $connectionId = null): void
    {
        $payload = now('UTC')->toIso8601String().'|count='.max(0, $count);
        Cache::put($this->scopedKey(self::CACHE_LAST_PUBLISHER_PROCESSED, $connectionId), $payload, now()->addDays(7));
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_PUBLISHER_PROCESSED, $payload, now()->addDays(7));
        }
    }

    public function rememberDueBacklog(int $overdueScheduled, int $overdueRetry, ?int $connectionId = null): void
    {
        $payload = max(0, $overdueScheduled).'|'.max(0, $overdueRetry);
        Cache::put($this->scopedKey(self::CACHE_DUE_BACKLOG, $connectionId), $payload, now()->addDays(7));
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_DUE_BACKLOG, $payload, now()->addDays(7));
        }
    }

    /**
     * @param  array<string, int>  $skipReasonCounts
     */
    public function rememberScanNoProgress(
        int $dueTotal,
        ?string $dominantReason,
        array $skipReasonCounts = [],
        ?int $connectionId = null,
    ): void {
        $payload = json_encode([
            'at' => now('UTC')->toIso8601String(),
            'due_total' => max(0, $dueTotal),
            'dominant_reason' => $dominantReason,
            'skip_reason_counts' => $skipReasonCounts,
        ], JSON_UNESCAPED_UNICODE);
        Cache::put($this->scopedKey(self::CACHE_SCAN_NO_PROGRESS, $connectionId), $payload, now()->addDays(7));
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_SCAN_NO_PROGRESS, $payload, now()->addDays(7));
        }
        $this->rememberFailure(
            sprintf('due=%d no_progress reason=%s', $dueTotal, $dominantReason ?? 'unknown'),
            $connectionId,
        );
    }

    /**
     * @param  array<string, int>  $skipReasonCounts
     */
    private function buildOverdueLabel(
        int $dueTotal,
        int $overdueScheduled,
        int $overdueRetry,
        ?string $dominantReason,
        array $skipReasonCounts,
    ): string {
        if ($dominantReason !== null && $dominantReason !== '' && $dueTotal > 0) {
            $reasonLabel = match ($dominantReason) {
                'active_publish', 'active_lease' => 'đang xuất bản',
                'stale_claim' => 'stale claim',
                'awaiting_worker' => 'đang chờ worker',
                'idempotent_replay', 'stale_operation' => 'stale operation',
                'lock_busy' => 'lock bận',
                'invalid_status' => 'status không hợp lệ',
                'dispatch_failed' => 'dispatch thất bại',
                default => $dominantReason,
            };
            $blocked = (int) ($skipReasonCounts[$dominantReason] ?? $dueTotal);

            return sprintf(
                '%d bài quá hạn chưa được xử lý — %d bị chặn bởi %s.',
                $dueTotal,
                $blocked,
                $reasonLabel,
            );
        }

        return sprintf(
            '%d bài quá hạn chưa được xử lý (%d scheduled + %d retry).',
            $dueTotal,
            $overdueScheduled,
            $overdueRetry,
        );
    }

    public function rememberSuccess(int $count = 1, ?int $connectionId = null): void
    {
        $payload = now()->toIso8601String().'|count='.$count;
        if ($connectionId !== null && $connectionId > 0) {
            $payload .= '|connection_id='.$connectionId;
        }

        Cache::put($this->scopedKey(self::CACHE_LAST_SUCCESS, $connectionId), $payload, now()->addDays(7));
        Cache::forget($this->scopedKey(self::CACHE_LAST_BOOTSTRAP_FAILURE, $connectionId));
        Cache::forget($this->scopedKey(self::CACHE_SCAN_NO_PROGRESS, $connectionId));

        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_SUCCESS, $payload, now()->addDays(7));
            Cache::forget(self::CACHE_LAST_BOOTSTRAP_FAILURE);
            Cache::forget(self::CACHE_SCAN_NO_PROGRESS);
        }
    }

    public function rememberFailure(string $message, ?int $connectionId = null): void
    {
        $payload = now()->toIso8601String().'|'.mb_substr($message, 0, 200);
        Cache::put($this->scopedKey(self::CACHE_LAST_FAILURE, $connectionId), $payload, now()->addDays(7));
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_FAILURE, $payload, now()->addDays(7));
        }
    }

    public function rememberBootstrapFailure(string $message, ?int $connectionId = null): void
    {
        $payload = now()->toIso8601String().'|'.mb_substr($message, 0, 200);
        if ($connectionId !== null && $connectionId > 0) {
            $payload .= '|connection_id='.$connectionId;
        }

        Cache::put($this->scopedKey(self::CACHE_LAST_BOOTSTRAP_FAILURE, $connectionId), $payload, now()->addDays(7));

        // Never write unscoped global bootstrap failure from a known connection —
        // that was how stale connection_id=2 painted other tenants unhealthy.
        if ($connectionId === null || $connectionId <= 0) {
            Cache::put(self::CACHE_LAST_BOOTSTRAP_FAILURE, $payload, now()->addDays(7));
        }
    }

    public function scopedKey(string $base, ?int $connectionId): string
    {
        if ($connectionId === null || $connectionId <= 0) {
            return $base;
        }

        return $base.'.'.$connectionId;
    }

    private function cacheString(string $key): ?string
    {
        $value = Cache::get($key);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    private function minutesSinceIsoPrefix(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $iso = explode('|', $value, 2)[0];

        try {
            return (int) abs(now()->diffInMinutes(\Carbon\Carbon::parse($iso)));
        } catch (\Throwable) {
            return null;
        }
    }
}
