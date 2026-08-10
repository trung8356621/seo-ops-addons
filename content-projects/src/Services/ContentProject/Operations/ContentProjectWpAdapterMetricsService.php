<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WP adapter aggregates from publish_attempts — no live WP calls.
 */
final class ContentProjectWpAdapterMetricsService
{
    private const CONNECTION = 'omi_seo_ai';

    /**
     * @param  list<int>|null  $siteIds
     * @return array{
     *     avg_latency_ms: int|null,
     *     slowest_ms: int|null,
     *     failure_pct: float,
     *     retry_pct: float,
     *     total: int,
     *     failed: int,
     *     retries: int,
     * }
     */
    public function snapshot(?array $siteIds = null): array
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('seo_content_project_publish_attempts')) {
            return $this->empty();
        }

        $query = DB::connection(self::CONNECTION)->table('seo_content_project_publish_attempts');

        if (is_array($siteIds) && $siteIds !== []) {
            $query->whereIn('project_id', function ($sub) use ($siteIds): void {
                $sub->select('id')
                    ->from('seo_projects')
                    ->whereIn('site_id', $siteIds);
            });
        }

        $total = (int) (clone $query)->count();
        $failed = (int) (clone $query)->where('status', 'failed')->count();
        $retries = 0;
        if (Schema::connection(self::CONNECTION)->hasColumn('seo_content_project_publish_attempts', 'attempt_number')) {
            $retries = (int) (clone $query)->where('attempt_number', '>', 1)->count();
        }

        $avgLatencyMs = null;
        $slowestMs = null;
        if (Schema::connection(self::CONNECTION)->hasColumn('seo_content_project_publish_attempts', 'duration_ms')) {
            $avgLatencyMs = (clone $query)->avg('duration_ms');
            $slowestMs = (clone $query)->max('duration_ms');
        }

        return [
            'avg_latency_ms' => $avgLatencyMs !== null ? (int) round((float) $avgLatencyMs) : null,
            'slowest_ms' => $slowestMs !== null ? (int) $slowestMs : null,
            'failure_pct' => $total > 0 ? round(($failed / $total) * 100, 2) : 0.0,
            'retry_pct' => $total > 0 ? round(($retries / $total) * 100, 2) : 0.0,
            'total' => $total,
            'failed' => $failed,
            'retries' => $retries,
        ];
    }

    /**
     * @return array{
     *     avg_latency_ms: int|null,
     *     slowest_ms: int|null,
     *     failure_pct: float,
     *     retry_pct: float,
     *     total: int,
     *     failed: int,
     *     retries: int,
     * }
     */
    private function empty(): array
    {
        return [
            'avg_latency_ms' => null,
            'slowest_ms' => null,
            'failure_pct' => 0.0,
            'retry_pct' => 0.0,
            'total' => 0,
            'failed' => 0,
            'retries' => 0,
        ];
    }
}
