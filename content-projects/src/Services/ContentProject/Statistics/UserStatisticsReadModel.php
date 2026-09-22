<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Statistics;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Omnichannel\Addons\Seo\Support\SeoAnalyticsArticleScope;
use App\Services\Users\SeoOpsSystemUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analytical user/workload Statistics for a Content Project planning month.
 *
 * Semantics:
 * - Month = seo_projects.month (ContentProjectMonthContext YYYY-MM).
 * - Assigned = active + archived execution items (capacity cardinality).
 * - Completed = active completed/CM-reviewed + archived items.
 * - Pending = active not completed.
 * - Capacity = ContentProjectWriterCapacitySettingsService.
 * - Page exclusion via {@see SeoAnalyticsArticleScope} on linked article
 *   (META_CONTENT_TYPE), never task.post_type alone.
 */
final class UserStatisticsReadModel
{
    public function __construct(
        private readonly ContentProjectWriterMonthlyCapacityService $writerCapacity,
        private readonly ContentProjectWriterCapacitySettingsService $capacitySettings,
        private readonly SeoAnalyticsArticleScope $analyticsScope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $month, ?int $userId = null): array
    {
        $monthKey = ContentProjectMonthContext::normalize($month);
        $breakdown = $this->itemBreakdownByUser($monthKey);
        $statusByUser = $this->activeStatusByUser($monthKey);

        $userIds = array_keys($breakdown);
        if ($userId !== null && $userId > 0) {
            $userIds = in_array($userId, $userIds, true) ? [$userId] : [];
        }

        $names = $this->writerCapacity->displayNamesByUserId($userIds);
        $capacities = $this->writerCapacity->capacityByUserId($userIds);
        $defaultCapacity = $this->capacitySettings->defaultMonthlyCapacity();

        $rows = [];
        $workloadMax = 1;
        foreach ($userIds as $uid) {
            if ($uid <= 0 || SeoOpsSystemUser::isSystemUserId($uid)) {
                continue;
            }
            $counts = $breakdown[$uid] ?? ['active' => 0, 'archived' => 0, 'total' => 0];
            $assigned = (int) ($counts['total'] ?? 0);
            if ($assigned <= 0) {
                continue;
            }

            $status = $statusByUser[$uid] ?? ['completed' => 0, 'pending' => 0];
            $archived = (int) ($counts['archived'] ?? 0);
            $completed = (int) ($status['completed'] ?? 0) + $archived;
            $pending = (int) ($status['pending'] ?? 0);
            $capacity = (int) ($capacities[$uid] ?? $defaultCapacity);
            $progress = $assigned > 0 ? (int) round(($completed / $assigned) * 100) : 0;
            $workloadMax = max($workloadMax, $assigned, $capacity);

            $rows[] = [
                'user_id' => $uid,
                'name' => (string) ($names[$uid] ?? ('#'.$uid)),
                'assigned' => $assigned,
                'completed' => $completed,
                'pending' => $pending,
                'progress' => min(100, max(0, $progress)),
                'capacity' => $capacity,
                'remaining' => $capacity - $assigned,
                'active_count' => (int) ($counts['active'] ?? 0),
                'archived_count' => $archived,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['assigned'] ?? 0) <=> ($a['assigned'] ?? 0),
        );

        $usersWithWork = count($rows);
        $totalAssigned = array_sum(array_column($rows, 'assigned'));
        $totalCompleted = array_sum(array_column($rows, 'completed'));
        $totalPending = array_sum(array_column($rows, 'pending'));
        $empty = $rows === [] || $totalAssigned === 0;

        return [
            'empty' => $empty,
            'empty_reason' => $empty ? 'no_assignments' : null,
            'month' => $monthKey,
            'month_label' => ContentProjectMonthContext::display($monthKey),
            'user_id' => $userId !== null && $userId > 0 ? $userId : null,
            'semantics' => [
                'assigned' => 'active_tasks_plus_archived_items_for_project_month',
                'completed' => 'active_completed_or_cm_reviewed_plus_archived_items',
                'pending' => 'active_tasks_not_completed',
                'capacity' => 'writer_monthly_capacity_settings',
                'month_field' => 'seo_projects.month',
                'page_exclusion' => 'seo_analytics_article_scope_via_content_type_meta',
            ],
            'kpis' => [
                'users_with_work' => $usersWithWork,
                'total_assigned' => $totalAssigned,
                'total_completed' => $totalCompleted,
                'total_pending' => $totalPending,
            ],
            'default_capacity' => $defaultCapacity,
            'team_capacity' => 0,
            'rows' => $rows,
            'workload_max' => max(1, $workloadMax),
        ];
    }

