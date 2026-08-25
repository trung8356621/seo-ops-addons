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
use Omnichannel\Addons\ContentProjects\Services\SeoProjectArticleOwnerSyncService;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Move Draft planning items into a new execution Content Project.
 * MOVE same task rows (preserve id / article_id uniqueness / origins).
 * Does not call AI. Does not auto-generate.
 */
final class SplitDraftContentProjectService
{
    public function __construct(
        private readonly ContentProjectPlannerRunService $plannerRuns,
        private readonly SeoProjectArticleOwnerSyncService $articleOwnerSync,
    ) {}

    /**
     * @param  list<int>  $itemIds
     * @return array<string, mixed>
     */
    public function preview(
        SeoProject $draft,
        string $selectionMode,
        ?int $quantity,
        array $itemIds,
        ?string $targetMonth,
        ?string $projectName,
    ): array {
        $resolved = $this->resolveItems($draft, $selectionMode, $quantity, $itemIds);
        $month = $this->normalizeMonth($targetMonth);
        $name = $this->resolveName($projectName, $month, $draft);
        $remaining = max(0, $this->currentDraftItemCount($draft) - count($resolved['task_ids']));

        return [
            'source_draft_project_id' => (int) $draft->getKey(),
            'selection_mode' => $resolved['mode'],
            'moved_count' => count($resolved['task_ids']),
            'remaining_count' => $remaining,
            'task_ids' => $resolved['task_ids'],
            'target_month' => $month->format('Y-m-d'),
            'target_month_label' => $month->format('m/Y'),
            'project_name' => $name,
            'auto_generate' => false,
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<string, mixed>
     */
    public function split(
        SeoProject $draft,
        string $selectionMode,
        ?int $quantity,
        array $itemIds,
        ?string $targetMonth,
        ?string $projectName,
        ?int $actorId,
    ): array {
        if (! $draft->isDraftPlanning()) {
            throw new InvalidArgumentException('PROJECT_NOT_DRAFT: Only Draft projects can be split.');
        }

        $siteId = (int) ($draft->site_id ?? 0);
        if ($siteId <= 0) {
            throw new InvalidArgumentException('Draft domain is required.');
        }

        $this->assertNoActivePlannerMaterialization($draft);

        $month = $this->normalizeMonth($targetMonth);
        $name = $this->resolveName($projectName, $month, $draft);

        return DB::connection('omi_seo_ai')->transaction(function () use (
            $draft,
            $selectionMode,
            $quantity,
            $itemIds,
            $month,
            $name,
            $siteId,
            $actorId,
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
                throw new InvalidArgumentException('No Draft items to move.');
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
            }

            $execution = SeoProject::query()->create([
                'name' => $name,
                'site_id' => $siteId,
                'month' => $month->format('Y-m-d'),
                'status' => SeoProject::STATUS_PENDING,
                'kind' => SeoProject::KIND_MONTHLY,
                'user_id' => (int) ($lockedDraft->user_id ?? $actorId ?? 0) ?: null,
                'total_tasks' => 0,
                'description' => null,
                'source_draft_project_id' => (int) $lockedDraft->getKey(),
            ]);

            $monthStart = $execution->monthCarbon();
            $index = 0;
            foreach ($taskIds as $taskId) {
                $task = $tasks->get($taskId);
                if (! $task instanceof SeoProjectTask) {
                    throw new RuntimeException('Locked task disappeared during split.');
                }

                $task->forceFill([
                    'project_id' => (int) $execution->getKey(),
                    'site_id' => $siteId,
                    'status' => SeoProjectTask::STATUS_PENDING,
                    'target_date' => $monthStart->copy()->addDays($index)->format('Y-m-d'),
                ])->save();

                SeoContentProjectItemOrigin::query()
                    ->where('project_task_id', $taskId)
                    ->update([
                        'project_id' => (int) $execution->getKey(),
                    ]);

                $index++;
            }

            $lockedDraft->syncTotalTasksCounter();
            $execution->syncTotalTasksCounter();
            $this->articleOwnerSync->syncProjectArticles($execution->fresh() ?? $execution);

            $remaining = $this->currentDraftItemCount($lockedDraft->fresh() ?? $lockedDraft);

            return [
                'source_draft_project_id' => (int) $lockedDraft->getKey(),
                'execution_project_id' => (int) $execution->getKey(),
                'moved_count' => count($taskIds),
                'remaining_count' => $remaining,
                'month' => $month->format('Y-m-d'),
                'month_label' => $month->format('m/Y'),
                'project_name' => (string) $execution->name,
                'task_ids' => $taskIds,
                'selection_mode' => $resolved['mode'],
                'auto_generate' => false,
                'status' => SeoProject::STATUS_PENDING,
            ];
        });
    }

    /**
     * Stable First N order: ascending primary key (creation order for auto-increment drafts).
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
        $available = $this->orderedDraftTaskIds($draft);
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
                    throw new InvalidArgumentException('Item '.$id.' does not belong to this Draft.');
                }
            }

            // Preserve Draft stable order among selected ids.
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

        // first_n
        $n = (int) ($quantity ?? 0);
        if ($n < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }
        if ($n > $availableCount) {
            throw new InvalidArgumentException(
                sprintf('Requested %d, Draft has %d.', $n, $availableCount),
            );
        }

        return [
            'mode' => SplitDraftContentProjectCommand::MODE_FIRST_N,
            'task_ids' => array_slice($available, 0, $n),
        ];
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

    public function currentDraftItemCount(SeoProject $draft): int
    {
        return count($this->orderedDraftTaskIds($draft));
    }

    private function normalizeMonth(?string $targetMonth): Carbon
    {
        if ($targetMonth === null || trim($targetMonth) === '') {
            return Carbon::now()->startOfMonth();
        }

        return Carbon::parse($targetMonth)->startOfMonth();
    }

    private function resolveName(?string $projectName, Carbon $month, SeoProject $draft): string
    {
        $custom = trim((string) $projectName);
        if ($custom !== '') {
            return $custom;
        }

        $domain = null;
        $siteId = (int) ($draft->site_id ?? 0);
        if ($siteId > 0) {
            $domain = Site::query()->whereKey($siteId)->value('domain');
        }

        return SeoProject::defaultExecutionName($month, is_string($domain) ? $domain : null);
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
