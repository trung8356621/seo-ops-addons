<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectQueueHealthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operation Center dashboard snapshot — read-only aggregates.
 */
final class ContentProjectOpsDashboardService
{
    public function __construct(
        private readonly ContentProjectQueueHealthService $queueHealth,
        private readonly ContentProjectOpsMetrics $metrics,
    ) {}

    /**
     * @param  list<int>|null  $siteIds
     * @return array<string, mixed>
     */
    public function snapshot(?array $siteIds = null): array
    {
        return [
            'ai' => $this->aiSnapshot($siteIds),
            'publishing' => $this->publishingSnapshot($siteIds),
            'archive' => $this->archiveSnapshot($siteIds),
            'worker' => $this->workerSnapshot(),
            'metrics_today' => $this->metrics->snapshotToday($siteIds),
        ];
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return array{waiting: int, running: int, failed: int, retry: int}
     */
    private function aiSnapshot(?array $siteIds): array
    {
        $waiting = 0;
        $running = 0;
        $failed = 0;
        $retry = 0;

        $taskQuery = SeoProjectTask::query()
            ->active()
            ->whereHas('project', static function ($q) use ($siteIds): void {
                $q->whereNull('archived_at');
                if (is_array($siteIds) && $siteIds !== []) {
                    $q->whereIn('site_id', $siteIds);
                }
            });

        $waiting = (int) (clone $taskQuery)->where('status', SeoProjectTask::STATUS_PENDING)->count();
        $staleMinutes = max(1, (int) config('seo-content-ai.content_project.generation_task_stale_minutes', 0));
        if ($staleMinutes <= 0) {
            $staleMinutes = max(
                max(1, (int) config('seo-content-ai.content_project.run_item_stale_minutes', 30)),
                max(1, (int) config('seo-content-ai.content_project.heartbeat_stale_minutes', 20)),
            );
        }
        $runningTasks = (int) (clone $taskQuery)
            ->where('status', SeoProjectTask::STATUS_WRITING)
            ->where('updated_at', '>=', now()->subMinutes($staleMinutes))
            ->count();

        $runQuery = SeoProjectRun::query()
            ->whereIn('status', [SeoProjectRun::STATUS_RUNNING, SeoProjectRun::STATUS_STOPPING])
            ->whereHas('project', static function ($q) use ($siteIds): void {
                $q->whereNull('archived_at');
                if (is_array($siteIds) && $siteIds !== []) {
                    $q->whereIn('site_id', $siteIds);
                }
            });

        $runningRuns = (int) $runQuery->count();
        $running = max($runningTasks, $runningRuns);

        $failed = (int) (clone $taskQuery)->where('status', SeoProjectTask::STATUS_FAILED)->count();

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_retry_count')) {
            $retry = (int) (clone $taskQuery)->where('publish_retry_count', '>', 0)->count();
        }

        return [
            'waiting' => $waiting,
            'running' => $running,
            'failed' => $failed,
            'retry' => $retry,
        ];
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return array<string, mixed>
     */
    private function publishingSnapshot(?array $siteIds): array
    {
        $connectionId = null;
        $current = \Omnichannel\Addons\Seo\Support\SeoConnectionContext::current();
        if ($current instanceof \App\Models\SeoDatabaseConnection) {
            $connectionId = (int) $current->getKey();
        }
        $health = $this->queueHealth->snapshot($siteIds, $connectionId);

        return [
            'waiting' => $health['waiting'],
            'processing' => $health['processing'],
            'failed' => $health['failed'],
            'retrying' => $health['retrying'],
            'last_worker_run' => $health['last_worker_run'],
            'last_success' => $health['last_success'],
            'last_failure' => $health['last_failure'],
            'health_connection_id' => $health['health_connection_id'] ?? null,
            'health_hash_id' => $health['health_hash_id'] ?? null,
        ];
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return array{pending: int, success: int, failed: int}
     */
    private function archiveSnapshot(?array $siteIds): array
    {
        $success = 0;
        $failed = 0;
        $pending = 0;

        $projectQuery = SeoProject::query()->whereNotNull('archived_at');
        if (is_array($siteIds) && $siteIds !== []) {
            $projectQuery->whereIn('site_id', $siteIds);
        }

        $success = (int) (clone $projectQuery)
            ->whereDate('archived_at', Carbon::today())
            ->count();

        if (Schema::connection('omi_seo_ai')->hasTable('seo_content_project_operations')) {
            $ops = DB::connection('omi_seo_ai')
                ->table('seo_content_project_operations')
                ->where('command', 'like', '%archive%')
                ->whereDate('finished_at', Carbon::today());

            if (is_array($siteIds) && $siteIds !== []) {
                $ops->where(function ($q) use ($siteIds): void {
                    foreach ($siteIds as $siteId) {
                        $q->orWhere('tenant_ref', 'site:'.(string) $siteId);
                    }
                });
            }

            $failed = (int) (clone $ops)->where('success', 0)->count();
            $pendingQuery = DB::connection('omi_seo_ai')
                ->table('seo_content_project_operations')
                ->where('command', 'like', '%archive%')
                ->where(function ($q): void {
                    $q->whereNull('finished_at')->orWhere('status', 'running');
                });
            if (is_array($siteIds) && $siteIds !== []) {
                $pendingQuery->where(function ($q) use ($siteIds): void {
                    foreach ($siteIds as $siteId) {
                        $q->orWhere('tenant_ref', 'site:'.(string) $siteId);
                    }
                });
            }
            $pending = (int) $pendingQuery->count();
        } elseif (Schema::connection('omi_seo_ai')->hasTable('seo_content_project_business_audits')) {
            $failed = (int) DB::connection('omi_seo_ai')
                ->table('seo_content_project_business_audits')
                ->where('action', 'like', '%archive%')
                ->where('result', 'failed')
                ->count();
        }

        return [
            'pending' => $pending,
            'success' => $success,
            'failed' => $failed,
        ];
    }

    /**
     * @return array{alive: bool, last_worker_run: string|null, last_success: string|null, last_failure: string|null}
     */
    private function workerSnapshot(): array
    {
        $connectionId = null;
        $current = \Omnichannel\Addons\Seo\Support\SeoConnectionContext::current();
        if ($current instanceof \App\Models\SeoDatabaseConnection) {
            $connectionId = (int) $current->getKey();
        }
        $health = $this->queueHealth->snapshot(null, $connectionId);
        $lastRun = $health['last_worker_run'];
        $alive = false;

        if (is_string($lastRun) && $lastRun !== '') {
            try {
                $alive = Carbon::parse($lastRun)->greaterThan(now()->subMinutes(10));
            } catch (\Throwable) {
                $alive = false;
            }
        }

        return [
            'alive' => $alive,
            'last_worker_run' => $lastRun,
            'last_success' => $health['last_success'],
            'last_failure' => $health['last_failure'],
        ];
    }
}
