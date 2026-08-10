<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Daily bucket counters — no-op when table missing.
 */
final class ContentProjectOpsMetrics
{
    private const CONNECTION = 'omi_seo_ai';

    public function increment(string $key, int $by = 1, ?int $siteId = null, ?int $projectId = null): void
    {
        if ($by <= 0 || ! $this->tableExists()) {
            return;
        }

        $now = now();

        try {
            DB::connection(self::CONNECTION)->statement(
                'INSERT INTO seo_content_project_ops_metrics
                    (metric_key, bucket_date, site_id, project_id, value, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    value = value + VALUES(value),
                    updated_at = VALUES(updated_at)',
                [
                    $key,
                    $now->toDateString(),
                    $siteId ?? 0,
                    $projectId ?? 0,
                    $by,
                    $now,
                    $now,
                ],
            );
        } catch (Throwable) {
            // metrics never break business path
        }
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return array<string, int>
     */
    public function snapshotToday(?array $siteIds = null): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        try {
            $query = DB::connection(self::CONNECTION)
                ->table('seo_content_project_ops_metrics')
                ->where('bucket_date', Carbon::today()->toDateString());

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
        } catch (Throwable) {
            return [];
        }
    }

    private function tableExists(): bool
    {
        return Schema::connection(self::CONNECTION)->hasTable('seo_content_project_ops_metrics');
    }
}
