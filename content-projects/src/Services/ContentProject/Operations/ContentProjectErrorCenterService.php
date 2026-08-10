<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Top errors from operations log or business audits — read-only.
 */
final class ContentProjectErrorCenterService
{
    private const CONNECTION = 'omi_seo_ai';

    /**
     * @param  list<int>|null  $siteIds
     * @return list<array{result_code: string, count: int, last_seen: string|null, sample_project_ref: string|null}>
     */
    public function topErrors(?array $siteIds = null, int $limit = 20): array
    {
        if (Schema::connection(self::CONNECTION)->hasTable('seo_content_project_operations')) {
            return $this->fromOperations($siteIds, $limit);
        }

        if (Schema::connection(self::CONNECTION)->hasTable('seo_content_project_business_audits')) {
            return $this->fromAudits($siteIds, $limit);
        }

        return [];
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return list<array{result_code: string, count: int, last_seen: string|null, sample_project_ref: string|null}>
     */
    private function fromOperations(?array $siteIds, int $limit): array
    {
        $query = ContentProjectOperation::query()
            ->where('success', false)
            ->whereNotNull('result_code');

        if (is_array($siteIds) && $siteIds !== []) {
            $query->where(function ($q) use ($siteIds): void {
                foreach ($siteIds as $siteId) {
                    $q->orWhere('tenant_ref', 'site:'.(string) $siteId);
                }
            });
        }

        $rows = $query
            ->selectRaw('result_code, COUNT(*) AS cnt, MAX(finished_at) AS last_seen, MIN(project_ref) AS sample_project_ref')
            ->groupBy('result_code')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $rows->map(static fn ($row): array => [
            'result_code' => (string) $row->result_code,
            'count' => (int) $row->cnt,
            'last_seen' => $row->last_seen?->toIso8601String(),
            'sample_project_ref' => $row->sample_project_ref !== null ? (string) $row->sample_project_ref : null,
        ])->all();
    }

    /**
     * @param  list<int>|null  $siteIds
     * @return list<array{result_code: string, count: int, last_seen: string|null, sample_project_ref: string|null}>
     */
    private function fromAudits(?array $siteIds, int $limit): array
    {
        $query = DB::connection(self::CONNECTION)
            ->table('seo_content_project_business_audits')
            ->where('result', 'failed')
            ->whereNotNull('result_code');

        $rows = $query
            ->selectRaw('result_code, COUNT(*) AS cnt, MAX(occurred_at) AS last_seen, MIN(project_ref) AS sample_project_ref')
            ->groupBy('result_code')
            ->orderByDesc('cnt')
            ->limit($limit)
            ->get();

        return $rows->map(static fn ($row): array => [
            'result_code' => (string) $row->result_code,
            'count' => (int) $row->cnt,
            'last_seen' => $row->last_seen !== null ? (string) $row->last_seen : null,
            'sample_project_ref' => $row->sample_project_ref !== null ? (string) $row->sample_project_ref : null,
        ])->all();
    }
}
