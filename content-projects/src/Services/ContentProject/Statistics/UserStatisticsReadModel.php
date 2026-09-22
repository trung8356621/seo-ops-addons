<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Statistics;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectMonthlyWorkloadService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterCapacitySettingsService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectGlobalLegacyArchive;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use App\Services\Users\SeoOpsSystemUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Analytical user/workload Statistics for a Content Project planning month.
 *
 * Semantics:
 * - Month = seo_projects.month (ContentProjectMonthContext YYYY-MM).
 * - Assigned = active + archived execution items (same SSOT as capacity).
 * - Completed = active tasks with status completed (or reviewing with CM stamp)
 *   + all archived items count as completed for progress.
 * - Pending = assigned active tasks that are not completed.
 * - Capacity = ContentProjectWriterCapacitySettingsService effective capacity.
 */
final class UserStatisticsReadModel
{
    public function __construct(
        private readonly ContentProjectMonthlyWorkloadService $workload,
        private readonly ContentProjectWriterMonthlyCapacityService $writerCapacity,
        private readonly ContentProjectWriterCapacitySettingsService $capacitySettings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(string $month, ?int $userId = null): array
    {
        $monthKey = ContentProjectMonthContext::normalize($month);
        $payload = $this->workload->forMonth($monthKey, ContentProjectMonthlyWorkloadService::SCOPE_ALL);
        $statusByUser = $this->activeStatusByUser($monthKey);

        $rows = [];
        foreach ($payload['by_writer'] as $writer) {
            $uid = (int) ($writer['user_id'] ?? 0);
            if ($uid <= 0 || SeoOpsSystemUser::isSystemUserId($uid)) {
                continue;
            }
            if ($userId !== null && $userId > 0 && $uid !== $userId) {
                continue;
            }

            $status = $statusByUser[$uid] ?? ['completed' => 0, 'pending' => 0];
            $archived = (int) ($writer['archived_count'] ?? 0);
            $completed = (int) ($status['completed'] ?? 0) + $archived;
            $pending = (int) ($status['pending'] ?? 0);
            $assigned = (int) ($writer['total_count'] ?? 0);
            $capacity = (int) ($writer['capacity'] ?? $payload['default_capacity']);
            $progress = $assigned > 0 ? (int) round(($completed / $assigned) * 100) : 0;

            $rows[] = [
                'user_id' => $uid,
                'name' => (string) ($writer['name'] ?? ('#'.$uid)),
                'assigned' => $assigned,
                'completed' => $completed,
                'pending' => $pending,
                'progress' => min(100, max(0, $progress)),
                'capacity' => $capacity,
                'remaining' => (int) ($writer['remaining'] ?? ($capacity - $assigned)),
                'active_count' => (int) ($writer['active_count'] ?? 0),
                'archived_count' => $archived,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => ($b['assigned'] ?? 0) <=> ($a['assigned'] ?? 0),
        );

        $usersWithWork = count(array_filter($rows, static fn (array $r): bool => ($r['assigned'] ?? 0) > 0));
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
            ],
            'kpis' => [
                'users_with_work' => $usersWithWork,
                'total_assigned' => $totalAssigned,
                'total_completed' => $totalCompleted,
                'total_pending' => $totalPending,
            ],
            'default_capacity' => (int) ($payload['default_capacity'] ?? $this->capacitySettings->defaultMonthlyCapacity()),
            'team_capacity' => (int) ($payload['team_capacity'] ?? 0),
            'rows' => $rows,
            'workload_max' => max(1, (int) ($payload['writer_max'] ?? 1)),
        ];
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
