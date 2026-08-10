<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily operational report — metrics + tasks + cost, no prompts.
 */
final class ContentProjectDailyReportService
{
    public function __construct(
        private readonly ContentProjectOpsMetrics $metrics,
        private readonly ContentProjectAiCostAggregateService $costAggregate,
        private readonly ContentProjectPublishAnalyticsService $publishAnalytics,
    ) {}

    /**
     * @param  list<int>|null  $siteIds
     * @return array<string, mixed>
     */
    public function buildForDate(Carbon $date, ?array $siteIds = null): array
    {
        $dayStart = $date->copy()->startOfDay();
        $dayEnd = $date->copy()->endOfDay();

        $generated = $this->countTasksByStatus($dayStart, $dayEnd, $siteIds, SeoProjectTask::STATUS_WRITING);
        $approved = $this->countApproved($dayStart, $dayEnd, $siteIds);
        $published = $this->countPublished($dayStart, $dayEnd, $siteIds);
        $failed = $this->countTasksByStatus($dayStart, $dayEnd, $siteIds, SeoProjectTask::STATUS_FAILED);

        $cost = $this->costAggregate->aggregate($date, $siteIds);
        $publish = $this->publishAnalytics->snapshot($siteIds);

        return [
            'date' => $date->toDateString(),
            'generated' => $generated,
            'approved' => $approved,
            'published' => $published,
            'failed' => $failed,
            'cost' => $cost['totals'],
            'avg_queue_wait_ms' => $this->avgQueueWaitMs($dayStart, $dayEnd, $siteIds),
            'avg_publish_ms' => $publish['overall']['avg_publish_ms'],
            'metrics' => $this->metricsForDate($date, $siteIds),
        ];
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function countTasksByStatus(Carbon $from, Carbon $to, ?array $siteIds, string $status): int
    {
        $query = SeoProjectTask::query()
            ->where('status', $status)
            ->whereBetween('updated_at', [$from, $to]);

        if (is_array($siteIds) && $siteIds !== []) {
            $query->whereIn('site_id', $siteIds);
        }

        return (int) $query->count();
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function countApproved(Carbon $from, Carbon $to, ?array $siteIds): int
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')) {
            return 0;
        }

        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('articles as a', 'a.id', '=', 't.article_id')
            ->where('t.status', SeoProjectTask::STATUS_COMPLETED)
            ->where('a.review_status', 'approved')
            ->whereBetween('t.updated_at', [$from, $to]);

        if (is_array($siteIds) && $siteIds !== []) {
            $query->whereIn('t.site_id', $siteIds);
        }

        return (int) $query->count();
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function countPublished(Carbon $from, Carbon $to, ?array $siteIds): int
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'publish_published_at')) {
            return 0;
        }

        $query = SeoProjectTask::query()
            ->whereNotNull('publish_published_at')
            ->whereBetween('publish_published_at', [$from, $to]);

        if (is_array($siteIds) && $siteIds !== []) {
            $query->whereIn('site_id', $siteIds);
        }

        return (int) $query->count();
    }

    /**
     * @param  list<int>|null  $siteIds
     */
    private function avgQueueWaitMs(Carbon $from, Carbon $to, ?array $siteIds): ?int
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'last_publish_attempt_at')) {
            return null;
        }

        $siteFilter = '';
        $bindings = [$from, $to];
        if (is_array($siteIds) && $siteIds !== []) {
            $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
            $siteFilter = " AND site_id IN ({$placeholders})";
            $bindings = array_merge($bindings, array_map('intval', $siteIds));
        }

        $row = DB::connection('omi_seo_ai')->selectOne("
            SELECT AVG(TIMESTAMPDIFF(MICROSECOND, scheduled_publish_at, last_publish_attempt_at) / 1000) AS avg_ms
            FROM seo_project_tasks
            WHERE scheduled_publish_at IS NOT NULL
              AND last_publish_attempt_at IS NOT NULL
              AND last_publish_attempt_at BETWEEN ? AND ?
              AND deleted_at IS NULL
              {$siteFilter}
        ", $bindings);

        return isset($row->avg_ms) ? (int) round((float) $row->avg_ms) : null;
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return array<string, int>
     */
    private function metricsForDate(Carbon $date, ?array $siteIds): array
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_ops_metrics')) {
            return [];
        }

        $query = DB::connection('omi_seo_ai')
            ->table('seo_content_project_ops_metrics')
            ->where('bucket_date', $date->toDateString());

        if (is_array($siteIds) && $siteIds !== []) {
            $query->where(function ($q) use ($siteIds): void {
                $q->whereIn('site_id', $siteIds)->orWhere('site_id', 0);
            });
        }

        $rows = $query
            ->selectRaw('metric_key, SUM(value) AS total')
            ->groupBy('metric_key')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->metric_key] = (int) $row->total;
        }

        return $out;
    }
}
