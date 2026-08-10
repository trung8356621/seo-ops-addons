<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskEventType;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectTaskStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Source of truth cho archive / restore / soft-delete task.
 */
final class SeoProjectTaskLifecycleService
{
    public function __construct(
        private readonly SeoProjectTaskEventRecorder $eventRecorder,
    ) {}

    public function resolveStatusAfterRestore(?string $statusBeforeArchive): string
    {
        $status = trim((string) $statusBeforeArchive);

        return match ($status) {
            SeoProjectTaskStatus::Completed->value => SeoProjectTaskStatus::Completed->value,
            SeoProjectTaskStatus::Failed->value => SeoProjectTaskStatus::Failed->value,
            SeoProjectTaskStatus::Pending->value => SeoProjectTaskStatus::Pending->value,
            SeoProjectTaskStatus::Draft->value => SeoProjectTaskStatus::Draft->value,
            SeoProjectTaskStatus::Cancelled->value => SeoProjectTaskStatus::Cancelled->value,
            SeoProjectTaskStatus::Writing->value,
            SeoProjectTaskStatus::Processing->value,
            SeoProjectTaskStatus::Reviewing->value => SeoProjectTaskStatus::Pending->value,
            SeoProjectTaskStatus::Archived->value,
            '' => SeoProjectTaskStatus::Pending->value,
            default => SeoProjectTaskStatus::Pending->value,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function archive(
        SeoProjectTask $task,
        ?int $userId = null,
        array $context = [],
    ): SeoProjectTask {
        return DB::connection('omi_seo_ai')->transaction(function () use ($task, $userId, $context): SeoProjectTask {
            /** @var SeoProjectTask|null $locked */
            $locked = SeoProjectTask::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectTask) {
                throw new RuntimeException(ContentProjectErrorCode::TaskNotFound->value);
            }

            if ($locked->deleted_at !== null) {
                throw new RuntimeException(ContentProjectErrorCode::TaskDeleted->value);
            }

            if ($locked->archived_at !== null
                || (string) $locked->status === SeoProjectTaskStatus::Archived->value
            ) {
                Log::debug('seo.project_task.archive_noop', [
                    'task_id' => (int) $locked->id,
                    'error_code' => ContentProjectErrorCode::TaskAlreadyArchived->value,
                ]);

                return $locked;
            }

            $previousStatus = (string) $locked->status;
            $locked->forceFill([
                'status_before_archive' => $previousStatus !== '' ? $previousStatus : null,
                'status' => SeoProjectTaskStatus::Archived->value,
                'archived_at' => now(),
            ])->save();

            $this->eventRecorder->record(
                $locked,
                SeoProjectTaskEventType::TaskArchived,
                $previousStatus !== '' ? $previousStatus : null,
                SeoProjectTaskStatus::Archived->value,
                [
                    'task_id' => (int) $locked->id,
                    'article_id' => $locked->article_id !== null ? (int) $locked->article_id : null,
                    'previous_status' => $previousStatus,
                    'archive_mirror_id' => isset($context['archive_mirror_id'])
                        ? (int) $context['archive_mirror_id']
                        : null,
                    'actor_id' => $userId,
                ],
                isset($context['run_id']) ? (int) $context['run_id'] : null,
                $userId,
            );

            $fresh = $locked->fresh() ?? $locked;
            DB::connection('omi_seo_ai')->afterCommit(static function () use ($fresh): void {
                app(\Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter::class)
                    ->taskArchived($fresh);
            });

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function restore(
        SeoProjectTask $task,
        ?int $userId = null,
        array $context = [],
    ): SeoProjectTask {
        return DB::connection('omi_seo_ai')->transaction(function () use ($task, $userId, $context): SeoProjectTask {
            /** @var SeoProjectTask|null $locked */
            $locked = SeoProjectTask::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectTask) {
                throw new RuntimeException(ContentProjectErrorCode::TaskNotFound->value);
            }

            if ($locked->deleted_at !== null) {
                throw new RuntimeException(ContentProjectErrorCode::TaskDeleted->value);
            }

            if ($locked->archived_at === null
                && (string) $locked->status !== SeoProjectTaskStatus::Archived->value
            ) {
                Log::debug('seo.project_task.restore_noop', [
                    'task_id' => (int) $locked->id,
                    'error_code' => ContentProjectErrorCode::TaskNotArchived->value,
                ]);

                return $locked;
            }

            $previousStatus = (string) $locked->status;
            $statusBefore = $locked->status_before_archive !== null
                ? (string) $locked->status_before_archive
                : null;
            $restoredStatus = $this->resolveStatusAfterRestore($statusBefore);

            $locked->forceFill([
                'status' => $restoredStatus,
                'status_before_archive' => null,
                'archived_at' => null,
            ])->save();

            $this->eventRecorder->record(
                $locked,
                SeoProjectTaskEventType::TaskRestored,
                $previousStatus !== '' ? $previousStatus : SeoProjectTaskStatus::Archived->value,
                $restoredStatus,
                [
                    'task_id' => (int) $locked->id,
                    'article_id' => $locked->article_id !== null ? (int) $locked->article_id : null,
                    'previous_status' => $statusBefore,
                    'restored_status' => $restoredStatus,
                    'archive_mirror_id' => isset($context['archive_mirror_id'])
                        ? (int) $context['archive_mirror_id']
                        : null,
                    'actor_id' => $userId,
                ],
                isset($context['run_id']) ? (int) $context['run_id'] : null,
                $userId,
            );

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * Soft-delete (không phải archive). Sau khi SoftDeletes bật, $task->delete() set deleted_at.
     *
     * @param  array<string, mixed>  $context
     */
    public function softDelete(
        SeoProjectTask $task,
        ?int $userId = null,
        array $context = [],
    ): void {
        DB::connection('omi_seo_ai')->transaction(function () use ($task, $userId, $context): void {
            /** @var SeoProjectTask|null $locked */
            $locked = SeoProjectTask::withTrashed()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectTask) {
                return;
            }

            if ($locked->deleted_at !== null) {
                return;
            }

            $fromStatus = (string) $locked->status;

            $this->eventRecorder->record(
                $locked,
                SeoProjectTaskEventType::TaskDeleted,
                $fromStatus !== '' ? $fromStatus : null,
                $fromStatus !== '' ? $fromStatus : null,
                [
                    'task_id' => (int) $locked->id,
                    'article_id' => $locked->article_id !== null ? (int) $locked->article_id : null,
                    'previous_status' => $fromStatus,
                    'actor_id' => $userId,
                ],
                isset($context['run_id']) ? (int) $context['run_id'] : null,
                $userId,
            );

            $locked->delete();
        });
    }
}
