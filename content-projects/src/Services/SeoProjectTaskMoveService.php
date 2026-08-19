<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Models\SeoProject;
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
     * Xóa project: CHỈ xóa khi không còn task active (cùng nguồn dữ liệu với cột
     * "Total items" trên UI — `tasks()->active()`, xem `SeoProjectResource::getEloquentQuery()`
     * `active_tasks_count`). Task đã archive còn sót lại sẽ bị soft-delete theo project.
     *
     * KHÔNG rollback tháng: project còn task active phải bị chặn xóa, không tự động
     * chuyển task sang project tháng trước (đó là hành vi cũ gây lệch tháng — xem
     * `deleteProjectRollingBackToPreviousMonth` deprecated bên dưới).
     *
     * @return array{deleted: bool, moved: int, target_project_id: int|null, target_month: string|null}
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
                'target_project_id' => null,
                'target_month' => null,
            ];
        });
    }

    /**
     * @deprecated Dùng {@see self::deleteProject()}. Wrapper giữ lại để tương thích ngược
     * (không còn rollback task sang tháng trước — hành vi cũ gây lệch tháng project khi
     * project chỉ còn task đã archive, ví dụ 6/2026 → 5/2026 → 4/2026 dù "Total items" = 0).
     *
     * @return array{deleted: bool, moved: int, target_project_id: int|null, target_month: string|null}
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

            $this->assertTargetHasCapacity($lockedTarget, $tasks->count());
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
            ->filter(static fn (SeoProject $project): bool => $project->remainingTaskCapacity() > 0)
            ->mapWithKeys(static function (SeoProject $project): array {
                $remaining = $project->remainingTaskCapacity();
                $max = $project->maxTasksAllowed();
                $count = $project->registeredTaskCount();

                return [
                    (int) $project->getKey() => __('seo-content-ai::filament.projects.move_target_option', [
                        'name' => (string) $project->name,
                        'month' => $project->monthCarbon()->format('m/Y'),
                        'count' => $count,
                        'max' => $max,
                        'remaining' => $remaining,
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

    public function assertTargetHasCapacity(SeoProject $target, int $incomingCount): void
    {
        if ($incomingCount <= 0) {
            return;
        }

        $remaining = $target->remainingTaskCapacity();
        if ($incomingCount <= $remaining) {
            return;
        }

        throw ValidationException::withMessages([
            'target_project_id' => __('seo-content-ai::filament.projects.move_target_full', [
                'month' => $target->monthCarbon()->format('m/Y'),
                'remaining' => $remaining,
                'needed' => $incomingCount,
                'max' => $target->maxTasksAllowed(),
            ]),
        ]);
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
