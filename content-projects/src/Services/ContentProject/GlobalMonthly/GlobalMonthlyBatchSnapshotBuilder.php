<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use App\Models\User;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectActiveGenerationRunDetector;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProjectGenerationGate;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use Illuminate\Support\Collection;

/**
 * Immutable eligible-task snapshot for a planning month.
 *
 * @phpstan-type SnapshotItem array{
 *     project_id: int,
 *     task_id: int,
 *     item_type: string,
 *     position: int,
 *     excluded: bool,
 *     exclude_reason: ?string
 * }
 */
final class GlobalMonthlyBatchSnapshotBuilder
{
    public function __construct(
        private readonly ContentProjectProjectGenerationGate $generationGate,
        private readonly ContentProjectItemGenerationClassifier $classifier,
        private readonly ContentProjectActiveGenerationRunDetector $activeRuns,
        private readonly LegacyActiveGenerationRunScanner $legacyScanner,
    ) {}

    /**
     * @return array{
     *     planning_month: string,
     *     project_order: list<int>,
     *     projects: list<array{project_id: int, name: ?string, eligible_count: int, excluded_count: int}>,
     *     items: list<SnapshotItem>,
     *     eligible_count: int,
     *     excluded: list<array{project_id: int, task_id: int, reason: string}>,
     *     legacy_active_runs: list<array{run_id: int, project_id: int, status: string, mode: string}>,
     *     lock_busy: bool,
     *     lock_unavailable: bool,
     *     lock_state: string,
     *     lock_holder: ?array<string, mixed>,
     *     lock_message: ?string,
     *     can_start: bool
     * }
     */
    public function preflight(string $planningMonth, GlobalGenerationLock $lock): array
    {
        $built = $this->build($planningMonth);
        $inspect = $lock->inspect();
        $state = (string) ($inspect['state'] ?? GlobalGenerationLock::STATE_UNAVAILABLE);

        return array_merge($built, [
            'legacy_active_runs' => $this->legacyScanner->findActiveRuns(),
            'lock_busy' => $state === GlobalGenerationLock::STATE_HELD,
            'lock_unavailable' => $state === GlobalGenerationLock::STATE_UNAVAILABLE,
            'lock_state' => $state,
            'lock_holder' => is_array($inspect['holder'] ?? null) ? $inspect['holder'] : null,
            'lock_message' => isset($inspect['message']) ? (string) $inspect['message'] : null,
            'can_start' => $state === GlobalGenerationLock::STATE_AVAILABLE,
        ]);
    }

    /**
     * @return array{
     *     planning_month: string,
     *     project_order: list<int>,
     *     projects: list<array{project_id: int, name: ?string, eligible_count: int, pending_count: int, failed_count: int, excluded_count: int}>,
     *     items: list<SnapshotItem>,
     *     eligible_count: int,
     *     pending_count: int,
     *     failed_count: int,
     *     excluded: list<array{project_id: int, task_id: int, reason: string}>
     * }
     */
    public function build(string $planningMonth): array
    {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $monthDate = ContentProjectMonthContext::toDateString($month);

        /** @var Collection<int, SeoProject> $projects */
        $projects = SeoProject::query()
            ->where('kind', SeoProject::KIND_MONTHLY)
            ->whereDate('month', $monthDate)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get();

        $userIds = $projects->pluck('user_id')->filter()->unique()->all();
        $userNames = [];
        if ($userIds !== []) {
            $userNames = User::query()
                ->whereIn('id', $userIds)
                ->pluck('name', 'id')
                ->all();
        }

        $projectOrder = [];
        $projectSummaries = [];
        $items = [];
        $excluded = [];
        $position = 0;

        foreach ($projects as $project) {
            if (! $project instanceof SeoProject || $project->isDraftPlanning() || $project->isArchive()) {
                continue;
            }

            $projectId = (int) $project->getKey();
            $projectOrder[] = $projectId;

            if ($this->activeRuns->hasActiveBulkGeneration($projectId)) {
                $preview = $this->classifier->preview($project);
                foreach ($preview->runDecisions() as $decision) {
                    if ($decision->shouldRun()) {
                        $excluded[] = [
                            'project_id' => $projectId,
                            'task_id' => $decision->taskId,
                            'reason' => 'project_bulk_active',
                        ];
                    }
                }
                $ownerName = trim((string) ($userNames[(int) $project->user_id] ?? ''));
                $projectSummaries[] = [
                    'project_id' => $projectId,
                    'name' => $project->name !== null ? (string) $project->name : null,
                    'owner_name' => $ownerName !== '' ? $ownerName : null,
                    'eligible_count' => 0,
                    'pending_count' => 0,
                    'failed_count' => 0,
                    'excluded_count' => count($excluded),
                ];

                continue;
            }

            $eligibleIds = $this->generationGate->eligibleTaskIds($project);
            $eligibleCount = 0;
            $excludedCount = 0;

            $types = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->whereIn('id', $eligibleIds)
                ->pluck('type', 'id')
                ->all();

            $statuses = SeoProjectTask::query()
                ->where('project_id', $projectId)
                ->whereIn('id', $eligibleIds)
                ->pluck('status', 'id')
                ->all();

            // Preserve canonical project ordering (target_date, id).
            $orderedIds = $project->tasks()
                ->planned()
                ->whereIn('id', $eligibleIds)
                ->orderBy('target_date')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $pendingCount = 0;
            $failedCount = 0;

            foreach ($orderedIds as $taskId) {
                $status = (string) ($statuses[$taskId] ?? SeoProjectTask::STATUS_PENDING);
                if ($status === SeoProjectTask::STATUS_FAILED) {
                    $failedCount++;
                } else {
                    $pendingCount++;
                }

                $position++;
                $items[] = [
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'item_type' => SeoProjectTask::normalizeType((string) ($types[$taskId] ?? SeoProjectTask::TYPE_CREATE)),
                    'position' => $position,
                    'excluded' => false,
                    'exclude_reason' => null,
                ];
                $eligibleCount++;
            }

            $ownerName = trim((string) ($userNames[(int) $project->user_id] ?? ''));
            $projectSummaries[] = [
                'project_id' => $projectId,
                'name' => $project->name !== null ? (string) $project->name : null,
                'owner_name' => $ownerName !== '' ? $ownerName : null,
                'eligible_count' => $eligibleCount,
                'pending_count' => $pendingCount,
                'failed_count' => $failedCount,
                'excluded_count' => $excludedCount,
            ];
        }

        return [
            'planning_month' => $month,
            'project_order' => $projectOrder,
            'projects' => $projectSummaries,
            'items' => $items,
            'eligible_count' => count($items),
            'pending_count' => array_sum(array_column($projectSummaries, 'pending_count')),
            'failed_count' => array_sum(array_column($projectSummaries, 'failed_count')),
            'excluded' => $excluded,
        ];
    }
}
