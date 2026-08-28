<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Models\SeoContentProjectItemOrigin;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectContinuationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class SeoProjectTaskMoveService
{
    public function __construct(
        private readonly ?ContentProjectContinuationService $continuation = null,
    ) {}

    private function continuation(): ContentProjectContinuationService
    {
        return $this->continuation ?? new ContentProjectContinuationService();
    }

    /**
     * Xóa project:
     * - Execution chưa run + có source Draft → restore items (Reviewed) rồi xóa project.
     * - Execution đã bắt đầu run → block.
     * - Draft / project không có source / rỗng → chỉ xóa khi không còn task active.
     *
     * @return array{deleted: bool, moved: int, restored: int, target_project_id: int|null, target_month: string|null}
     */
    public function deleteProject(SeoProject $project): array
    {
        return DB::connection($project->getConnectionName())->transaction(function () use ($project): array {
            /** @var SeoProject|null $locked */
            $locked = SeoProject::query()
                ->whereKey($project->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProject) {
                throw new RuntimeException(__('seo-content-ai::filament.projects.delete_failed'));
            }

            if ($locked->isArchive()) {
                throw ValidationException::withMessages([
                    'project' => __('seo-content-ai::filament.projects.delete_archive_forbidden'),
                ]);
            }

            if ($locked->isProjectArchived()) {
                throw ValidationException::withMessages([
                    'project' => __('seo-content-ai::filament.projects.delete_archived_forbidden'),
                ]);
            }

            if ($locked->isDraftPlanning()) {
                throw ValidationException::withMessages([
                    'project' => __('seo-content-ai::filament.projects.delete_draft_forbidden'),
                ]);
            }

            if ($this->isRestorableUnstartedExecution($locked)) {
                return $this->restoreToSourceDraftAndDelete($locked);
            }

            if ($this->hasStartedExecution($locked)) {
                throw ValidationException::withMessages([
                    'project' => __('seo-content-ai::filament.projects.delete_blocked_already_started'),
                ]);
            }

            $activeTasks = $locked->tasks()
                ->active()
                ->lockForUpdate()
                ->get();

            if ($activeTasks->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'project' => __('seo-content-ai::filament.projects.delete_blocked_has_items', [
                        'count' => $activeTasks->count(),
                    ]),
                ]);
            }

            $archivedTasks = $locked->tasks()
                ->archived()
                ->lockForUpdate()
                ->get();

            foreach ($archivedTasks as $archivedTask) {
                $archivedTask->delete();
            }

            $locked->delete();

            return [
                'deleted' => true,
                'moved' => 0,
                'restored' => 0,
                'target_project_id' => null,
                'target_month' => null,
            ];
        });
    }

    /**
     * Unstarted execution from Draft split: has source_draft_project_id and never ran.
     */
    public function isRestorableUnstartedExecution(SeoProject $project): bool
    {
        if ($project->isDraftPlanning() || $project->isArchive()) {
            return false;
        }

        if ((int) ($project->source_draft_project_id ?? 0) <= 0) {
            return false;
        }

        return ! $this->hasStartedExecution($project);
    }

    /**
     * Project đã bắt đầu: status runtime, hoặc có SeoProjectRun / RunItem,
     * hoặc task đã rời trạng thái pending thuần (writing/processing/…).
     */
    public function hasStartedExecution(SeoProject $project): bool
    {
        $status = (string) ($project->status ?? '');
        if (in_array($status, [
            SeoProject::STATUS_RUNNING,
            SeoProject::STATUS_COMPLETED,
            SeoProject::STATUS_PAUSED,
        ], true)) {
            return true;
        }

        $projectId = (int) $project->getKey();
        if ($projectId <= 0) {
            return false;
        }

        if (SeoProjectRun::query()->where('project_id', $projectId)->exists()) {
            return true;
        }

        $taskIds = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($taskIds === []) {
            return false;
        }

        if (SeoProjectRunItem::query()->whereIn('task_id', $taskIds)->exists()) {
            return true;
        }

        return SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->whereNull('archived_at')
            ->whereIn('status', [
                SeoProjectTask::STATUS_WRITING,
                SeoProjectTask::STATUS_PROCESSING,
                SeoProjectTask::STATUS_REVIEWING,
                SeoProjectTask::STATUS_COMPLETED,
                SeoProjectTask::STATUS_FAILED,
            ])
            ->exists();
    }

    /**
     * @return array{deleted: bool, moved: int, restored: int, target_project_id: int|null, target_month: string|null}
     */
    private function restoreToSourceDraftAndDelete(SeoProject $locked): array
    {
        $sourceDraftId = (int) ($locked->source_draft_project_id ?? 0);

        /** @var SeoProject|null $draft */
        $draft = SeoProject::query()
            ->whereKey($sourceDraftId)
            ->lockForUpdate()
            ->first();

        if (! $draft instanceof SeoProject || ! $draft->isDraftPlanning()) {
            throw ValidationException::withMessages([
                'project' => __('seo-content-ai::filament.projects.delete_blocked_missing_source_draft'),
            ]);
        }

        $activeTasks = $locked->tasks()
            ->active()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $draftMonthStart = $draft->monthCarbon();
        $dayIndex = $draft->registeredTaskCount();
        $restored = 0;

        foreach ($activeTasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }

            // Keep title/keyword/description/origin; keep planning_reviewed_at (Reviewed).
            $task->forceFill([
                'project_id' => (int) $draft->getKey(),
                'site_id' => (int) ($draft->site_id ?? $task->site_id),
                'status' => SeoProjectTask::STATUS_PENDING,
                'target_date' => $draftMonthStart->copy()->addDays($dayIndex)->format('Y-m-d'),
            ])->save();

            SeoContentProjectItemOrigin::query()
                ->where('project_task_id', (int) $task->id)
                ->update([
                    'project_id' => (int) $draft->getKey(),
                ]);

            $dayIndex++;
            $restored++;
        }

        $archivedTasks = $locked->tasks()
            ->archived()
            ->lockForUpdate()
            ->get();

        foreach ($archivedTasks as $archivedTask) {
            $archivedTask->delete();
        }

        $locked->delete();

        $draft->syncTotalTasksCounter();
        app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($draft->fresh() ?? $draft);

        return [
            'deleted' => true,
            'moved' => $restored,
            'restored' => $restored,
            'target_project_id' => (int) $draft->getKey(),
            'target_month' => $draft->monthCarbon()->format('m/Y'),
        ];
    }

    /**
     * @deprecated Dùng {@see self::deleteProject()}. Wrapper giữ lại để tương thích ngược
     * (không còn rollback task sang tháng trước — hành vi cũ gây lệch tháng project khi
     * project chỉ còn task đã archive, ví dụ 6/2026 → 5/2026 → 4/2026 dù "Total items" = 0).
     *
     * @return array{deleted: bool, moved: int, restored: int, target_project_id: int|null, target_month: string|null}
     */
    public function deleteProjectRollingBackToPreviousMonth(SeoProject $project): array
    {
        return $this->deleteProject($project);
    }

    /**
     * Chuyển một hoặc nhiều task sang project tháng khác (cùng domain).
     *
     * @param  list<int>  $taskIds
     * @return array{moved: int, target_project_id: int, target_month: string}
     */
    public function moveTasksToProject(SeoProject $source, SeoProject $target, array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $taskIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($taskIds === []) {
            throw ValidationException::withMessages([
                'task_id' => __('seo-content-ai::filament.projects.move_task_missing'),
            ]);
        }

        if ((int) $source->getKey() === (int) $target->getKey()) {
            throw ValidationException::withMessages([
                'target_project_id' => __('seo-content-ai::filament.projects.move_same_project'),
            ]);
        }

        if ((int) ($source->site_id ?? 0) <= 0
            || (int) ($target->site_id ?? 0) !== (int) $source->site_id
        ) {
            throw ValidationException::withMessages([
                'target_project_id' => __('seo-content-ai::filament.projects.move_domain_mismatch'),
            ]);
        }

        return DB::connection($source->getConnectionName())->transaction(function () use ($source, $target, $taskIds): array {
            /** @var SeoProject|null $lockedSource */
            $lockedSource = SeoProject::query()
                ->whereKey($source->getKey())
                ->lockForUpdate()
                ->first();

            /** @var SeoProject|null $lockedTarget */
            $lockedTarget = SeoProject::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedSource instanceof SeoProject || ! $lockedTarget instanceof SeoProject) {
                throw new RuntimeException(__('seo-content-ai::filament.projects.move_failed'));
            }

            $tasks = $lockedSource->tasks()
                ->whereIn('id', $taskIds)
                ->orderBy('target_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($tasks->count() !== count($taskIds)) {
                throw ValidationException::withMessages([
                    'task_id' => __('seo-content-ai::filament.projects.move_task_missing'),
                ]);
            }

            $lockedTarget->setRelation(
                'tasks',
                $lockedTarget->tasks()->orderBy('id')->lockForUpdate()->get(),
            );

            $this->assertTargetAcceptsMoves($lockedTarget);
            $this->appendTasksToProject($lockedTarget, $tasks);
            $lockedSource->syncTotalTasksCounter();

            app(SeoProjectArticleOwnerSyncService::class)->syncProjectArticles($lockedTarget->fresh() ?? $lockedTarget);

            return [
                'moved' => $tasks->count(),
                'target_project_id' => (int) $lockedTarget->getKey(),
                'target_month' => $lockedTarget->monthCarbon()->format('m/Y'),
            ];
        });
    }

    /**
     * @return array<int, string>
     */
    public function moveTargetOptions(SeoProject $source): array
    {
        $siteId = (int) ($source->site_id ?? 0);
        if ($siteId <= 0) {
            return [];
        }

        return SeoProject::query()
            ->where('site_id', $siteId)
            ->whereKeyNot($source->getKey())
            ->where(function ($query): void {
                $query
                    ->where('kind', SeoProject::KIND_MONTHLY)
                    ->orWhereNull('kind');
            })
            ->orderByDesc('month')
            ->orderBy('id')
            ->get()
            ->filter(static fn (SeoProject $project): bool => ! $project->isArchive())
            ->mapWithKeys(static function (SeoProject $project): array {
                $count = $project->registeredTaskCount();

                return [
                    (int) $project->getKey() => __('seo-content-ai::filament.projects.move_target_option_items', [
                        'name' => (string) $project->name,
                        'month' => $project->monthCarbon()->format('m/Y'),
                        'count' => $count,
                    ]),
                ];
            })
            ->all();
    }

    public function findOrCreatePreviousMonthProject(SeoProject $source): SeoProject
    {
        $siteId = (int) ($source->site_id ?? 0);
        if ($siteId <= 0) {
            throw ValidationException::withMessages([
                'site_id' => __('seo-content-ai::filament.projects.domain_required'),
            ]);
        }

        $previousMonth = $source->monthCarbon()->copy()->subMonthNoOverflow()->startOfMonth();

        return $this->findOrCreateProjectForMonth($source, $previousMonth);
    }

    public function findOrCreateProjectForMonth(SeoProject $source, Carbon|string $month): SeoProject
    {
        return $this->continuation()->findOrCreateContinuation($source, $month);
    }

    /**
     * @deprecated Capacity unlimited — only blocks archive vaults.
     */
    public function assertTargetHasCapacity(SeoProject $target, int $incomingCount): void
    {
        $this->assertTargetAcceptsMoves($target);
    }

    public function assertTargetAcceptsMoves(SeoProject $target): void
    {
        if ($target->isArchive()) {
            throw ValidationException::withMessages([
                'target_project_id' => __('seo-content-ai::filament.projects.move_target_archive'),
            ]);
        }
    }

    /**
     * @param  Collection<int, SeoProjectTask>  $tasks
     */
    private function appendTasksToProject(SeoProject $target, Collection $tasks): void
    {
        $startIndex = $target->registeredTaskCount();
        $monthStart = $target->monthCarbon();

        foreach ($tasks->values() as $index => $task) {
            $task->update([
                'project_id' => (int) $target->getKey(),
                'target_date' => $monthStart->copy()->addDays($startIndex + $index)->format('Y-m-d'),
            ]);
        }

        $target->syncTotalTasksCounter();
    }
}
