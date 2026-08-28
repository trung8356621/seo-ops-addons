<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectPlannerRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SplitDraftContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Planner\ContentProjectPlannerRunService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectWriterMonthlyCapacityService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAllocator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

/**
 * Move reviewed Draft planning items into current-month execution Content Projects.
 * One execution project per selected writer with remaining monthly capacity
 * ({@see ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS}).
 * MOVE same task rows (preserve id / article_id uniqueness / origins).
 * Does not call AI. Does not auto-generate.
 * Every created project has a real writer user_id — never System User, actor, or draft owner.
 */
final class SplitDraftContentProjectService
{
    public function __construct(
        private readonly ContentProjectPlannerRunService $plannerRuns,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
        private readonly ContentProjectWriterMonthlyCapacityService $capacity,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @param  list<int|string>  $assigneeIds
     * @return array<string, mixed>
     */
    public function preview(
        SeoProject $draft,
        string $selectionMode,
        ?int $quantity,
        array $itemIds,
        array $assigneeIds = [],
    ): array {
        $resolved = $this->resolveItems($draft, $selectionMode, $quantity, $itemIds);
        $month = $this->currentMonth();
        $taskIds = $resolved['task_ids'];
        $plan = $this->planAllocations($taskIds, $assigneeIds, $month);
        $remaining = max(0, $this->currentDraftItemCount($draft) - count($taskIds));

        return $this->presentPlan(
            $draft,
            $resolved['mode'],
            $taskIds,
            $remaining,
            $month,
            $plan,
        );
    }

    /**
     * @param  list<int>  $itemIds
     * @param  list<int|string>  $assigneeIds
     * @return array<string, mixed>
     */
    public function split(
        SeoProject $draft,
        string $selectionMode,
        ?int $quantity,
        array $itemIds,
        ?int $actorId = null,
        array $assigneeIds = [],
    ): array {
        unset($actorId);

        if (! $draft->isDraftPlanning()) {
            throw new InvalidArgumentException('PROJECT_NOT_DRAFT: Only Draft projects can be split.');
        }

        $siteId = (int) ($draft->site_id ?? 0);
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Draft domain is required.');
        }

        $this->assertNoActivePlannerMaterialization($draft);

        $month = $this->currentMonth();

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $draft,
            $selectionMode,
            $quantity,
            $itemIds,
            $assigneeIds,
            $month,
            $siteId,
        ): array {
            /** @var SeoProject|null $lockedDraft */
            $lockedDraft = SeoProject::query()
                ->whereKey((int) $draft->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedDraft instanceof SeoProject || ! $lockedDraft->isDraftPlanning()) {
                throw new RuntimeException('Draft project disappeared or is no longer Draft.');
            }

            $resolved = $this->resolveItems($lockedDraft, $selectionMode, $quantity, $itemIds);
            $taskIds = $resolved['task_ids'];
            if ($taskIds === []) {
                throw new InvalidArgumentException('No reviewed Draft items to move.');
            }

            $writerIds = $this->capacity->normalizeUserIds($assigneeIds);
            if ($writerIds === []) {
                throw new InvalidArgumentException(
                    (string) __('seo-content-ai::filament.projects.draft_split_no_writers'),
                );
            }

            SeoProject::query()
                ->activeProjects()
                ->whereIn('user_id', $writerIds)
                ->whereDate('month', $month->format('Y-m-d'))
                ->where('status', '!=', SeoProject::STATUS_DRAFT)
                ->lockForUpdate()
                ->get(['id']);

            $plan = $this->planAllocations($taskIds, $writerIds, $month);
            if ((int) $plan['unallocated_count'] > 0) {
                throw new InvalidArgumentException((string) $plan['insufficient_message']);
            }
            if ($plan['allocations'] === []) {
                throw new InvalidArgumentException(
                    (string) __('seo-content-ai::filament.projects.draft_split_no_writers'),
                );
            }

            $tasks = SeoProjectTask::query()
                ->where('project_id', (int) $lockedDraft->getKey())
                ->whereIn('id', $taskIds)
                ->whereNull('archived_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(static fn (SeoProjectTask $task): int => (int) $task->id);

            if ($tasks->count() !== count($taskIds)) {
                throw new InvalidArgumentException('One or more items are no longer in this Draft.');
            }

            foreach ($taskIds as $taskId) {
                $task = $tasks->get($taskId);
                if (! $task instanceof SeoProjectTask) {
                    throw new InvalidArgumentException('Item '.$taskId.' is missing.');
                }
                $this->assertTaskSplittable($task);
                $this->assertTaskReviewed($task);
            }

            $createdProjects = [];
            $allMoved = [];
            $reservedNames = [];

            foreach ($plan['allocations'] as $allocation) {
                $chunkIds = $allocation['task_ids'];
                $writerId = (int) $allocation['user_id'];
                if ($chunkIds === [] || $writerId <= 0) {
                    continue;
                }

                $name = $this->nextExecutionProjectName($siteId, $month, $reservedNames);
                $reservedNames[] = $name;

                $execution = SeoProject::query()->create([
                    'name' => $name,
                    'site_id' => $siteId,
                    'month' => $month->format('Y-m-d'),
                    'status' => SeoProject::STATUS_PENDING,
                    'kind' => SeoProject::KIND_MONTHLY,
                    'user_id' => $writerId,
                    'total_tasks' => 0,
                    'description' => null,
                    'source_draft_project_id' => (int) $lockedDraft->getKey(),
                ]);

                $monthStart = $execution->monthCarbon();
                $dayIndex = 0;
                foreach ($chunkIds as $taskId) {
                    $task = $tasks->get($taskId);
                    if (! $task instanceof SeoProjectTask) {
                        throw new RuntimeException('Locked task disappeared during split.');
                    }

                    $task->forceFill([
                        'project_id' => (int) $execution->getKey(),
                        'site_id' => $siteId,
                        'status' => SeoProjectTask::STATUS_PENDING,
                        'target_date' => $monthStart->copy()->addDays($dayIndex)->format('Y-m-d'),
                    ])->save();

                    SeoContentProjectItemOrigin::query()
                        ->where('project_task_id', $taskId)
                        ->update([
                            'project_id' => (int) $execution->getKey(),
                        ]);

                    $allMoved[] = $taskId;
                    $dayIndex++;
                }

                $execution->syncTotalTasksCounter();
                $this->articleOwnerSync->syncProjectArticles($execution->fresh() ?? $execution);

                $createdProjects[] = [
                    'execution_project_id' => (int) $execution->getKey(),
                    'month' => $month->format('Y-m-d'),
                    'month_label' => $month->format('n/Y'),
                    'project_name' => (string) $execution->name,
                    'moved_count' => count($chunkIds),
                    'item_count' => count($chunkIds),
                    'task_ids' => $chunkIds,
                    'assignee_id' => $writerId,
                    'user_id' => $writerId,
                    'user_name' => (string) ($allocation['user_name'] ?? '#'.$writerId),
                    'has_real_writer' => true,
                    'status' => SeoProject::STATUS_PENDING,
                ];
            }

            $lockedDraft->syncTotalTasksCounter();
            $remaining = $this->currentDraftItemCount($lockedDraft->fresh() ?? $lockedDraft);
            $first = $createdProjects[0] ?? null;

            return [
                'source_draft_project_id' => (int) $lockedDraft->getKey(),
                'execution_project_id' => (int) ($first['execution_project_id'] ?? 0),
                'execution_project_ids' => array_map(
                    static fn (array $row): int => (int) $row['execution_project_id'],
                    $createdProjects,
                ),
                'projects' => $createdProjects,
                'allocations' => $createdProjects,
                'moved_count' => count($allMoved),
                'remaining_count' => $remaining,
                'reviewed_remaining_count' => $this->currentReviewedDraftItemCount($lockedDraft->fresh() ?? $lockedDraft),
                'month' => $month->format('Y-m-d'),
                'month_label' => $month->format('m/Y'),
                'project_count' => count($createdProjects),
                'project_name' => (string) ($first['project_name'] ?? ''),
                'task_ids' => $allMoved,
                'selection_mode' => $resolved['mode'],
                'auto_generate' => false,
                'assignee_id' => (int) ($first['user_id'] ?? 0) ?: null,
                'has_real_writer' => true,
                'insufficient_slots' => 0,
                'status' => SeoProject::STATUS_PENDING,
                'max_writer_monthly_items' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
                'max_items_per_project' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
            ];
        });
    }

    /**
     * Stable First N order among REVIEWED draft items only.
     *
     * @param  list<int>  $itemIds
     * @return array{mode: string, task_ids: list<int>}
     */
    public function resolveItems(
        SeoProject $draft,
        string $selectionMode,
        ?int $quantity,
        array $itemIds,
    ): array {
        $mode = strtolower(trim($selectionMode));
        $available = $this->orderedReviewedDraftTaskIds($draft);
        $availableCount = count($available);

        if ($mode === SplitDraftContentProjectCommand::MODE_ALL
            || $mode === 'activate_all'
        ) {
            return [
                'mode' => SplitDraftContentProjectCommand::MODE_ALL,
                'task_ids' => $available,
            ];
        }

        if ($mode === SplitDraftContentProjectCommand::MODE_SELECTED
            || $mode === 'selected'
        ) {
            $requested = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => (int) $id, $itemIds),
                static fn (int $id): bool => $id > 0,
            )));
            if ($requested === []) {
                throw new InvalidArgumentException('Selected item list is empty.');
            }

            $lookup = array_fill_keys($available, true);
            foreach ($requested as $id) {
                if (! isset($lookup[$id])) {
                    throw new InvalidArgumentException(
                        'Item '.$id.' is not a reviewed Draft item eligible for split.',
                    );
                }
            }

            $ordered = [];
            foreach ($available as $id) {
                if (in_array($id, $requested, true)) {
                    $ordered[] = $id;
                }
            }

            return [
                'mode' => SplitDraftContentProjectCommand::MODE_SELECTED,
                'task_ids' => $ordered,
            ];
        }

        $n = (int) ($quantity ?? 0);
        if ($n < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        if ($availableCount < 1) {
            throw new InvalidArgumentException('No reviewed Draft items to move.');
        }
        if ($n > $availableCount) {
            throw new InvalidArgumentException(
                sprintf('Requested %d, Draft has %d reviewed items.', $n, $availableCount),
            );
        }

        return [
            'mode' => SplitDraftContentProjectCommand::MODE_FIRST_N,
            'task_ids' => array_slice($available, 0, $n),
        ];
    }

    /**
     * @param  list<int>  $taskIds
     * @param  list<int|string>  $assigneeIds
     * @return array{
     *     allocations: list<array{user_id: int, user_name: string, item_count: int, task_ids: list<int>}>,
     *     unallocated_count: int,
     *     unallocated_task_ids: list<int>,
     *     selected_capacity: int,
     *     insufficient_slots: int,
     *     insufficient_message: string
     * }
     */
    public function planAllocations(array $taskIds, array $assigneeIds, Carbon|string $month): array
    {
        $writerIds = $this->capacity->normalizeUserIds($assigneeIds);
        $remainingByUser = $this->capacity->remainingByUserId($writerIds, $month);
        $allocated = ContentProjectWriterAllocator::allocate($taskIds, $writerIds, $remainingByUser);
        $names = $this->capacity->displayNamesByUserId(
            array_map(static fn (array $row): int => (int) $row['user_id'], $allocated['allocations']),
        );

        $allocations = [];
        foreach ($allocated['allocations'] as $row) {
            $userId = (int) $row['user_id'];
            $allocations[] = [
                'user_id' => $userId,
                'user_name' => (string) ($names[$userId] ?? '#'.$userId),
                'item_count' => (int) $row['item_count'],
                'task_ids' => $row['task_ids'],
            ];
        }

        $selectedCapacity = 0;
        foreach ($writerIds as $userId) {
            $selectedCapacity += max(0, (int) ($remainingByUser[$userId] ?? 0));
        }

        $shortfall = (int) $allocated['unallocated_count'];

        return [
            'allocations' => $allocations,
            'unallocated_count' => $shortfall,
            'unallocated_task_ids' => $allocated['unallocated_task_ids'],
            'selected_capacity' => $selectedCapacity,
            'insufficient_slots' => $shortfall,
            'insufficient_message' => $shortfall > 0
                ? ContentProjectExecutionLimits::insufficientCapacityMessage($shortfall)
                : '',
        ];
    }

    /**
     * @param  list<int>  $taskIds
     * @param  array{
     *     allocations: list<array{user_id: int, user_name: string, item_count: int, task_ids: list<int>}>,
     *     unallocated_count: int,
     *     selected_capacity: int,
     *     insufficient_slots: int,
     *     insufficient_message: string
     * }  $plan
     * @return array<string, mixed>
     */
    private function presentPlan(
        SeoProject $draft,
        string $mode,
        array $taskIds,
        int $remainingDraftCount,
        Carbon $month,
        array $plan,
    ): array {
        $allocations = [];
        foreach ($plan['allocations'] as $row) {
            $allocations[] = [
                'user_id' => (int) $row['user_id'],
                'user_name' => (string) $row['user_name'],
                'item_count' => (int) $row['item_count'],
                'moved_count' => (int) $row['item_count'],
                'task_ids' => $row['task_ids'],
                'assignee_id' => (int) $row['user_id'],
                'has_real_writer' => true,
                'month' => $month->format('Y-m-d'),
                'month_label' => $month->format('n/Y'),
            ];
        }

        $first = $allocations[0] ?? null;

        return [
            'source_draft_project_id' => (int) $draft->getKey(),
            'selection_mode' => $mode,
            'moved_count' => count($taskIds),
            'remaining_count' => $remainingDraftCount,
            'reviewed_eligible_count' => $this->currentReviewedDraftItemCount($draft),
            'task_ids' => $taskIds,
            'target_month' => $month->format('Y-m-d'),
            'target_month_label' => $month->format('m/Y'),
            'project_count' => count($allocations),
            'projects' => $allocations,
            'allocations' => $allocations,
            'project_name' => '',
            'auto_generate' => false,
            'assignee_id' => (int) ($first['user_id'] ?? 0) ?: null,
            'has_real_writer' => $allocations !== [],
            'selected_capacity' => (int) $plan['selected_capacity'],
            'insufficient_slots' => (int) $plan['insufficient_slots'],
            'insufficient_message' => (string) $plan['insufficient_message'],
            'max_writer_monthly_items' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
            'max_items_per_project' => ContentProjectExecutionLimits::MAX_WRITER_MONTHLY_ITEMS,
        ];
    }

    /**
     * Next auto name for site+month: "project n/Y", then "project n/Y-2", "-3", …
     * Suffix = max existing (+ reserved) + 1; does not reuse holes.
     * Base name counts as suffix 1.
     *
     * @param  list<string>  $reservedNames
     */
    public function nextExecutionProjectName(
        int $siteId,
        Carbon|string $month,
        array $reservedNames = [],
    ): string {
        $carbon = Carbon::parse($month)->startOfMonth();
        $base = SeoProject::defaultNameFromMonth($carbon);
        $maxSuffix = 0;

        if ($siteId > 0) {
            $existing = SeoProject::query()
                ->where('site_id', $siteId)
                ->whereDate('month', $carbon->format('Y-m-d'))
                ->pluck('name');

            foreach ($existing as $name) {
                $maxSuffix = max($maxSuffix, $this->executionNameSuffix((string) $name, $base));
            }
        }

        foreach ($reservedNames as $name) {
            $maxSuffix = max($maxSuffix, $this->executionNameSuffix((string) $name, $base));
        }

        if ($maxSuffix < 1) {
            return $base;
        }

        return $base.'-'.($maxSuffix + 1);
    }

    /**
     * @return list<int>
     */
    public function orderedDraftTaskIds(SeoProject $draft): array
    {
        return SeoProjectTask::query()
            ->where('project_id', (int) $draft->getKey())
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Eligible split set: planning_reviewed_at IS NOT NULL.
     *
     * @return list<int>
     */
    public function orderedReviewedDraftTaskIds(SeoProject $draft): array
    {
        $query = SeoProjectTask::query()
            ->where('project_id', (int) $draft->getKey())
            ->whereNull('archived_at')
            ->where('status', '!=', SeoProjectTask::STATUS_CANCELLED)
            ->orderBy('id');

        if (Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
            $query->whereNotNull('planning_reviewed_at');
        } else {
            // Without review column, nothing is eligible (fail closed).
            return [];
        }

        return $query
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function currentDraftItemCount(SeoProject $draft): int
    {
        return count($this->orderedDraftTaskIds($draft));
    }

    public function currentReviewedDraftItemCount(SeoProject $draft): int
    {
        return count($this->orderedReviewedDraftTaskIds($draft));
    }

    public function currentMonth(): Carbon
    {
        return Carbon::now()->startOfMonth();
    }

    private function executionNameSuffix(string $name, string $base): int
    {
        $name = trim($name);
        if ($name === $base) {
            return 1;
        }

        if (preg_match('/^'.preg_quote($base, '/').'-(\d+)$/', $name, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return 0;
    }

    private function assertNoActivePlannerMaterialization(SeoProject $draft): void
    {
        foreach ([
            SeoContentProjectPlannerRun::SOURCE_SEO_AUDIT,
            SeoContentProjectPlannerRun::SOURCE_AI_NEW_CONTENT,
        ] as $source) {
            $active = $this->plannerRuns->findActive($draft, $source);
            if ($active instanceof SeoContentProjectPlannerRun) {
                throw new InvalidArgumentException(
                    'Cannot split while a planner run is still queued/running.',
                );
            }
        }
    }

    private function assertTaskReviewed(SeoProjectTask $task): void
    {
        if (! Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', 'planning_reviewed_at')) {
            throw new InvalidArgumentException('Planning review is unavailable.');
        }
        if ($task->planning_reviewed_at === null) {
            throw new InvalidArgumentException(
                'Item '.$task->id.' is unreviewed and cannot be split.',
            );
        }
    }

    private function assertTaskSplittable(SeoProjectTask $task): void
    {
        $status = (string) ($task->status ?? '');
        $busy = [
            SeoProjectTask::STATUS_WRITING,
            SeoProjectTask::STATUS_PROCESSING,
            SeoProjectTask::STATUS_REVIEWING,
        ];
        if (in_array($status, $busy, true)) {
            throw new InvalidArgumentException(
                'Item '.$task->id.' has active execution state and cannot be split.',
            );
        }

        if (in_array($status, [
            SeoProjectTask::STATUS_COMPLETED,
            SeoProjectTask::STATUS_FAILED,
        ], true)) {
            throw new InvalidArgumentException(
                'Item '.$task->id.' has execution history and cannot be split from Draft.',
            );
        }

        $hasRun = SeoProjectRunItem::query()
            ->where('task_id', (int) $task->id)
            ->exists();
        if ($hasRun) {
            throw new InvalidArgumentException(
                'Item '.$task->id.' already has generation run history and cannot be split from Draft.',
            );
        }
    }
}
