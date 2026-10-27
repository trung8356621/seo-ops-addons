<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemGenerationClassifier;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sequential multi-project orchestration under one Global Generation Lock.
 */
final class GlobalMonthlyBatchOrchestrator
{
    public function __construct(
        private readonly GlobalGenerationLock $lock,
        private readonly GlobalMonthlyBatchSnapshotBuilder $snapshotBuilder,
        private readonly LegacyActiveGenerationRunScanner $legacyScanner,
        private readonly ContentProjectCommandBus $commandBus,
        private readonly ContentProjectRunEngine $runEngine,
        private readonly ContentProjectItemGenerationClassifier $classifier,
    ) {}

    /**
     * @return array{ok: bool, batch_id: ?int, message: string, metadata: array<string, mixed>}
     */
    public function start(
        string $planningMonth,
        AiCostPolicy $aiPolicy,
        ActorContext $actor,
    ): array {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $legacy = $this->legacyScanner->findActiveRuns();
        if ($legacy !== []) {
            return [
                'ok' => false,
                'batch_id' => null,
                'message' => GlobalGenerationLock::BUSY_MESSAGE.' (legacy Project Run đang chạy).',
                'metadata' => ['legacy_active_runs' => $legacy, 'reason' => 'legacy_active_run'],
            ];
        }

        $lockInspect = $this->lock->inspect();
        $lockState = (string) ($lockInspect['state'] ?? GlobalGenerationLock::STATE_UNAVAILABLE);
        if ($lockState === GlobalGenerationLock::STATE_UNAVAILABLE) {
            return [
                'ok' => false,
                'batch_id' => null,
                'message' => GlobalGenerationLock::UNAVAILABLE_MESSAGE,
                'metadata' => ['reason' => 'lock_unavailable', 'lock_state' => $lockState],
            ];
        }
        if ($lockState === GlobalGenerationLock::STATE_HELD) {
            return [
                'ok' => false,
                'batch_id' => null,
                'message' => GlobalGenerationLock::BUSY_MESSAGE,
                'metadata' => [
                    'lock_holder' => $lockInspect['holder'] ?? null,
                    'reason' => 'lock_busy',
                ],
            ];
        }

        $snapshot = $this->snapshotBuilder->build($month);
        if ($snapshot['eligible_count'] <= 0) {
            return [
                'ok' => false,
                'batch_id' => null,
                'message' => 'Không có bài đủ điều kiện generate trong tháng '.$month.'.',
                'metadata' => ['reason' => 'no_eligible', 'snapshot' => $snapshot],
            ];
        }

        $batch = SeoContentGlobalBatch::query()->create([
            'user_id' => $actor->actorId,
            'planning_month' => ContentProjectMonthContext::toDateString($month),
            'status' => SeoContentGlobalBatch::STATUS_PENDING,
            'total_items' => $snapshot['eligible_count'],
            'succeeded' => 0,
            'failed' => 0,
            'skipped' => 0,
            'pending' => $snapshot['eligible_count'],
            'ai_cost_policy' => $aiPolicy->value,
            'project_order' => $snapshot['project_order'],
            'settings' => [
                'ai_cost_policy' => $aiPolicy->value,
                'ai_generation_mode' => $aiPolicy->generationModeValue(),
            ],
            'started_at' => now(),
        ]);

        $batchId = (int) $batch->getKey();
        $this->persistItems($batchId, $snapshot['items']);

        $acquired = $this->lock->acquire(
            SeoContentGenerationLock::OWNER_GLOBAL_BATCH,
            $batchId,
            [
                'label' => 'Global Batch #'.$batchId.' ('.$month.')',
                'planning_month' => $month,
            ],
        );

        if (! ($acquired['ok'] ?? false)) {
            $batch->update([
                'status' => SeoContentGlobalBatch::STATUS_FAILED,
                'finished_at' => now(),
                'pending' => 0,
            ]);

            return [
                'ok' => false,
                'batch_id' => $batchId,
                'message' => GlobalGenerationLock::BUSY_MESSAGE,
                'metadata' => [
                    'reason' => 'lock_acquire_failed',
                    'holder' => $acquired['holder'] ?? null,
                ],
            ];
        }

        $token = (string) $acquired['token'];
        $batch->update([
            'lock_owner_token' => $token,
            'status' => SeoContentGlobalBatch::STATUS_RUNNING,
        ]);

        try {
            $started = $this->startNextProjectSlice($batch->fresh() ?? $batch, $actor);
            if (! ($started['ok'] ?? false)) {
                $this->failBatchAndRelease($batchId, $token, (string) ($started['message'] ?? 'Failed to start first project.'));

                return [
                    'ok' => false,
                    'batch_id' => $batchId,
                    'message' => (string) ($started['message'] ?? 'Failed to start first project.'),
                    'metadata' => array_merge($started['metadata'] ?? [], ['reason' => 'first_slice_failed']),
                ];
            }

            return [
                'ok' => true,
                'batch_id' => $batchId,
                'message' => 'Global batch started.',
                'metadata' => [
                    'eligible_count' => $snapshot['eligible_count'],
                    'project_order' => $snapshot['project_order'],
                    'current_project_id' => $started['project_id'] ?? null,
                    'current_project_run_id' => $started['run_id'] ?? null,
                ],
            ];
        } catch (Throwable $e) {
            $this->failBatchAndRelease($batchId, $token, $e->getMessage());
            RuntimeLogger::report($e, [
                'endpoint' => 'global_monthly_batch.start',
                'batch_id' => $batchId,
            ]);

            return [
                'ok' => false,
                'batch_id' => $batchId,
                'message' => 'Global batch start failed: '.$e->getMessage(),
                'metadata' => ['reason' => 'exception'],
            ];
        }
    }