    /**
     * @return array<int, array{active: int, archived: int, total: int}>
     */
    private function itemBreakdownByUser(string $monthKey): array
    {
        /** @var array<int, array{active: int, archived: int, total: int}> $counts */
        $counts = [];
        $monthDate = ContentProjectMonthContext::toDateString($monthKey);

        $activeQuery = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            ->whereDate('p.month', $monthDate)
            ->whereNull('p.archived_at')
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->whereNotNull('p.user_id')
            ->where('p.user_id', '>', 0);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $activeQuery->whereNull('t.deleted_at');
        }
        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($activeQuery, 'p');
        $this->analyticsScope->applyToTaskArticleId($activeQuery, 't.article_id');

        foreach (
            $activeQuery
                ->groupBy('p.user_id')
                ->selectRaw('p.user_id as user_id, COUNT(t.id) as item_count')
                ->get() as $row
        ) {
            $uid = (int) ($row->user_id ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $counts[$uid] ??= ['active' => 0, 'archived' => 0, 'total' => 0];
            $counts[$uid]['active'] = max(0, (int) ($row->item_count ?? 0));
        }

        $archivedQuery = DB::connection('omi_seo_ai')
            ->table('seo_project_archive_items as ai')
            ->join('seo_project_archives as a', 'a.id', '=', 'ai.seo_project_archive_id')
            ->join('seo_projects as p', 'p.id', '=', 'a.project_id')
            ->whereNull('a.restored_at')
            ->whereNotNull('p.archived_at')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            ->whereDate('p.month', $monthDate)
            ->whereNotNull('p.user_id')
            ->where('p.user_id', '>', 0);

        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($archivedQuery, 'p');
        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_archive_items', 'article_id')) {
            $this->analyticsScope->applyToTaskArticleId($archivedQuery, 'ai.article_id');
        }

        foreach (
            $archivedQuery
                ->groupBy('p.user_id')
                ->selectRaw('p.user_id as user_id, COUNT(ai.id) as item_count')
                ->get() as $row
        ) {
            $uid = (int) ($row->user_id ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $counts[$uid] ??= ['active' => 0, 'archived' => 0, 'total' => 0];
            $counts[$uid]['archived'] = max(0, (int) ($row->item_count ?? 0));
        }

        foreach ($counts as $uid => $row) {
            $counts[$uid]['total'] = (int) $row['active'] + (int) $row['archived'];
        }

        return $counts;
    }

    /**
     * @return array<int, array{completed: int, pending: int}>
     */
    private function activeStatusByUser(string $monthKey): array
    {
        $monthDate = ContentProjectMonthContext::toDateString($monthKey);

        $query = DB::connection('omi_seo_ai')
            ->table('seo_project_tasks as t')
            ->join('seo_projects as p', 'p.id', '=', 't.project_id')
            ->where('p.status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('p.kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('p.kind');
            })
            ->whereDate('p.month', $monthDate)
            ->whereNull('p.archived_at')
            ->whereNull('t.archived_at')
            ->where('t.status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->whereNotNull('p.user_id')
            ->where('p.user_id', '>', 0);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('t.deleted_at');
        }
        ContentProjectGlobalLegacyArchive::excludeFromProjectAlias($query, 'p');
        $this->analyticsScope->applyToTaskArticleId($query, 't.article_id');

        $rows = $query
            ->groupBy('p.user_id')
            ->selectRaw('p.user_id as user_id')
            ->selectRaw(
                "SUM(CASE WHEN t.status = '".SeoProjectTask::STATUS_COMPLETED."' OR t.content_manager_reviewed_at IS NOT NULL THEN 1 ELSE 0 END) as completed",
            )
            ->selectRaw(
                "SUM(CASE WHEN t.status != '".SeoProjectTask::STATUS_COMPLETED."' AND t.content_manager_reviewed_at IS NULL THEN 1 ELSE 0 END) as pending",
            )
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) ($row->user_id ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $out[$uid] = [
                'completed' => max(0, (int) ($row->completed ?? 0)),
                'pending' => max(0, (int) ($row->pending ?? 0)),
            ];
        }

        return $out;
    }
}
