<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionStatus;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;

/**
 * Recover orphaned Writing items + dead pending/processing run-items.
 * Writing orphans → Failed. Pending + dead runtime → finalize run-items only (keep Pending).
 * Never force-releases locks owned by a fresh execution.
 */
final class ContentProjectGenerationRecoveryService
{
    public const RECOVERY_MESSAGE = 'Interrupted: stale generation runtime (no heartbeat / no active worker).';

    public function __construct(
        private readonly ContentProjectExecutionStalenessPolicy $staleness,
        private readonly ContentProjectExecutionFinalizer $finalizer,
    ) {}

    /**
     * @return array{recovered_task_ids: list<int>, skipped_task_ids: list<int>, details: list<array<string, mixed>>}
     */
    public function reconcileProject(SeoProject $project): array
    {
        $projectId = (int) $project->getKey();
        $writingIds = SeoProjectTask::query()
            ->where('project_id', $projectId)
            ->where('status', SeoProjectTask::STATUS_WRITING)
            ->whereNull('archived_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $activeRuntimeTaskIds = SeoProjectRunItem::query()
            ->whereIn(
                'task_id',
                SeoProjectTask::query()
                    ->where('project_id', $projectId)
                    ->whereNull('archived_at')
                    ->select('id'),
            )
            ->whereIn('status', ContentProjectExecutionStatus::activeStatuses())
            ->whereNull('finished_at')
            ->pluck('task_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $taskIds = array_values(array_unique(array_merge($writingIds, $activeRuntimeTaskIds)));
        $tasks = $taskIds === []
            ? collect()
            : SeoProjectTask::query()
                ->whereIn('id', $taskIds)
                ->with(['article'])
                ->orderBy('id')
                ->get();

        $recovered = [];
        $skipped = [];
        $details = [];

        foreach ($tasks as $task) {
            if (! $task instanceof SeoProjectTask) {
                continue;
            }
            $result = $this->recoverTaskIfStale($task);
            if (($result['recovered'] ?? false) === true) {
                $recovered[] = (int) $task->id;
            } else {
                $skipped[] = (int) $task->id;
            }
            $details[] = $result;
        }

        if ($recovered !== []) {
            try {
                $batchId = 'recovery-'.now()->format('YmdHi').'-'.$projectId;
                app(\Omnichannel\Addons\Seo\Services\Notifications\Publishers\GenerationStuckNotificationPublisher::class)
                    ->notifyRecoveryBatch(
                        $project,
                        $batchId,
                        $recovered,
                        [],
                        exhausted: false,
                    );
            } catch (\Throwable $notificationError) {
                RuntimeLogger::warning('seo.operational_notification.generation_stuck_hook_failed', [
                    'project_id' => $projectId,
                    'error' => $notificationError->getMessage(),
                ]);
            }
        }

        return [
            'recovered_task_ids' => $recovered,
            'skipped_task_ids' => $skipped,
            'details' => $details,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function recoverTaskIfStale(SeoProjectTask $task): array
    {
        $evaluation = $this->staleness->evaluateTask($task);
        if (! ($evaluation['stale'] ?? false)) {
            return [
                'task_id' => (int) $task->id,
                'recovered' => false,
                'reason' => 'not_stale',
                'evaluation' => $evaluation,
            ];
        }

        return $this->recoverStaleTask($task, $evaluation);
    }

    /**
     * @param  array<string, mixed>  $evaluation
     * @return array<string, mixed>
     */
    public function recoverStaleTask(SeoProjectTask $task, array $evaluation = []): array
    {
        if ($evaluation === []) {
            $evaluation = $this->staleness->evaluateTask($task);
        }
        if (! ($evaluation['stale'] ?? false)) {
            return [
                'task_id' => (int) $task->id,
                'recovered' => false,
                'reason' => 'not_stale',
                'evaluation' => $evaluation,
            ];
        }

        $taskId = (int) $task->getKey();
        $reason = self::RECOVERY_MESSAGE;

        $outcome = DB::connection('omi_seo_ai')->transaction(function () use ($task, $taskId, $evaluation, $reason): array {
            /** @var SeoProjectTask|null $locked */
            $locked = SeoProjectTask::query()->whereKey($taskId)->lockForUpdate()->first();
            if (! $locked instanceof SeoProjectTask) {
                return ['recovered' => false, 'reason' => 'task_missing'];
            }

            // Re-check under lock — do not interrupt a freshly claimed worker.
            $freshEval = $this->staleness->evaluateTask($locked->loadMissing('article'));
            if (! ($freshEval['stale'] ?? false)) {
                return ['recovered' => false, 'reason' => 'became_active', 'evaluation' => $freshEval];
            }

            $finalizedIds = [];
            $staleItemIds = array_map('intval', $freshEval['stale_run_item_ids'] ?? []);
            $activeIds = array_map('intval', $freshEval['active_run_item_ids'] ?? []);
            $targetIds = $staleItemIds !== [] ? $staleItemIds : $activeIds;

            foreach ($targetIds as $itemId) {
                $item = SeoProjectRunItem::query()->find($itemId);
                if (! $item instanceof SeoProjectRunItem) {
                    continue;
                }
                if (! ContentProjectExecutionStatus::isActive((string) $item->status) || $item->finished_at !== null) {
                    continue;
                }
                $this->finalizer->finalize(
                    $item,
                    SeoProjectRunItemStatus::Failed->value,
                    $reason,
                    [
                        'error_code' => ContentProjectExecutionStalenessPolicy::REASON_STALE_RUNTIME,
                    ],
                    syncMirror: true,
                );
                $finalizedIds[] = $itemId;
                $this->releaseStaleDispatchIfOwnedBy($item);
            }

            $wasWriting = (string) $locked->status === SeoProjectTask::STATUS_WRITING;
            // Only mutate lifecycle generation status when Writing was orphaned.
            // Pending + dead run-item: clear runtime artifacts only — keep Pending for Generate.
            if ($wasWriting) {
                SeoProjectTask::query()->whereKey($taskId)->update([
                    'status' => SeoProjectTask::STATUS_FAILED,
                ]);
                $locked->refresh();
            }

            return [
                'recovered' => true,
                'reason' => ContentProjectExecutionStalenessPolicy::REASON_STALE_RUNTIME,
                'finalized_run_item_ids' => $finalizedIds,
                'task_status' => (string) $locked->status,
                'preserved_task_status' => ! $wasWriting,
                'evaluation' => $freshEval,
            ];
        });

        if (($outcome['recovered'] ?? false) === true) {
            RuntimeLogger::info('content_project.generation_stale_recovered', [
                'task_id' => $taskId,
                'project_id' => (int) ($task->project_id ?? 0),
                'finalized_run_item_ids' => $outcome['finalized_run_item_ids'] ?? [],
                'preserved_task_status' => (bool) ($outcome['preserved_task_status'] ?? false),
                'timeout_minutes' => $evaluation['timeout_minutes'] ?? $this->staleness->staleTimeoutMinutes(),
            ]);
        }

        return array_merge(['task_id' => $taskId], $outcome);
    }

    /**
     * Clear active_dispatch only when it points at this finalized stale item (token ownership).
     */
    private function releaseStaleDispatchIfOwnedBy(SeoProjectRunItem $item): void
    {
        $run = SeoProjectRun::query()->find((int) $item->run_id);
        if (! $run instanceof SeoProjectRun) {
            return;
        }

        $settings = is_array($run->settings ?? null) ? $run->settings : [];
        $engine = is_array($settings['engine'] ?? null) ? $settings['engine'] : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active === null) {
            return;
        }

        $ownedItemId = (int) ($active['run_item_id'] ?? 0);
        if ($ownedItemId !== (int) $item->id) {
            // Lock belongs to another execution — do not force-release.
            return;
        }

        $token = trim((string) ($active['token'] ?? ''));
        unset($engine['active_dispatch']);
        $settings['engine'] = $engine;

        SeoProjectRun::query()->whereKey((int) $run->id)->update([
            'settings' => $settings,
        ]);

        RuntimeLogger::info('content_project.stale_dispatch_released', [
            'run_id' => (int) $run->id,
            'run_item_id' => (int) $item->id,
            'token_present' => $token !== '',
        ]);
    }
}