    /**
     * Advance after a child Project Run reaches terminal.
     * Idempotent for the same run_id.
     *
     * @return array{ok: bool, message: string, metadata: array<string, mixed>}
     */
    public function advanceAfterChildRun(int $batchId, int $projectRunId, ?ActorContext $actor = null): array
    {
        $decision = DB::connection('omi_seo_ai')->transaction(function () use ($batchId, $projectRunId): array {
            /** @var SeoContentGlobalBatch|null $batch */
            $batch = SeoContentGlobalBatch::query()->whereKey($batchId)->lockForUpdate()->first();
            if (! $batch instanceof SeoContentGlobalBatch) {
                return ['action' => 'missing'];
            }

            if ($batch->isTerminal()) {
                return ['action' => 'already_terminal', 'status' => (string) $batch->status];
            }

            $currentRunId = (int) ($batch->current_project_run_id ?? 0);
            if ($currentRunId !== $projectRunId) {
                return [
                    'action' => 'stale',
                    'current_project_run_id' => $currentRunId,
                    'incoming_run_id' => $projectRunId,
                ];
            }

            $this->syncItemsFromChildRun($batch, $projectRunId);
            $batch->refresh();

            $childRun = SeoProjectRun::query()->find($projectRunId);
            $childSettings = $childRun instanceof SeoProjectRun && is_array($childRun->settings)
                ? $childRun->settings
                : [];
            $childEngine = is_array($childSettings['php_engine'] ?? null) ? $childSettings['php_engine'] : [];
            if (! empty($childEngine['fail_closed'])) {
                $this->markRemainingPendingCancelled($batch);
                $this->recount($batch);
                $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_FAILED);

                return ['action' => 'system_failed', 'reason' => 'child_fail_closed'];
            }

            if ($batch->stopRequested()) {
                $this->markRemainingPendingCancelled($batch);
                $this->recount($batch);
                $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_CANCELLED);

                return ['action' => 'stopped'];
            }

            // Clear current pointer before starting next slice outside the transaction.
            $batch->update([
                'current_project_run_id' => null,
            ]);

            return ['action' => 'continue'];
        });

        return match ($decision['action'] ?? '') {
            'missing' => ['ok' => false, 'message' => 'Batch not found.', 'metadata' => []],
            'already_terminal' => [
                'ok' => true,
                'message' => 'Batch already terminal.',
                'metadata' => ['status' => $decision['status'] ?? null],
            ],
            'stale' => [
                'ok' => true,
                'message' => 'Stale advance ignored.',
                'metadata' => $decision,
            ],
            'stopped' => ['ok' => true, 'message' => 'Batch stopped.', 'metadata' => ['status' => 'cancelled']],
            'system_failed' => [
                'ok' => true,
                'message' => 'Batch stopped after system failure.',
                'metadata' => ['status' => 'failed', 'reason' => $decision['reason'] ?? null],
            ],
            'continue' => $this->continueAfterAdvance($batchId, $actor),
            default => ['ok' => false, 'message' => 'Unknown advance decision.', 'metadata' => $decision],
        };
    }

    /**
     * @return array{ok: bool, message: string, metadata: array<string, mixed>}
     */
    private function continueAfterAdvance(int $batchId, ?ActorContext $actor): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch || $batch->isTerminal()) {
            return ['ok' => true, 'message' => 'Batch terminal during continue.', 'metadata' => []];
        }

        $actorContext = $actor ?? ActorContext::system();
        $started = $this->startNextProjectSlice($batch, $actorContext);
        if (($started['done'] ?? false) === true) {
            $this->recount($batch);
            $this->completeTerminal($batch->fresh() ?? $batch, SeoContentGlobalBatch::STATUS_COMPLETED);

            return ['ok' => true, 'message' => 'Batch completed.', 'metadata' => ['status' => 'completed']];
        }

        if (! ($started['ok'] ?? false)) {
            $this->recount($batch);
            $token = (string) ($batch->lock_owner_token ?? '');
            $this->failBatchAndRelease($batchId, $token, (string) ($started['message'] ?? 'Advance failed.'));

            return [
                'ok' => false,
                'message' => (string) ($started['message'] ?? 'Advance failed.'),
                'metadata' => $started['metadata'] ?? [],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Advanced to next project.',
            'metadata' => [
                'project_id' => $started['project_id'] ?? null,
                'run_id' => $started['run_id'] ?? null,
            ],
        ];
    }

    /**
     * @return array{ok: bool, message: string, metadata: array<string, mixed>}
     */
    public function requestStop(int $batchId, ActorContext $actor, ?string $reason = null): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch) {
            return ['ok' => false, 'message' => 'Batch not found.', 'metadata' => []];
        }

        if ($batch->isTerminal()) {
            return ['ok' => true, 'message' => 'Batch already terminal.', 'metadata' => ['status' => (string) $batch->status]];
        }

        $batch->update([
            'status' => SeoContentGlobalBatch::STATUS_STOPPING,
            'stop_requested_at' => now(),
            'stop_requested_by' => $actor->actorId,
            'settings' => array_merge(is_array($batch->settings) ? $batch->settings : [], [
                'stop_reason' => $reason ?? 'Stopped by user.',
            ]),
        ]);

        $runId = (int) ($batch->current_project_run_id ?? 0);
        if ($runId > 0) {
            $run = SeoProjectRun::query()->find($runId);
            if ($run instanceof SeoProjectRun && (string) $run->status === SeoProjectRun::STATUS_RUNNING) {
                $this->runEngine->requestStop($run, $actor->actorId, $reason ?? 'Global batch stop.');
            }
        }

        // Lock remains until child finishes and advance marks terminal.
        return [
            'ok' => true,
            'message' => 'Stop requested — waiting for current article to finish.',
            'metadata' => [
                'batch_id' => $batchId,
                'current_project_run_id' => $runId > 0 ? $runId : null,
            ],
        ];
    }

    /**
     * Resume: pending continue; succeeded never rerun; skipped stay; failed NOT auto-retried.
     *
     * @return array{ok: bool, message: string, metadata: array<string, mixed>}
     */
    public function resume(int $batchId, ActorContext $actor): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch) {
            return ['ok' => false, 'message' => 'Batch not found.', 'metadata' => []];
        }

        if ($batch->isTerminal() && ! in_array((string) $batch->status, [
            SeoContentGlobalBatch::STATUS_CANCELLED,
            SeoContentGlobalBatch::STATUS_FAILED,
        ], true)) {
            return ['ok' => false, 'message' => 'Batch completed — nothing to resume.', 'metadata' => []];
        }

        $pending = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_PENDING)
            ->count();

        if ($pending <= 0) {
            return [
                'ok' => false,
                'message' => 'No pending items. Failed items require explicit retry selection.',
                'metadata' => ['reason' => 'no_pending'],
            ];
        }

        if ($this->legacyScanner->hasActiveRuns((int) ($batch->current_project_run_id ?? 0) ?: null)) {
            return [
                'ok' => false,
                'message' => GlobalGenerationLock::BUSY_MESSAGE,
                'metadata' => ['reason' => 'legacy_active_run'],
            ];
        }

        $token = (string) ($batch->lock_owner_token ?? '');
        if ($token === '' || ! $this->lock->assertHeldBy(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId)) {
            $acquired = $this->lock->acquire(
                SeoContentGenerationLock::OWNER_GLOBAL_BATCH,
                $batchId,
                ['label' => 'Global Batch #'.$batchId.' (resume)'],
            );
            if (! ($acquired['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => GlobalGenerationLock::BUSY_MESSAGE,
                    'metadata' => ['holder' => $acquired['holder'] ?? null],
                ];
            }
            $token = (string) $acquired['token'];
            $batch->update(['lock_owner_token' => $token]);
        }

        // Clear stop markers; do not flip failed → pending.
        $batch->update([
            'status' => SeoContentGlobalBatch::STATUS_RUNNING,
            'stop_requested_at' => null,
            'finished_at' => null,
        ]);

        $currentRunId = (int) ($batch->current_project_run_id ?? 0);
        if ($currentRunId > 0) {
            $run = SeoProjectRun::query()->find($currentRunId);
            if ($run instanceof SeoProjectRun && $this->runEngine->isRecoverable($run)) {
                $this->runEngine->resume($run);

                return [
                    'ok' => true,
                    'message' => 'Resumed recoverable child run.',
                    'metadata' => ['project_run_id' => $currentRunId],
                ];
            }
        }

        $started = $this->startNextProjectSlice($batch->fresh() ?? $batch, $actor);
        if (! ($started['ok'] ?? false) && ! ($started['done'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($started['message'] ?? 'Resume failed.'),
                'metadata' => $started['metadata'] ?? [],
            ];
        }

        if ($started['done'] ?? false) {
            $this->recount($batch);
            $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_COMPLETED);
        }

        return [
            'ok' => true,
            'message' => 'Batch resumed.',
            'metadata' => $started,
        ];
    }

    /**
     * @param  list<array{project_id: int, task_id: int, item_type: string, position: int}>  $items
     */
    private function persistItems(int $batchId, array $items): void
    {
        $now = now();
        $rows = [];
        foreach ($items as $item) {
            $rows[] = [
                'batch_id' => $batchId,
                'position' => (int) $item['position'],
                'project_id' => (int) $item['project_id'],
                'task_id' => (int) $item['task_id'],
                'item_type' => (string) $item['item_type'],
                'status' => SeoContentGlobalBatchItem::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            SeoContentGlobalBatchItem::query()->insert($chunk);
        }
    }

    /**
     * @return array{ok?: bool, done?: bool, message?: string, project_id?: int, run_id?: int, metadata?: array<string, mixed>}
     */
    private function startNextProjectSlice(SeoContentGlobalBatch $batch, ActorContext $actor): array
    {
        $batchId = (int) $batch->getKey();
        $policy = GlobalMonthlyAiPolicy::normalize($batch->ai_cost_policy);
        $order = is_array($batch->project_order) ? $batch->project_order : [];

        foreach ($order as $rawProjectId) {
            $projectId = (int) $rawProjectId;
            $pendingTaskIds = SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('project_id', $projectId)
                ->where('status', SeoContentGlobalBatchItem::STATUS_PENDING)
                ->orderBy('position')
                ->pluck('task_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($pendingTaskIds === []) {
                continue;
            }

            $project = SeoProject::query()->find($projectId);
            if (! $project instanceof SeoProject) {
                $this->skipTasks($batchId, $pendingTaskIds, 'project_missing');

                continue;
            }

            // Pre-dispatch eligibility re-check.
            $visitIds = [];
            foreach ($pendingTaskIds as $taskId) {
                $task = SeoProjectTask::query()->find($taskId);
                if (! $task instanceof SeoProjectTask) {
                    $this->markItemSkipped($batchId, $taskId, 'task_missing');

                    continue;
                }

                $decision = $this->classifier->classifyTask($task);
                if (! $decision->shouldRun()) {
                    $this->markItemSkipped($batchId, $taskId, $decision->reason);

                    continue;
                }

                $visitIds[] = $taskId;
            }

            if ($visitIds === []) {
                continue;
            }

            // Idempotency: do not start another run if current_project_run still active for this project.
            $currentRunId = (int) ($batch->current_project_run_id ?? 0);
            if ($currentRunId > 0 && (int) ($batch->current_project_id ?? 0) === $projectId) {
                $existing = SeoProjectRun::query()->find($currentRunId);
                if ($existing instanceof SeoProjectRun
                    && in_array((string) $existing->status, [
                        SeoProjectRun::STATUS_RUNNING,
                        SeoProjectRun::STATUS_STOPPING,
                    ], true)
                ) {
                    return [
                        'ok' => true,
                        'project_id' => $projectId,
                        'run_id' => $currentRunId,
                        'message' => 'Slice already running.',
                    ];
                }
            }

            $settings = array_merge(GlobalMonthlyAiPolicy::childRunSettings($policy), [
                'global_batch_id' => $batchId,
                'inherits_global_lock' => true,
                'lazy_bulk' => true,
                'use_php_engine' => true,
            ]);

            GlobalMonthlyAiPolicy::assertUnchanged($policy, $settings[AiCostPolicy::SETTING_KEY] ?? null);

            /** @var ContentProjectActionResult $result */
            $result = $this->commandBus->dispatch(
                new GenerateProjectItemsCommand(
                    projectRef: $projectId,
                    itemRefs: $visitIds,
                    mode: SeoProjectRun::MODE_FULL,
                    settings: $settings,
                ),
                $actor,
            );

            if (! $result->success) {
                return [
                    'ok' => false,
                    'message' => $result->message,
                    'metadata' => [
                        'project_id' => $projectId,
                        'code' => $result->code,
                        'result_meta' => $result->metadata,
                    ],
                ];
            }

            $runId = $this->extractRunId($result->metadata);
            if ($runId <= 0) {
                return [
                    'ok' => false,
                    'message' => 'Child generate did not return execution ref.',
                    'metadata' => ['project_id' => $projectId],
                ];
            }

            SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->whereIn('task_id', $visitIds)
                ->where('status', SeoContentGlobalBatchItem::STATUS_PENDING)
                ->update([
                    'status' => SeoContentGlobalBatchItem::STATUS_RUNNING,
                    'project_run_id' => $runId,
                    'started_at' => now(),
                    'updated_at' => now(),
                ]);

            $batch->update([
                'current_project_id' => $projectId,
                'current_project_run_id' => $runId,
            ]);
            $this->recount($batch);

            return [
                'ok' => true,
                'project_id' => $projectId,
                'run_id' => $runId,
                'message' => 'Started project slice.',
            ];
        }

        return ['ok' => true, 'done' => true, 'message' => 'No more pending projects.'];
    }

    private function syncItemsFromChildRun(SeoContentGlobalBatch $batch, int $projectRunId): void
    {
        $batchId = (int) $batch->getKey();
        $items = SeoProjectRunItem::query()
            ->where('run_id', $projectRunId)
            ->articleExecution()
            ->get(['id', 'task_id', 'status', 'attempt', 'error_message', 'finished_at']);

        foreach ($items as $runItem) {
            $taskId = (int) $runItem->task_id;
            $status = SeoProjectRunItemStatus::fromLegacy(
                $runItem->status !== null ? (string) $runItem->status : null
            );

            $mapped = match ($status) {
                SeoProjectRunItemStatus::Success => SeoContentGlobalBatchItem::STATUS_SUCCEEDED,
                SeoProjectRunItemStatus::Failed => SeoContentGlobalBatchItem::STATUS_FAILED,
                SeoProjectRunItemStatus::Skipped => SeoContentGlobalBatchItem::STATUS_SKIPPED,
                SeoProjectRunItemStatus::Cancelled => SeoContentGlobalBatchItem::STATUS_CANCELLED,
                default => null,
            };

            if ($mapped === null) {
                // Still pending/processing — if run terminal, treat remaining as cancelled/skipped.
                $run = SeoProjectRun::query()->find($projectRunId);
                if ($run instanceof SeoProjectRun && in_array((string) $run->status, [
                    SeoProjectRun::STATUS_COMPLETED,
                    SeoProjectRun::STATUS_FAILED,
                    SeoProjectRun::STATUS_CANCELLED,
                ], true)) {
                    $mapped = $batch->stopRequested()
                        ? SeoContentGlobalBatchItem::STATUS_CANCELLED
                        : SeoContentGlobalBatchItem::STATUS_SKIPPED;
                } else {
                    continue;
                }
            }

            SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('task_id', $taskId)
                ->whereIn('status', [
                    SeoContentGlobalBatchItem::STATUS_PENDING,
                    SeoContentGlobalBatchItem::STATUS_RUNNING,
                ])
                ->update([
                    'status' => $mapped,
                    'project_run_id' => $projectRunId,
                    'run_item_id' => (int) $runItem->id,
                    'attempt_count' => max(1, (int) ($runItem->attempt ?? 1)),
                    'error_message' => $runItem->error_message !== null ? (string) $runItem->error_message : null,
                    'finished_at' => $runItem->finished_at ?? now(),
                    'updated_at' => now(),
                ]);
        }

        $this->recount($batch);
    }

    private function markRemainingPendingCancelled(SeoContentGlobalBatch $batch): void
    {
        SeoContentGlobalBatchItem::query()
            ->where('batch_id', (int) $batch->id)
            ->whereIn('status', [
                SeoContentGlobalBatchItem::STATUS_PENDING,
                SeoContentGlobalBatchItem::STATUS_RUNNING,
            ])
            ->update([
                'status' => SeoContentGlobalBatchItem::STATUS_CANCELLED,
                'finished_at' => now(),
                'updated_at' => now(),
                'skip_reason' => 'batch_stop_requested',
            ]);
    }

    /**
     * @param  list<int>  $taskIds
     */
    private function skipTasks(int $batchId, array $taskIds, string $reason): void
    {
        foreach ($taskIds as $taskId) {
            $this->markItemSkipped($batchId, $taskId, $reason);
        }
    }

    private function markItemSkipped(int $batchId, int $taskId, string $reason): void
    {
        SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('task_id', $taskId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_PENDING)
            ->update([
                'status' => SeoContentGlobalBatchItem::STATUS_SKIPPED,
                'skip_reason' => $reason,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function recount(SeoContentGlobalBatch $batch): void
    {
        $batchId = (int) $batch->getKey();
        $counts = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        $batch->update([
            'succeeded' => (int) ($counts[SeoContentGlobalBatchItem::STATUS_SUCCEEDED] ?? 0),
            'failed' => (int) ($counts[SeoContentGlobalBatchItem::STATUS_FAILED] ?? 0),
            'skipped' => (int) ($counts[SeoContentGlobalBatchItem::STATUS_SKIPPED] ?? 0)
                + (int) ($counts[SeoContentGlobalBatchItem::STATUS_CANCELLED] ?? 0),
            'pending' => (int) ($counts[SeoContentGlobalBatchItem::STATUS_PENDING] ?? 0)
                + (int) ($counts[SeoContentGlobalBatchItem::STATUS_RUNNING] ?? 0),
        ]);
    }

    private function completeTerminal(SeoContentGlobalBatch $batch, string $status): void
    {
        $token = (string) ($batch->lock_owner_token ?? '');
        $batch->update([
            'status' => $status,
            'finished_at' => now(),
            'current_project_id' => null,
            'current_project_run_id' => null,
        ]);

        if ($token !== '') {
            $this->lock->releaseIfOwner(
                SeoContentGenerationLock::OWNER_GLOBAL_BATCH,
                (int) $batch->id,
                $token,
            );
        }
    }

    private function failBatchAndRelease(int $batchId, string $token, string $message): void
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if ($batch instanceof SeoContentGlobalBatch) {
            $settings = is_array($batch->settings) ? $batch->settings : [];
            $settings['failure_message'] = $message;
            $batch->update([
                'status' => SeoContentGlobalBatch::STATUS_FAILED,
                'finished_at' => now(),
                'settings' => $settings,
            ]);
        }

        if ($token !== '') {
            $this->lock->release($token);
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function extractRunId(array $metadata): int
    {
        $ref = ContentProjectPublicRef::resolvePrimaryExecutionRef($metadata);
        if ($ref === null) {
            return 0;
        }

        try {
            return ContentProjectPublicRef::decodeExecution($ref);
        } catch (Throwable) {
            if (ctype_digit($ref)) {
                return (int) $ref;
            }

            return 0;
        }
    }
}
