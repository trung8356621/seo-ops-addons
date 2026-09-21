<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\SitePlanning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Site Planning article distribution: how many articles are allocated to site × planning_month.
 *
 * Count = distinct project_task_id (t.id) where site_id + planning_month match.
 * Includes completed / published / archived projects.
 * Excludes only soft-deleted + cancelled.
 *
 * Optional: exclude Global Legacy import shells (`import_source = seo_content_archive_items`)
 * when callers need planning-workload semantics (Projects list). Default keeps historical
 * distribution including Global Legacy.
 *
 * Not an "active workload" / capacity gate.
 */
final class SitePlanningActiveUnitAggregator
{
    /**
     * Distinct article counts keyed by site_id for one planning month (matrix cells).
     *
     * @return array<int, int>
     */
    public function plannedCountsBySite(string $planningMonth, bool $excludeGlobalLegacy = false): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $counts = [];

        foreach ($this->distributionRows($month, null, $excludeGlobalLegacy) as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $taskId = (int) ($row->id ?? 0);
            if ($siteId <= 0 || $taskId <= 0) {
                continue;
            }
            $counts[$siteId][$taskId] = true;
        }

        $out = [];
        foreach ($counts as $siteId => $taskMap) {
            $out[(int) $siteId] = count($taskMap);
        }

        return $out;
    }

    /**
     * Single-site distribution count (tests / diagnostics).
     *
     * @return array{planned: int, task_ids: list<int>}
     */
    public function forSiteMonth(int $siteId, string $planningMonth, bool $excludeGlobalLegacy = false): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        if ($siteId <= 0) {
            return ['planned' => 0, 'task_ids' => []];
        }

        $taskIds = [];
        foreach ($this->distributionRows($month, $siteId, $excludeGlobalLegacy) as $row) {
            $taskId = (int) ($row->id ?? 0);
            if ($taskId > 0) {
                $taskIds[$taskId] = true;
            }
        }

        $ids = array_map('intval', array_keys($taskIds));
        sort($ids);

        return [
            'planned' => count($ids),
            'task_ids' => $ids,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function distributionRows(string $month, ?int $siteId = null, bool $excludeGlobalLegacy = false)
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_project_tasks')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_projects')) {
            return collect();
        }

        $monthDate = ContentProjectMonthContext::toDateString($month);

        $query = DB::connection('omi_seo_ai')->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->whereNull('t.deleted_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->select([
                't.id',
                't.site_id',
                't.planning_month',
                't.created_at',
                't.target_date',
                'p.month as project_month',
                'p.status as project_status',
            ]);

        if ($excludeGlobalLegacy) {
            ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($query, 'p');
        }

        if ($siteId !== null && $siteId > 0) {
            $query->where('t.site_id', $siteId);
        } else {
            $query->whereNotNull('t.site_id')->where('t.site_id', '>', 0);
        }

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_month')) {
            $query->where(function ($q) use ($monthDate): void {
                $q->whereDate('t.planning_month', $monthDate)
                    ->orWhereNull('t.planning_month')
                    ->orWhereDate('p.month', $monthDate);
            });
        } else {
            $query->whereDate('p.month', $monthDate);
        }

        return $query->get()->filter(function (object $row) use ($month): bool {
            $projectIsDraft = strtolower(trim((string) ($row->project_status ?? ''))) === SeoProject::STATUS_DRAFT;

            return PlanningMonthBackfill::resolve([
                'planning_month' => $row->planning_month ?? null,
                'created_at' => $row->created_at ?? null,
                'target_date' => $row->target_date ?? null,
                'project_month' => $row->project_month ?? null,
                'project_is_draft' => $projectIsDraft,
            ]) === $month;
        })->values();
    }
}
