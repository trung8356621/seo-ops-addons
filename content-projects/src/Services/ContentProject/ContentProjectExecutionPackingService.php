<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectTaskMoveService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pack writer-allocated items into max-30 Execution Projects.
 * Reuses mutable existing projects for the same writer + execution month
 * before creating new containers. Domain/site is NOT a grouping key.
 */
final class ContentProjectExecutionPackingService
{
    public function __construct(
        private readonly SeoProjectTaskMoveService $moveService,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
    ) {}

    public function maxItemsPerProject(): int
    {
        return ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS;
    }

    /**
     * Mutable/reusable execution containers for writer+month.
     * Order: base name (project n/Y) first, then id ascending.
     *
     * @return Collection<int, SeoProject>
     */
    public function listReusableProjects(int $userId, Carbon|string $month): Collection
    {
        if ($userId <= 0) {
            return collect();
        }

        $monthDate = Carbon::parse($month)->startOfMonth()->format('Y-m-d');
        $baseName = SeoProject::defaultNameFromMonth($monthDate);

        $projects = SeoProject::query()
            ->activeProjects()
            ->where('user_id', $userId)
            ->whereDate('month', $monthDate)
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (SeoProject $project): bool => $this->isReusable($project))
            ->values();

        return $projects->sortBy(function (SeoProject $project) use ($baseName): array {
            $name = trim((string) ($project->name ?? ''));
            $isBase = $name === $baseName ? 0 : 1;

            return [$isBase, (int) $project->getKey()];
        })->values();
    }

    public function isReusable(SeoProject $project): bool
    {
        if ($project->isDraftPlanning() || $project->isArchive() || $project->isProjectArchived()) {
            return false;
        }

        $status = (string) ($project->status ?? '');
        if (in_array($status, [
            SeoProject::STATUS_RUNNING,
            SeoProject::STATUS_COMPLETED,
            SeoProject::STATUS_PAUSED,
        ], true)) {
            return false;
        }

        // pending / manual / approved — only when never started
        return ! $this->moveService->hasStartedExecution($project);
    }

    /**
     * Free slots remaining under max-30 for a project.
     */
    public function freeSlots(SeoProject $project): int
    {
        $current = $this->activeItemCount($project);

        return max(0, $this->maxItemsPerProject() - $current);
    }

    public function activeItemCount(SeoProject $project): int
    {
        $query = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED);

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return (int) $query->count();
    }

    /**
     * Plan packing of NEW task ids into existing free slots then new chunks.
     *
     * @param  list<int>  $taskIds
     * @return list<array{
     *     project_id: int|null,
     *     reused: bool,
     *     task_ids: list<int>,
     *     slots_before: int,
     *     item_count: int
     * }>
     */
    public function planPack(int $userId, Carbon|string $month, array $taskIds): array
    {
        $taskIds = array_values(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        ));

        if ($taskIds === [] || $userId <= 0) {
            return [];
        }

        $remaining = $taskIds;
        $bins = [];
        $projects = $this->listReusableProjects($userId, $month);

        foreach ($projects as $project) {
            if ($remaining === []) {
                break;
            }
            $free = $this->freeSlots($project);
            if ($free < 1) {
                continue;
            }
            $take = array_splice($remaining, 0, $free);
            if ($take === []) {
                continue;
            }
            $before = $this->activeItemCount($project);
            $bins[] = [
                'project_id' => (int) $project->getKey(),
                'reused' => true,
                'task_ids' => $take,
                'slots_before' => $before,
                'item_count' => count($take),
            ];
        }

        $max = $this->maxItemsPerProject();
        while ($remaining !== []) {
            $take = array_splice($remaining, 0, $max);
            $bins[] = [
                'project_id' => null,
                'reused' => false,
                'task_ids' => $take,
                'slots_before' => 0,
                'item_count' => count($take),
            ];
        }

        return $bins;
    }

    /**
     * Minimal repack plan for ALL mutable items already on writer+month projects.
     *
     * @return array{
     *     user_id: int,
     *     month: string,
     *     before: list<array{project_id: int, name: string, item_count: int}>,
     *     bins: list<array{project_id: int|null, reused: bool, task_ids: list<int>, item_count: int}>,
     *     empty_project_ids: list<int>,
     *     skipped_projects: list<array{project_id: int, name: string, reason: string}>,
     *     task_count: int
     * }
     */
    public function planRepack(int $userId, Carbon|string $month): array
    {
        $monthDate = Carbon::parse($month)->startOfMonth()->format('Y-m-d');
        $monthCarbon = Carbon::parse($monthDate);

        $allMonthProjects = SeoProject::query()
            ->activeProjects()
            ->where('user_id', $userId)
            ->whereDate('month', $monthDate)
            ->where('status', '!=', SeoProject::STATUS_DRAFT)
            ->where(function ($builder): void {
                $builder
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->orderBy('id')
            ->get();

        $skipped = [];
        $reusable = [];
        foreach ($allMonthProjects as $project) {
            if (! $project instanceof SeoProject) {
                continue;
            }
            if ($this->isReusable($project)) {
                $reusable[] = $project;
            } else {
                $skipped[] = [
                    'project_id' => (int) $project->getKey(),
                    'name' => (string) ($project->name ?? ''),
                    'reason' => $this->skipReason($project),
                ];
            }
        }

        $baseName = SeoProject::defaultNameFromMonth($monthDate);
        usort($reusable, static function (SeoProject $a, SeoProject $b) use ($baseName): int {
            $aBase = trim((string) ($a->name ?? '')) === $baseName ? 0 : 1;
            $bBase = trim((string) ($b->name ?? '')) === $baseName ? 0 : 1;
            if ($aBase !== $bBase) {
                return $aBase <=> $bBase;
            }

            return (int) $a->getKey() <=> (int) $b->getKey();
        });

        $before = [];
        $orderedTaskIds = [];
        foreach ($reusable as $project) {
            $ids = $this->activeTaskIdsOrdered($project);
            $before[] = [
                'project_id' => (int) $project->getKey(),
                'name' => (string) ($project->name ?? ''),
                'item_count' => count($ids),
            ];
            foreach ($ids as $id) {
                $orderedTaskIds[] = $id;
            }
        }

        $taskCount = count($orderedTaskIds);
        $max = $this->maxItemsPerProject();
        $needed = $taskCount > 0 ? (int) ceil($taskCount / $max) : 0;

        $bins = [];
        $offset = 0;
        for ($i = 0; $i < $needed; $i++) {
            $chunk = array_slice($orderedTaskIds, $offset, $max);
            $offset += count($chunk);
            $existing = $reusable[$i] ?? null;
            $bins[] = [
                'project_id' => $existing instanceof SeoProject ? (int) $existing->getKey() : null,
                'reused' => $existing instanceof SeoProject,
                'task_ids' => $chunk,
                'item_count' => count($chunk),
            ];
        }

        $emptyIds = [];
        for ($i = $needed; $i < count($reusable); $i++) {
            $emptyIds[] = (int) $reusable[$i]->getKey();
        }

        return [
            'user_id' => $userId,
            'month' => $monthDate,
            'before' => $before,
            'bins' => $bins,
            'empty_project_ids' => $emptyIds,
            'skipped_projects' => $skipped,
            'task_count' => $taskCount,
            'month_carbon' => $monthCarbon,
        ];
    }

    /**
     * Apply a repack plan transactionally (moves items, creates containers, deletes empties).
     *
     * @param  array{
     *     user_id: int,
     *     month: string,
     *     bins: list<array{project_id: int|null, reused: bool, task_ids: list<int>, item_count: int}>,
     *     empty_project_ids: list<int>,
     *     month_carbon?: Carbon
     * }  $plan
     * @param  callable(int, Carbon, list<string>): string  $nameResolver
     * @return array{
     *     moved: int,
     *     reused_project_ids: list<int>,
     *     created_project_ids: list<int>,
     *     deleted_project_ids: list<int>
     * }
     */
    public function applyRepack(array $plan, callable $nameResolver): array
    {
        $userId = (int) ($plan['user_id'] ?? 0);
        $month = Carbon::parse((string) ($plan['month'] ?? now()->format('Y-m-d')))->startOfMonth();
        $bins = is_array($plan['bins'] ?? null) ? $plan['bins'] : [];
        $emptyIds = array_values(array_map('intval', $plan['empty_project_ids'] ?? []));

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $userId,
            $month,
            $bins,
            $emptyIds,
            $nameResolver,
        ): array {
            $reused = [];
            $created = [];
            $moved = 0;
            $reservedNames = [];

            foreach ($bins as $bin) {
                $taskIds = array_values(array_map('intval', $bin['task_ids'] ?? []));
                if ($taskIds === []) {
                    continue;
                }

                $projectId = isset($bin['project_id']) ? (int) $bin['project_id'] : 0;
                if ($projectId > 0) {
                    $execution = SeoProject::query()->whereKey($projectId)->lockForUpdate()->first();
                    if (! $execution instanceof SeoProject || ! $this->isReusable($execution)) {
                        throw new RuntimeException('Reusable project disappeared during repack: '.$projectId);
                    }
                    $reused[] = $projectId;
                } else {
                    $name = $nameResolver($userId, $month, $reservedNames);
                    $reservedNames[] = $name;
                    $execution = SeoProject::query()->create([
                        'name' => $name,
                        'site_id' => null,
                        'month' => $month->format('Y-m-d'),
                        'status' => SeoProject::STATUS_PENDING,
                        'kind' => SeoProject::KIND_MONTHLY,
                        'user_id' => $userId,
                        'total_tasks' => 0,
                        'description' => null,
                        'source_draft_project_id' => null,
                    ]);
                    $created[] = (int) $execution->getKey();
                }

                $moved += $this->assignTasksToProject($execution, $taskIds, $month);
                $execution->syncTotalTasksCounter();
                $this->articleOwnerSync->syncProjectArticles($execution->fresh() ?? $execution);
            }

            $deleted = [];
            foreach ($emptyIds as $emptyId) {
                if ($emptyId <= 0 || in_array($emptyId, $reused, true) || in_array($emptyId, $created, true)) {
                    continue;
                }
                if ($this->deleteEmptyMutableProject($emptyId)) {
                    $deleted[] = $emptyId;
                }
            }

            return [
                'moved' => $moved,
                'reused_project_ids' => array_values(array_unique($reused)),
                'created_project_ids' => $created,
                'deleted_project_ids' => $deleted,
            ];
        });
    }

    /**
     * Delete empty mutable execution without restoring items to Draft.
     */
    public function deleteEmptyMutableProject(int $projectId): bool
    {
        if ($projectId <= 0) {
            return false;
        }

        return (bool) DB::connection('omi_seo_ai')->transaction(function () use ($projectId): bool {
            $project = SeoProject::query()->whereKey($projectId)->lockForUpdate()->first();
            if (! $project instanceof SeoProject) {
                return false;
            }
            if (! $this->isReusable($project)) {
                return false;
            }
            if ($this->activeItemCount($project) > 0) {
                throw new InvalidArgumentException(
                    'Cannot empty-delete project #'.$projectId.' — still has items.',
                );
            }

            $project->delete();

            return true;
        });
    }

    /**
     * @param  list<int>  $taskIds
     */
    public function assignTasksToProject(SeoProject $execution, array $taskIds, Carbon $month): int
    {
        $executionId = (int) $execution->getKey();
        $tasks = SeoProjectTask::query()
            ->whereIn('id', $taskIds)
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (SeoProjectTask $t): int => (int) $t->id);

        $monthStart = $month->copy()->startOfMonth();
        $dayIndex = 0;
        $moved = 0;

        foreach ($taskIds as $taskId) {
            $task = $tasks->get($taskId);
            if (! $task instanceof SeoProjectTask) {
                throw new RuntimeException('Task missing during pack: '.$taskId);
            }

            $siteId = (int) ($task->site_id ?? 0);
            $payload = [
                'project_id' => $executionId,
                'status' => SeoProjectTask::STATUS_PENDING,
                'target_date' => $monthStart->copy()->addDays($dayIndex)->format('Y-m-d'),
            ];
            // Preserve item-level domain; never clear an existing site_id.
            if ($siteId > 0) {
                $payload['site_id'] = $siteId;
            }

            $task->forceFill($payload)->save();

            SeoContentProjectItemOrigin::query()
                ->where('project_task_id', $taskId)
                ->update(['project_id' => $executionId]);

            $moved++;
            $dayIndex++;
        }

        return $moved;
    }

    /**
     * @return list<int>
     */
    private function activeTaskIdsOrdered(SeoProject $project): array
    {
        $query = SeoProjectTask::query()
            ->where('project_id', (int) $project->getKey())
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->orderBy('id');

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    private function skipReason(SeoProject $project): string
    {
        if ($project->isArchive() || $project->isProjectArchived()) {
            return 'archived';
        }
        if ($this->moveService->hasStartedExecution($project)) {
            return 'has_started_execution';
        }

        return 'lifecycle_not_reusable:'.(string) ($project->status ?? '');
    }
}
