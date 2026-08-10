<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publish analytics — tasks + publish_attempts, read-only.
 */
final class ContentProjectPublishAnalyticsService
{
    private const CONNECTION = 'omi_seo_ai';

    /**
     * @param  list<int>|null  $siteIds
     * @return array{
     *     overall: array{total: int, success_count: int, success_pct: float, retry_count: int, retry_pct: float, avg_publish_ms: int|null},
     *     failure_breakdown: array{timeout: int, connection: int, api: int, other: int},
     *     by_site: list<array{site_id: int, total: int, success_count: int, success_pct: float, retry_count: int, retry_pct: float, avg_publish_ms: int|null}>,
     * }
     */
    public function snapshot(?array $siteIds = null): array
    {
        $hasAttempts = Schema::connection(self::CONNECTION)->hasTable('seo_content_project_publish_attempts');
        $hasPublishedAt = Schema::connection(self::CONNECTION)->hasColumn('seo_project_tasks', 'publish_published_at');
        $hasLastAttempt = Schema::connection(self::CONNECTION)->hasColumn('seo_project_tasks', 'last_publish_attempt_at');
        $hasRetryCount = Schema::connection(self::CONNECTION)->hasColumn('seo_project_tasks', 'publish_retry_count');

        $taskQuery = SeoProjectTask::query()
            ->whereHas('project', static function ($q) use ($siteIds): void {
                if (is_array($siteIds) && $siteIds !== []) {
                    $q->whereIn('site_id', $siteIds);
                }
            });

        if (Schema::connection(self::CONNECTION)->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $taskQuery->whereIn('publish_queue_status', [
                ContentProjectPublishQueueStatus::Published->value,
                ContentProjectPublishQueueStatus::Failed->value,
                ContentProjectPublishQueueStatus::Retrying->value,
            ]);
        }

        $total = (int) (clone $taskQuery)->count();

        $successCount = 0;
        if (Schema::connection(self::CONNECTION)->hasColumn('seo_project_tasks', 'publish_queue_status')) {
            $successCount = (int) (clone $taskQuery)
                ->where('publish_queue_status', ContentProjectPublishQueueStatus::Published->value)
                ->count();
        } elseif ($hasPublishedAt) {
            $successCount = (int) (clone $taskQuery)->whereNotNull('publish_published_at')->count();
        }

        $retryCount = $hasRetryCount
            ? (int) (clone $taskQuery)->where('publish_retry_count', '>', 0)->count()
            : 0;

        $avgPublishMs = null;
        if ($hasPublishedAt && $hasLastAttempt) {
            $avgRow = DB::connection(self::CONNECTION)->selectOne('
                SELECT AVG(TIMESTAMPDIFF(MICROSECOND, last_publish_attempt_at, publish_published_at) / 1000) AS avg_ms
                FROM seo_project_tasks
                WHERE publish_published_at IS NOT NULL
                  AND last_publish_attempt_at IS NOT NULL
                  AND deleted_at IS NULL
                  '.($siteIds !== null && $siteIds !== [] ? 'AND site_id IN ('.implode(',', array_map('intval', $siteIds)).')' : '').'
            ');
            $avgPublishMs = isset($avgRow->avg_ms) ? (int) round((float) $avgRow->avg_ms) : null;
        }

        $failureBreakdown = $hasAttempts
            ? $this->failureBreakdown($siteIds)
            : ['timeout' => 0, 'connection' => 0, 'api' => 0, 'other' => 0];

        return [
            'overall' => [
                'total' => $total,
                'success_count' => $successCount,
                'success_pct' => $total > 0 ? round(($successCount / $total) * 100, 2) : 0.0,
                'retry_count' => $retryCount,
                'retry_pct' => $total > 0 ? round(($retryCount / $total) * 100, 2) : 0.0,
                'avg_publish_ms' => $avgPublishMs,
            ],
            'failure_breakdown' => $failureBreakdown,
            'by_site' => $this->bySite($siteIds, $hasPublishedAt, $hasRetryCount),
        ];
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return array{timeout: int, connection: int, api: int, other: int}
     */
    private function failureBreakdown(?array $siteIds): array
    {
        $query = DB::connection(self::CONNECTION)
            ->table('seo_content_project_publish_attempts')
            ->where('status', 'failed')
            ->whereNotNull('last_error');

        if (is_array($siteIds) && $siteIds !== []) {
            $query->whereIn('project_id', function ($sub) use ($siteIds): void {
                $sub->select('id')
                    ->from('seo_projects')
                    ->whereIn('site_id', $siteIds);
            });
        }

        $rows = $query->pluck('last_error');

        $counts = ['timeout' => 0, 'connection' => 0, 'api' => 0, 'other' => 0];
        foreach ($rows as $error) {
            $lower = strtolower((string) $error);
            if (str_contains($lower, 'timeout')) {
                $counts['timeout']++;
            } elseif (str_contains($lower, 'connection') || str_contains($lower, 'connect')) {
                $counts['connection']++;
            } elseif (str_contains($lower, 'api') || str_contains($lower, '401') || str_contains($lower, '403') || str_contains($lower, '500')) {
                $counts['api']++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return list<array{site_id: int, total: int, success_count: int, success_pct: float, retry_count: int, retry_pct: float, avg_publish_ms: int|null}>
     */
    private function bySite(?array $siteIds, bool $hasPublishedAt, bool $hasRetryCount): array
    {
        $siteFilter = '';
        $bindings = [];
        if (is_array($siteIds) && $siteIds !== []) {
            $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
            $siteFilter = " AND t.site_id IN ({$placeholders})";
            $bindings = array_map('intval', $siteIds);
        }

        $publishedExpr = Schema::connection(self::CONNECTION)->hasColumn('seo_project_tasks', 'publish_queue_status')
            ? "SUM(CASE WHEN t.publish_queue_status = 'published' THEN 1 ELSE 0 END)"
            : ($hasPublishedAt ? 'SUM(CASE WHEN t.publish_published_at IS NOT NULL THEN 1 ELSE 0 END)' : '0');

        $retryExpr = $hasRetryCount
            ? 'SUM(CASE WHEN t.publish_retry_count > 0 THEN 1 ELSE 0 END)'
            : '0';

        $avgExpr = ($hasPublishedAt && Schema::connection(self::CONNECTION)->hasColumn('seo_project_tasks', 'last_publish_attempt_at'))
            ? 'AVG(CASE WHEN t.publish_published_at IS NOT NULL AND t.last_publish_attempt_at IS NOT NULL
                THEN TIMESTAMPDIFF(MICROSECOND, t.last_publish_attempt_at, t.publish_published_at) / 1000 END)'
            : 'NULL';

        $rows = DB::connection(self::CONNECTION)->select("
            SELECT
                t.site_id,
                COUNT(*) AS total,
                {$publishedExpr} AS success_count,
                {$retryExpr} AS retry_count,
                {$avgExpr} AS avg_publish_ms
            FROM seo_project_tasks t
            WHERE t.deleted_at IS NULL
              {$siteFilter}
            GROUP BY t.site_id
            ORDER BY t.site_id
        ", $bindings);

        return array_map(static function ($row): array {
            $total = (int) $row->total;
            $successCount = (int) $row->success_count;
            $retryCount = (int) $row->retry_count;

            return [
                'site_id' => (int) $row->site_id,
                'total' => $total,
                'success_count' => $successCount,
                'success_pct' => $total > 0 ? round(($successCount / $total) * 100, 2) : 0.0,
                'retry_count' => $retryCount,
                'retry_pct' => $total > 0 ? round(($retryCount / $total) * 100, 2) : 0.0,
                'avg_publish_ms' => isset($row->avg_publish_ms) ? (int) round((float) $row->avg_publish_ms) : null,
            ];
        }, $rows);
    }
}
