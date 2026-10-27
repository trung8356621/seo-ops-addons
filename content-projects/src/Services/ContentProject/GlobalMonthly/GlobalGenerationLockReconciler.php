<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGenerationLock;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatch;
use Omnichannel\Addons\ContentProjects\Models\SeoContentGlobalBatchItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Idempotent reconciliation of execution state and Global Generation Lock.
 *
 * Rules:
 * - A: Terminal owner without active execution -> finalize missing state, release owner_token.
 * - B: Terminal child run without Global Batch advance -> replay/repair advance safely, no duplicate dispatch.
 * - C: Active worker/job -> retain lock, do not touch.
 * - D: WORKER_LOST -> reuse existing recovery policy, heartbeat timeout alone does not release.
 * - E: Unknown/unresolvable owner state -> fail-closed, report recovery_required with owner details.
 */
final class GlobalGenerationLockReconciler
{
    public function __construct(
        private readonly GlobalGenerationLock $lock,
        private readonly ContentProjectRunEngine $runEngine,
        private readonly GlobalMonthlyBatchOrchestrator $orchestrator,
    ) {}

    /**
     * @return array{
     *     reconciled: bool,
     *     action: string,
     *     reason: string,
     *     details: array<string, mixed>
     * }
     */
    public function reconcile(): array
    {
        $inspect = $this->lock->inspect();
        $state = (string) ($inspect['state'] ?? GlobalGenerationLock::STATE_UNAVAILABLE);

        if ($state === GlobalGenerationLock::STATE_UNAVAILABLE) {
            return [
                'reconciled' => false,
                'action' => 'fail_closed',
                'reason' => 'lock_table_or_query_unavailable',
                'details' => $inspect,
            ];
        }

        if ($state !== GlobalGenerationLock::STATE_HELD) {
            return [
                'reconciled' => false,
                'action' => 'none',
                'reason' => 'lock_available',
                'details' => [],
            ];
        }

        $holder = is_array($inspect['holder'] ?? null) ? $inspect['holder'] : null;
        if ($holder === null) {
            return [
                'reconciled' => false,
                'action' => 'fail_closed',
                'reason' => 'held_state_without_holder_details',
                'details' => $inspect,
            ];
        }

        $ownerType = (string) ($holder['owner_type'] ?? '');
        $ownerId = (int) ($holder['owner_id'] ?? 0);
        $ownerToken = (string) ($holder['owner_token'] ?? '');

        if ($ownerType === '' || $ownerId <= 0 || $ownerToken === '') {
            // Malformed lock row without valid owner: safe orphan release.
            $released = $this->lock->release($ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'released_orphan_lock' : 'release_failed',
                'reason' => 'invalid_owner_identifiers',
                'details' => $holder,
            ];
        }

        return match ($ownerType) {
            SeoContentGenerationLock::OWNER_GLOBAL_BATCH => $this->reconcileGlobalBatchLock($ownerId, $ownerToken, $holder),
            SeoContentGenerationLock::OWNER_PROJECT_RUN => $this->reconcileProjectRunLock($ownerId, $ownerToken, $holder),
            default => $this->reconcileUnknownOwnerLock($ownerType, $ownerId, $ownerToken, $holder),
        };
    }

    /**
     * @param  array<string, mixed>  $holder
     * @return array{reconciled: bool, action: string, reason: string, details: array<string, mixed>}
     */
    private function reconcileGlobalBatchLock(int $batchId, string $ownerToken, array $holder): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch) {
            if ($this->hasActiveQueueJobsForBatch($batchId)) {
                return [
                    'reconciled' => false,
                    'action' => 'retain_lock',
                    'reason' => 'missing_batch_row_but_queue_job_exists',
                    'details' => $holder,
                ];
            }

            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId, $ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'released_orphan_lock' : 'release_failed',
                'reason' => 'batch_record_missing',
                'details' => $holder,
            ];
        }

        // Case A.1: Batch is already terminal.
        if ($batch->isTerminal()) {
            $currentRunId = (int) ($batch->current_project_run_id ?? 0);
            if ($currentRunId > 0) {
                $childRun = SeoProjectRun::query()->find($currentRunId);
                if ($childRun instanceof SeoProjectRun && ! $childRun->isTerminal()) {
                    if ($this->isRunWorkerActive($childRun)) {
                        return [
                            'reconciled' => false,
                            'action' => 'retain_lock',
                            'reason' => 'terminal_batch_has_active_child_run_worker',
                            'details' => array_merge($holder, ['child_run_id' => $currentRunId]),
                        ];
                    }
                }
            }

            if ($this->hasActiveQueueJobsForBatch($batchId)) {
                return [
                    'reconciled' => false,
                    'action' => 'retain_lock',
                    'reason' => 'batch_terminal_but_queue_jobs_remain',
                    'details' => $holder,
                ];
            }

            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId, $ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'released_terminal_owner_lock' : 'release_failed',
                'reason' => 'batch_already_terminal',
                'details' => array_merge($holder, ['batch_status' => (string) $batch->status]),
            ];
        }

        // Case B: Batch is running or stopping. Check child run.
        $currentRunId = (int) ($batch->current_project_run_id ?? 0);
        if ($currentRunId > 0) {
            $childRun = SeoProjectRun::query()->find($currentRunId);

            if (! $childRun instanceof SeoProjectRun) {
                // Child run record disappeared: replay advance to repair.
                $adv = $this->orchestrator->advanceAfterChildRun($batchId, $currentRunId, ActorContext::system());

                return [
                    'reconciled' => true,
                    'action' => 'replayed_advance_missing_child',
                    'reason' => 'child_run_record_missing',
                    'details' => $adv,
                ];
            }

            if ($childRun->isTerminal()) {
                // Child run is terminal: check if it is in recoverable WORKER_LOST state.
                if (ContentProjectRunRecoverableState::isRecoverableRun($childRun)) {
                    return [
                        'reconciled' => false,
                        'action' => 'retain_lock',
                        'reason' => 'child_run_in_recoverable_state',
                        'details' => array_merge($holder, [
                            'child_run_id' => $currentRunId,
                            'child_run_status' => (string) $childRun->status,
                            'recoverable' => true,
                        ]),
                    ];
                }

                // Normal terminal child run: replay advance safely.
                $adv = $this->orchestrator->advanceAfterChildRun($batchId, $currentRunId, ActorContext::system());

                return [
                    'reconciled' => true,
                    'action' => 'replayed_advance_after_child_terminal',
                    'reason' => 'child_run_terminal_without_advance',
                    'details' => $adv,
                ];
            }

            // Child run is non-terminal (running or stopping).
            // Check confirmed dead worker leases via existing engine policy.
            $deadRecovery = $this->runEngine->recoverDeadDispatchIfConfirmed($childRun);
            if ($deadRecovery === 'cancelled') {
                // STOPPING finalized as cancelled -> replay advance.
                $adv = $this->orchestrator->advanceAfterChildRun($batchId, $currentRunId, ActorContext::system());

                return [
                    'reconciled' => true,
                    'action' => 'replayed_advance_after_stopping_finalized',
                    'reason' => 'child_run_cancelled_by_watchdog',
                    'details' => $adv,
                ];
            }

            if ($deadRecovery === 'worker_lost') {
                // Declared WORKER_LOST: retain lock per existing recovery contract.
                return [
                    'reconciled' => false,
                    'action' => 'retain_lock',
                    'reason' => 'child_run_declared_worker_lost',
                    'details' => array_merge($holder, [
                        'child_run_id' => $currentRunId,
                        'worker_lost' => true,
                    ]),
                ];
            }

            // Check if all child run items are finished despite non-terminal status.
            if ($this->areAllRunItemsFinished($childRun)) {
                $this->finalizeTerminalRunQuietly($childRun);
                $adv = $this->orchestrator->advanceAfterChildRun($batchId, $currentRunId, ActorContext::system());

                return [
                    'reconciled' => true,
                    'action' => 'finalized_completed_child_and_advanced',
                    'reason' => 'child_items_finished_without_finalization',
                    'details' => $adv,
                ];
            }

            // Child run is genuinely active. Retain lock.
            return [
                'reconciled' => false,
                'action' => 'retain_lock',
                'reason' => 'child_run_worker_active',
                'details' => array_merge($holder, ['child_run_id' => $currentRunId]),
            ];
        }

        // Case C: Batch has no current child run pointed.
        $pendingItems = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_PENDING)
            ->count();

        $waitingRetry = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
            ->count();

        $waitingDue = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
            ->where(function ($q): void {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->count();

        $settings = is_array($batch->settings) ? $batch->settings : [];
        $autoRerunDisabled = ! empty($settings['auto_rerun_disabled']);

        if ($batch->stopRequested() || $autoRerunDisabled) {
            // Emergency Stop settled — cancel waiting_retry and release exclusive lock.
            $this->orchestrator->wakeForAutoRetry($batchId, ActorContext::system());
            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId, $ownerToken);

            return [
                'reconciled' => true,
                'action' => $released ? 'finalized_stopped_batch_and_released_lock' : 'stop_finalized_lock_already_clear',
                'reason' => 'stop_requested_without_active_child',
                'details' => array_merge($holder, [
                    'auto_rerun_disabled' => $autoRerunDisabled,
                    'waiting_retry' => $waitingRetry,
                ]),
            ];
        }

        // Auto Cost cooldown: retain exclusive Global Lock — never treat waiting_retry as empty.
        if ($waitingRetry > 0 && $pendingItems === 0) {
            $this->lock->heartbeat($ownerToken);

            if ($waitingDue > 0) {
                if ($this->hasActiveWakeOrAdvanceJobsForBatch($batchId)) {
                    return [
                        'reconciled' => false,
                        'action' => 'retain_lock',
                        'reason' => 'waiting_retry_due_wake_already_queued',
                        'details' => array_merge($holder, ['waiting_due' => $waitingDue]),
                    ];
                }

                $woke = $this->orchestrator->wakeForAutoRetry($batchId, ActorContext::system());

                return [
                    'reconciled' => (bool) ($woke['ok'] ?? false),
                    'action' => 'redispatched_due_waiting_retry_wake',
                    'reason' => 'waiting_retry_due_without_wake_job',
                    'details' => array_merge($holder, $woke),
                ];
            }

            // Future next_retry_at — retain lock + ensure delayed wake survives queue drops.
            $ensured = $this->orchestrator->ensureAutoRetryWakeScheduled($batchId);

            return [
                'reconciled' => false,
                'action' => 'retain_lock',
                'reason' => 'waiting_retry_cooldown_hold',
                'details' => array_merge($holder, [
                    'waiting_retry' => $waitingRetry,
                    'wake' => $ensured,
                ]),
            ];
        }

        if ($pendingItems === 0 && $waitingRetry === 0) {
            // Truly idle — finalize and release.
            $this->orchestrator->advanceAfterChildRun($batchId, 0, ActorContext::system());
            $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId, $ownerToken);

            return [
                'reconciled' => true,
                'action' => 'finalized_empty_pending_batch_and_released_lock',
                'reason' => 'no_pending_items_left_in_batch',
                'details' => $holder,
            ];
        }

        // Batch running with pending items but no slice running — continue to next slice.
        // Prefer wake path for Auto Cost so concurrency guards apply.
        if (GlobalMonthlyAiPolicy::isAutoCost($batch->ai_cost_policy)) {
            if ($this->hasActiveWakeOrAdvanceJobsForBatch($batchId)) {
                $this->lock->heartbeat($ownerToken);

                return [
                    'reconciled' => false,
                    'action' => 'retain_lock',
                    'reason' => 'pending_items_wake_or_advance_already_queued',
                    'details' => $holder,
                ];
            }

            $woke = $this->orchestrator->wakeForAutoRetry($batchId, ActorContext::system());

            return [
                'reconciled' => (bool) ($woke['ok'] ?? false),
                'action' => 'resumed_stalled_auto_cost_slice',
                'reason' => 'batch_running_without_active_slice',
                'details' => $woke,
            ];
        }

        $resumed = $this->orchestrator->resume($batchId, ActorContext::system());

        return [
            'reconciled' => (bool) ($resumed['ok'] ?? false),
            'action' => 'resumed_stalled_batch_slice',
            'reason' => 'batch_running_without_active_slice',
            'details' => $resumed,
        ];
    }

    /**
     * @param  array<string, mixed>  $holder
     * @return array{reconciled: bool, action: string, reason: string, details: array<string, mixed>}
     */
    private function reconcileProjectRunLock(int $runId, string $ownerToken, array $holder): array
    {
        /** @var SeoProjectRun|null $run */
        $run = SeoProjectRun::query()->find($runId);
        if (! $run instanceof SeoProjectRun) {
            if ($this->hasActiveQueueJobsForRun($runId)) {
                return [
                    'reconciled' => false,
                    'action' => 'retain_lock',
                    'reason' => 'missing_run_row_but_queue_jobs_exist',
                    'details' => $holder,
                ];
            }

            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_PROJECT_RUN, $runId, $ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'released_orphan_lock' : 'release_failed',
                'reason' => 'run_record_missing',
                'details' => $holder,
            ];
        }

        // Case A.2: Run is already terminal.
        if ($run->isTerminal()) {
            if ($this->isRunWorkerActive($run) || $this->hasActiveQueueJobsForRun($runId)) {
                return [
                    'reconciled' => false,
                    'action' => 'retain_lock',
                    'reason' => 'run_terminal_but_active_execution_remains',
                    'details' => $holder,
                ];
            }

            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_PROJECT_RUN, $runId, $ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'released_terminal_owner_lock' : 'release_failed',
                'reason' => 'run_already_terminal',
                'details' => array_merge($holder, ['run_status' => (string) $run->status]),
            ];
        }

        // Case B.2: Run is non-terminal (running or stopping).
        $deadRecovery = $this->runEngine->recoverDeadDispatchIfConfirmed($run);
        if ($deadRecovery === 'cancelled') {
            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_PROJECT_RUN, $runId, $ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'released_cancelled_dead_worker_lock' : 'release_failed',
                'reason' => 'stopping_run_finalized_as_cancelled',
                'details' => $holder,
            ];
        }

        if ($deadRecovery === 'worker_lost') {
            $run->refresh();
            if ($this->isRunWorkerActive($run) || $this->hasActiveQueueJobsForRun($runId)) {
                return [
                    'reconciled' => false,
                    'action' => 'retain_lock',
                    'reason' => 'run_declared_worker_lost_active_execution_remains',
                    'details' => array_merge($holder, [
                        'run_id' => $runId,
                        'worker_lost' => true,
                    ]),
                ];
            }

            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_PROJECT_RUN, $runId, $ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'released_worker_lost_terminal_owner_lock' : 'release_failed',
                'reason' => 'run_declared_worker_lost',
                'details' => array_merge($holder, [
                    'run_id' => $runId,
                    'worker_lost' => true,
                ]),
            ];
        }

        // Check if all items are finished.
        if ($this->areAllRunItemsFinished($run)) {
            $this->finalizeTerminalRunQuietly($run);
            $released = $this->lock->releaseIfOwner(SeoContentGenerationLock::OWNER_PROJECT_RUN, $runId, $ownerToken);

            return [
                'reconciled' => $released,
                'action' => $released ? 'finalized_completed_run_and_released_lock' : 'release_failed',
                'reason' => 'all_run_items_finished',
                'details' => $holder,
            ];
        }

        // Active execution underway. Retain lock.
        return [
            'reconciled' => false,
            'action' => 'retain_lock',
            'reason' => 'run_worker_active',
            'details' => $holder,
        ];
    }

    /**
     * @param  array<string, mixed>  $holder
     * @return array{reconciled: bool, action: string, reason: string, details: array<string, mixed>}
     */
    private function reconcileUnknownOwnerLock(string $ownerType, int $ownerId, string $ownerToken, array $holder): array
    {
        // Fail-closed if unknown type has ambiguous state; release if token invalid.
        $released = $this->lock->release($ownerToken);

        return [
            'reconciled' => $released,
            'action' => $released ? 'released_unknown_owner_lock' : 'fail_closed',
            'reason' => 'unknown_owner_type:'.$ownerType,
            'details' => $holder,
        ];
    }

    private function areAllRunItemsFinished(SeoProjectRun $run): bool
    {
        $total = (int) ($run->total ?? 0);
        if ($total <= 0) {
            return false;
        }

        $activeItems = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->whereIn('status', [
                SeoProjectRunItemStatus::Processing->value,
                SeoProjectRunItemStatus::Pending->value,
            ])
            ->count();

        return $activeItems === 0;
    }

    private function finalizeTerminalRunQuietly(SeoProjectRun $run): void
    {
        $status = (string) $run->status === SeoProjectRun::STATUS_STOPPING
            ? SeoProjectRun::STATUS_CANCELLED
            : SeoProjectRun::STATUS_COMPLETED;

        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
        ])->saveQuietly();
    }

    private function isRunWorkerActive(SeoProjectRun $run): bool
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[ContentProjectRunEngine::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;

        if ($active === null) {
            return false;
        }

        $heartbeatAge = null;
        if (! empty($active['last_heartbeat_at'])) {
            try {
                $heartbeatAge = now()->diffInSeconds(now()->parse((string) $active['last_heartbeat_at']));
            } catch (Throwable) {
                $heartbeatAge = null;
            }
        }

        $staleThreshold = ContentProjectRunEngineFeature::heartbeatStaleMinutes() * 60;

        return $heartbeatAge !== null && $heartbeatAge < $staleThreshold;
    }

    private function hasActiveQueueJobsForBatch(int $batchId): bool
    {
        return $this->queryQueueJobs("AdvanceGlobalMonthlyBatchJob.*\"batchId\":{$batchId}");
    }

    private function hasActiveWakeOrAdvanceJobsForBatch(int $batchId): bool
    {
        return $this->hasActiveQueueJobsForBatch($batchId)
            || $this->queryQueueJobs("WakeGlobalMonthlyBatchJob.*\"batchId\":{$batchId}")
            || $this->queryQueueJobs("WakeGlobalMonthlyBatchJob.*batchId\";i:{$batchId}");
    }

    private function hasActiveQueueJobsForRun(int $runId): bool
    {
        return $this->queryQueueJobs("\"run_id\":{$runId}")
            || $this->queryQueueJobs("\"projectRunId\":{$runId}");
    }

    private function queryQueueJobs(string $pattern): bool
    {
        try {
            $connection = config('queue.default', 'database');
            if ($connection !== 'database') {
                return false;
            }

            $queue = ContentProjectRunEngineFeature::queueName();

            return DB::table('jobs')
                ->where('queue', $queue)
                ->where('payload', 'like', '%'.$pattern.'%')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
