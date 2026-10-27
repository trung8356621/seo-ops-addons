<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\GlobalMonthly;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\ContentProjects\Jobs\WakeGlobalMonthlyBatchJob;
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
use Carbon\Carbon;
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
        private readonly GlobalAutoGenerationPolicy $autoCostPolicy = new GlobalAutoGenerationPolicy,
    ) {}

    /**
     * @param  string|AiCostPolicy  $aiPolicy  Batch policy token. New starts should pass auto_cost.
     * @return array{ok: bool, batch_id: ?int, message: string, metadata: array<string, mixed>}
     */
    public function start(
        string $planningMonth,
        string|AiCostPolicy $aiPolicy,
        ActorContext $actor,
    ): array {
        $month = ContentProjectMonthContext::normalize($planningMonth);
        $policyToken = $aiPolicy instanceof AiCostPolicy
            ? $aiPolicy->value
            : GlobalMonthlyAiPolicy::normalizeBatchPolicy($aiPolicy);
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

        $isAutoCost = GlobalMonthlyAiPolicy::isAutoCost($policyToken);
        $settings = [
            'ai_cost_policy' => $policyToken,
            'auto_cost' => $isAutoCost,
        ];
        if ($isAutoCost) {
            $settings['ai_generation_mode'] = GlobalMonthlyAiPolicy::AUTO_COST;
            $settings['free_exhausted'] = false;
            $settings['paid_exhausted'] = false;
            $settings['free_circuit_open_until'] = null;
            $settings['consecutive_free_rate_limits'] = 0;
            $settings['retry_round'] = 0;
            $settings['free_succeeded'] = 0;
            $settings['paid_fallback_succeeded'] = 0;
            $settings['failed_attempt_count'] = 0;
        } else {
            $legacyPolicy = AiCostPolicy::tryFromMixed($policyToken);
            $settings['ai_generation_mode'] = $legacyPolicy->generationModeValue();
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
            'ai_cost_policy' => $policyToken,
            'project_order' => $snapshot['project_order'],
            'settings' => $settings,
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

            $isAutoCost = GlobalMonthlyAiPolicy::isAutoCost($batch->ai_cost_policy);
            if ($isAutoCost) {
                $autoDecision = $this->syncAutoCostItemFromChildRun($batch, $projectRunId);
            } else {
                $this->syncItemsFromChildRun($batch, $projectRunId);
                $autoDecision = ['action' => 'continue'];
            }
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
                $this->markWaitingRetryCancelled($batch);
                $this->recount($batch);
                $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_CANCELLED);

                return ['action' => 'stopped'];
            }

            if ($isAutoCost && ($autoDecision['action'] ?? '') === GlobalAutoGenerationPolicy::ACTION_STOP_AI_UNAVAILABLE) {
                $this->recount($batch);
                $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE);

                return ['action' => 'stopped_ai_unavailable', 'reason' => $autoDecision['reason'] ?? null];
            }

            if ($isAutoCost && ($autoDecision['action'] ?? '') === GlobalAutoGenerationPolicy::ACTION_STOP_SYSTEM) {
                $this->recount($batch);
                $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_FAILED);

                return ['action' => 'system_failed', 'reason' => $autoDecision['reason'] ?? 'persist_unsafe'];
            }

            // Clear current pointer before starting next slice outside the transaction.
            $batch->update([
                'current_project_run_id' => null,
                'current_batch_item_id' => null,
            ]);

            $waitUntil = $autoDecision['next_retry_at'] ?? null;
            if ($isAutoCost && ($autoDecision['action'] ?? '') === GlobalAutoGenerationPolicy::ACTION_WAITING_RETRY) {
                return [
                    'action' => 'continue_or_wait',
                    'next_retry_at' => $waitUntil,
                ];
            }

            if ($isAutoCost && ($autoDecision['action'] ?? '') === GlobalAutoGenerationPolicy::ACTION_RUN_PAID) {
                return [
                    'action' => 'continue_same_item_paid',
                    'batch_item_id' => $autoDecision['batch_item_id'] ?? null,
                ];
            }

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
            'stopped_ai_unavailable' => [
                'ok' => true,
                'message' => 'Batch stopped — AI unavailable (Free + Paid exhausted).',
                'metadata' => [
                    'status' => SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE,
                    'reason' => $decision['reason'] ?? null,
                ],
            ],
            'system_failed' => [
                'ok' => true,
                'message' => 'Batch stopped after system failure.',
                'metadata' => ['status' => 'failed', 'reason' => $decision['reason'] ?? null],
            ],
            'continue_same_item_paid' => $this->continueAutoCostPaidAttempt(
                $batchId,
                (int) ($decision['batch_item_id'] ?? 0),
                $actor,
            ),
            'continue_or_wait' => $this->continueAfterAdvance($batchId, $actor, $decision['next_retry_at'] ?? null),
            'continue' => $this->continueAfterAdvance($batchId, $actor),
            default => ['ok' => false, 'message' => 'Unknown advance decision.', 'metadata' => $decision],
        };
    }

    /**
     * @return array{ok: bool, message: string, metadata: array<string, mixed>}
     */
    private function continueAfterAdvance(int $batchId, ?ActorContext $actor, mixed $hintNextRetryAt = null): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch || $batch->isTerminal()) {
            return ['ok' => true, 'message' => 'Batch terminal during continue.', 'metadata' => []];
        }

        $actorContext = $actor ?? ActorContext::system();
        $started = $this->startNextProjectSlice($batch, $actorContext);
        if (($started['waiting'] ?? false) === true) {
            $next = $started['next_retry_at'] ?? $hintNextRetryAt;
            $this->scheduleWake($batchId, $next);

            return [
                'ok' => true,
                'message' => 'Waiting for Auto Rerun cooldown.',
                'metadata' => [
                    'status' => 'waiting_retry',
                    'next_retry_at' => $next,
                ],
            ];
        }

        if (($started['done'] ?? false) === true) {
            $this->recount($batch);
            $this->completeTerminal($batch->fresh() ?? $batch, SeoContentGlobalBatch::STATUS_COMPLETED);

            return ['ok' => true, 'message' => 'Batch completed.', 'metadata' => ['status' => 'completed']];
        }

        if (($started['stopped_ai_unavailable'] ?? false) === true) {
            $this->recount($batch);
            $this->completeTerminal($batch->fresh() ?? $batch, SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE);

            return [
                'ok' => true,
                'message' => 'Batch stopped — AI unavailable.',
                'metadata' => ['status' => SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE],
            ];
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
                'batch_item_id' => $started['batch_item_id'] ?? null,
                'strategy' => $started['strategy'] ?? null,
            ],
        ];
    }

    /**
     * Queue wake when next_retry_at is due. Holds Global Lock; does not busy-loop.
     */
    public function wakeForAutoRetry(int $batchId, ActorContext $actor): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch) {
            return ['ok' => false, 'message' => 'Batch not found.', 'metadata' => []];
        }

        if ($batch->isTerminal()) {
            return ['ok' => true, 'message' => 'Batch already terminal.', 'metadata' => ['status' => (string) $batch->status]];
        }

        $settings = is_array($batch->settings) ? $batch->settings : [];
        if ($batch->stopRequested() || ! empty($settings['auto_rerun_disabled'])) {
            $this->markRemainingPendingCancelled($batch);
            $this->markWaitingRetryCancelled($batch);
            $this->recount($batch);
            $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_CANCELLED);

            return ['ok' => true, 'message' => 'Stop honored on wake — no auto rerun.', 'metadata' => ['status' => 'cancelled']];
        }

        if (! GlobalMonthlyAiPolicy::isAutoCost($batch->ai_cost_policy)) {
            return ['ok' => true, 'message' => 'Not an Auto Cost batch.', 'metadata' => []];
        }

        if (! $this->lock->assertHeldBy(SeoContentGenerationLock::OWNER_GLOBAL_BATCH, $batchId)) {
            return [
                'ok' => false,
                'message' => 'Global lock owner token invalid — wake aborted.',
                'metadata' => ['reason' => 'lock_owner_invalid'],
            ];
        }

        // Never start a second article while a child run is still active.
        $currentRunId = (int) ($batch->current_project_run_id ?? 0);
        if ($currentRunId > 0) {
            $existing = SeoProjectRun::query()->find($currentRunId);
            if ($existing instanceof SeoProjectRun
                && in_array((string) $existing->status, [
                    SeoProjectRun::STATUS_RUNNING,
                    SeoProjectRun::STATUS_STOPPING,
                ], true)
            ) {
                return [
                    'ok' => true,
                    'message' => 'Wake skipped — child run still active (no concurrent article).',
                    'metadata' => ['project_run_id' => $currentRunId, 'reason' => 'child_still_active'],
                ];
            }
        }

        $expired = $this->autoCostPolicy->expireFreeCircuit([
            'free_circuit_open_until' => $settings['free_circuit_open_until'] ?? null,
            'consecutive_free_rate_limits' => $settings['consecutive_free_rate_limits'] ?? 0,
            'now_ts' => now()->getTimestamp(),
        ]);
        if ($expired['re_probe']) {
            $settings['free_circuit_open_until'] = null;
            $settings['consecutive_free_rate_limits'] = 0;
            $batch->update(['settings' => $settings]);
        }

        // Promote due waiting_retry → pending for dispatch.
        SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
            ->where(function ($q): void {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->update([
                'status' => SeoContentGlobalBatchItem::STATUS_PENDING,
                'next_retry_at' => null,
                'updated_at' => now(),
            ]);

        $batch->update(['status' => SeoContentGlobalBatch::STATUS_RUNNING]);

        return $this->continueAfterAdvance($batchId, $actor);
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
                // Prevent reconciler / delayed wake from auto-rerunning after Emergency Stop.
                'auto_rerun_disabled' => true,
            ]),
        ]);

        $runId = (int) ($batch->current_project_run_id ?? 0);
        $run = $runId > 0 ? SeoProjectRun::query()->find($runId) : null;
        $childStillRunning = $run instanceof SeoProjectRun
            && in_array((string) $run->status, [
                SeoProjectRun::STATUS_RUNNING,
                SeoProjectRun::STATUS_STOPPING,
            ], true);

        if ($childStillRunning) {
            if ((string) $run->status === SeoProjectRun::STATUS_RUNNING) {
                $this->runEngine->requestStop($run, $actor->actorId, $reason ?? 'Global batch stop.');
            }

            // Lock remains until child finishes and advance marks terminal.
            return [
                'ok' => true,
                'message' => 'Stop requested — waiting for current article to finish.',
                'metadata' => [
                    'batch_id' => $batchId,
                    'current_project_run_id' => $runId,
                ],
            ];
        }

        // Child already terminal (incl. recoverable circuit_breaker/worker_lost) or missing:
        // User Stop is authoritative — sync outcomes first, then finalize and release lock.
        $batch = $batch->fresh() ?? $batch;
        if ($runId > 0) {
            $this->syncItemsFromChildRun($batch, $runId);
            $batch = $batch->fresh() ?? $batch;
        }
        $this->markRemainingPendingCancelled($batch);
        $this->markWaitingRetryCancelled($batch);
        $this->recount($batch);
        $this->completeTerminal($batch, SeoContentGlobalBatch::STATUS_CANCELLED);

        return [
            'ok' => true,
            'message' => 'Batch stopped — lock released (no active child worker).',
            'metadata' => [
                'batch_id' => $batchId,
                'status' => SeoContentGlobalBatch::STATUS_CANCELLED,
                'previous_project_run_id' => $runId > 0 ? $runId : null,
            ],
        ];
    }

    /**
     * Resume: pending continue; succeeded never rerun; skipped stay.
     * Legacy sticky batches: failed NOT auto-retried.
     * Auto Cost: waiting_retry + stopped_ai_unavailable are resumable; succeeded never rerun.
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

        $isAutoCost = GlobalMonthlyAiPolicy::isAutoCost($batch->ai_cost_policy);

        if ($batch->isTerminal() && ! $batch->isResumableTerminal()) {
            return ['ok' => false, 'message' => 'Batch completed — nothing to resume.', 'metadata' => []];
        }

        if ($isAutoCost && (string) $batch->status === SeoContentGlobalBatch::STATUS_STOPPED_AI_UNAVAILABLE) {
            // Clear exhausted flags so availability is re-probed after AI returns.
            $settings = is_array($batch->settings) ? $batch->settings : [];
            $settings['free_exhausted'] = false;
            $settings['paid_exhausted'] = false;
            $settings['free_circuit_open_until'] = null;
            $settings['consecutive_free_rate_limits'] = 0;
            $settings['stop_reason'] = null;
            $batch->update(['settings' => $settings]);

            SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
                ->update([
                    'status' => SeoContentGlobalBatchItem::STATUS_PENDING,
                    'next_retry_at' => null,
                    'updated_at' => now(),
                ]);
        }

        $pending = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_PENDING)
            ->count();

        $waitingDue = 0;
        if ($isAutoCost) {
            $waitingDue = SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
                ->count();
        }

        if ($pending <= 0 && $waitingDue <= 0) {
            return [
                'ok' => false,
                'message' => $isAutoCost
                    ? 'No pending or waiting-retry items.'
                    : 'No pending items. Failed items require explicit retry selection.',
                'metadata' => ['reason' => 'no_pending'],
            ];
        }

        if ($isAutoCost && $waitingDue > 0 && $pending <= 0) {
            // Promote all waiting_retry on explicit user Resume (AI available again / continue).
            SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
                ->update([
                    'status' => SeoContentGlobalBatchItem::STATUS_PENDING,
                    'next_retry_at' => null,
                    'updated_at' => now(),
                ]);
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
     * @return array{ok?: bool, done?: bool, waiting?: bool, stopped_ai_unavailable?: bool, message?: string, project_id?: int, run_id?: int, batch_item_id?: int, strategy?: string, next_retry_at?: mixed, metadata?: array<string, mixed>}
     */
    private function startNextProjectSlice(SeoContentGlobalBatch $batch, ActorContext $actor): array
    {
        if (GlobalMonthlyAiPolicy::isAutoCost($batch->ai_cost_policy)) {
            return $this->startNextAutoCostItem($batch, $actor);
        }

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

    /**
     * Auto Cost: concurrency = 1 article attempt. FREE → PAID → waiting_retry.
     *
     * @return array{ok?: bool, done?: bool, waiting?: bool, stopped_ai_unavailable?: bool, message?: string, project_id?: int, run_id?: int, batch_item_id?: int, strategy?: string, next_retry_at?: mixed, metadata?: array<string, mixed>}
     */
    private function startNextAutoCostItem(SeoContentGlobalBatch $batch, ActorContext $actor): array
    {
        $batchId = (int) $batch->getKey();
        $settings = is_array($batch->settings) ? $batch->settings : [];
        $nowTs = now()->getTimestamp();

        $expired = $this->autoCostPolicy->expireFreeCircuit([
            'free_circuit_open_until' => $settings['free_circuit_open_until'] ?? null,
            'consecutive_free_rate_limits' => $settings['consecutive_free_rate_limits'] ?? 0,
            'now_ts' => $nowTs,
        ]);
        if ($expired['re_probe']) {
            $settings['free_circuit_open_until'] = null;
            $settings['consecutive_free_rate_limits'] = 0;
            $batch->update(['settings' => $settings]);
        }

        $freeExhausted = (bool) ($settings['free_exhausted'] ?? false);
        $paidExhausted = (bool) ($settings['paid_exhausted'] ?? false);
        if ($freeExhausted && $paidExhausted) {
            return [
                'ok' => true,
                'stopped_ai_unavailable' => true,
                'message' => 'Free and Paid exhausted.',
            ];
        }

        // Idempotency: do not start another child run while one is active.
        $currentRunId = (int) ($batch->current_project_run_id ?? 0);
        if ($currentRunId > 0) {
            $existing = SeoProjectRun::query()->find($currentRunId);
            if ($existing instanceof SeoProjectRun
                && in_array((string) $existing->status, [
                    SeoProjectRun::STATUS_RUNNING,
                    SeoProjectRun::STATUS_STOPPING,
                ], true)
            ) {
                return [
                    'ok' => true,
                    'project_id' => (int) ($batch->current_project_id ?? 0),
                    'run_id' => $currentRunId,
                    'batch_item_id' => (int) ($batch->current_batch_item_id ?? 0) ?: null,
                    'message' => 'Slice already running.',
                ];
            }
        }

        /** @var SeoContentGlobalBatchItem|null $item */
        $item = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_PENDING)
            ->orderBy('position')
            ->first();

        if (! $item instanceof SeoContentGlobalBatchItem) {
            /** @var SeoContentGlobalBatchItem|null $due */
            $due = SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
                ->where(function ($q): void {
                    $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
                })
                ->orderBy('position')
                ->first();

            if ($due instanceof SeoContentGlobalBatchItem) {
                $due->update([
                    'status' => SeoContentGlobalBatchItem::STATUS_PENDING,
                    'next_retry_at' => null,
                    'updated_at' => now(),
                ]);
                $item = $due->fresh();
            }
        }

        if (! $item instanceof SeoContentGlobalBatchItem) {
            $waiting = SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
                ->orderBy('next_retry_at')
                ->first();

            if ($waiting instanceof SeoContentGlobalBatchItem && $waiting->next_retry_at !== null) {
                return [
                    'ok' => true,
                    'waiting' => true,
                    'next_retry_at' => $waiting->next_retry_at->getTimestamp(),
                    'message' => 'No due items — waiting for retry cooldown.',
                ];
            }

            return ['ok' => true, 'done' => true, 'message' => 'No more Auto Cost items.'];
        }

        $task = SeoProjectTask::query()->find((int) $item->task_id);
        if (! $task instanceof SeoProjectTask) {
            $this->markItemSkipped($batchId, (int) $item->task_id, 'task_missing');

            return $this->startNextAutoCostItem($batch->fresh() ?? $batch, $actor);
        }

        $decision = $this->classifier->classifyTask($task);
        if (! $decision->shouldRun()) {
            // Already succeeded elsewhere — never regenerate.
            if (in_array($decision->reason, ['successful_execution', 'valid_article_output', 'generation_meta_complete', 'last_run_item_completed'], true)) {
                $item->update([
                    'status' => SeoContentGlobalBatchItem::STATUS_SUCCEEDED,
                    'succeeded_via' => 'preexisting',
                    'finished_at' => now(),
                    'skip_reason' => $decision->reason,
                    'updated_at' => now(),
                ]);
                $this->recount($batch);

                return $this->startNextAutoCostItem($batch->fresh() ?? $batch, $actor);
            }
            $this->markItemSkipped($batchId, (int) $item->task_id, $decision->reason);

            return $this->startNextAutoCostItem($batch->fresh() ?? $batch, $actor);
        }

        $project = SeoProject::query()->find((int) $item->project_id);
        if (! $project instanceof SeoProject) {
            $this->markItemSkipped($batchId, (int) $item->task_id, 'project_missing');

            return $this->startNextAutoCostItem($batch->fresh() ?? $batch, $actor);
        }

        $attemptDecision = $this->autoCostPolicy->decideNextAttempt([
            'free_attempted' => (bool) $item->free_attempted,
            'paid_attempted' => (bool) $item->paid_attempted,
            'retry_count' => (int) ($item->retry_count ?? 0),
            'free_exhausted' => $freeExhausted,
            'paid_exhausted' => $paidExhausted,
            'free_circuit_open_until' => $settings['free_circuit_open_until'] ?? null,
            'consecutive_free_rate_limits' => (int) ($settings['consecutive_free_rate_limits'] ?? 0),
            'stop_requested' => $batch->stopRequested(),
            'now_ts' => $nowTs,
        ]);

        if ($attemptDecision['action'] === GlobalAutoGenerationPolicy::ACTION_STOP_AI_UNAVAILABLE) {
            $this->persistAvailabilitySettings($batch, $attemptDecision);

            return [
                'ok' => true,
                'stopped_ai_unavailable' => true,
                'message' => 'AI unavailable.',
                'metadata' => ['reason' => $attemptDecision['reason']],
            ];
        }

        if ($attemptDecision['action'] === GlobalAutoGenerationPolicy::ACTION_WAIT_COOLDOWN) {
            $this->persistAvailabilitySettings($batch, $attemptDecision);

            return [
                'ok' => true,
                'waiting' => true,
                'next_retry_at' => $attemptDecision['next_retry_at'],
                'message' => 'Free circuit cooldown — waiting.',
            ];
        }

        if ($attemptDecision['action'] === GlobalAutoGenerationPolicy::ACTION_PERMANENT_FAIL) {
            $item->update([
                'status' => SeoContentGlobalBatchItem::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => 'Max Auto Rerun rounds reached.',
                'last_error_category' => 'max_rerun_rounds',
                'updated_at' => now(),
            ]);
            $this->recount($batch);

            return $this->startNextAutoCostItem($batch->fresh() ?? $batch, $actor);
        }

        if (! in_array($attemptDecision['action'], [
            GlobalAutoGenerationPolicy::ACTION_RUN_FREE,
            GlobalAutoGenerationPolicy::ACTION_RUN_PAID,
        ], true)) {
            return [
                'ok' => false,
                'message' => 'Unexpected Auto Cost action: '.$attemptDecision['action'],
                'metadata' => $attemptDecision,
            ];
        }

        $strategy = (string) $attemptDecision['strategy'];

        return $this->dispatchAutoCostChildRun($batch, $item, $project, $strategy, $actor);
    }

    /**
     * @return array{ok?: bool, message?: string, project_id?: int, run_id?: int, batch_item_id?: int, strategy?: string, metadata?: array<string, mixed>}
     */
    private function continueAutoCostPaidAttempt(int $batchId, int $batchItemId, ?ActorContext $actor): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch || $batch->isTerminal()) {
            return ['ok' => true, 'message' => 'Batch terminal.', 'metadata' => []];
        }

        /** @var SeoContentGlobalBatchItem|null $item */
        $item = SeoContentGlobalBatchItem::query()->whereKey($batchItemId)->first();
        if (! $item instanceof SeoContentGlobalBatchItem) {
            return $this->continueAfterAdvance($batchId, $actor);
        }

        $project = SeoProject::query()->find((int) $item->project_id);
        if (! $project instanceof SeoProject) {
            $item->update([
                'status' => SeoContentGlobalBatchItem::STATUS_SKIPPED,
                'skip_reason' => 'project_missing',
                'finished_at' => now(),
            ]);

            return $this->continueAfterAdvance($batchId, $actor);
        }

        $actorContext = $actor ?? ActorContext::system();
        $started = $this->dispatchAutoCostChildRun(
            $batch,
            $item,
            $project,
            GlobalAutoGenerationPolicy::STRATEGY_PAID,
            $actorContext,
        );

        if (! ($started['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => (string) ($started['message'] ?? 'Paid fallback dispatch failed.'),
                'metadata' => $started['metadata'] ?? [],
            ];
        }

        return [
            'ok' => true,
            'message' => 'Started Paid fallback attempt.',
            'metadata' => $started,
        ];
    }

    /**
     * @return array{ok?: bool, message?: string, project_id?: int, run_id?: int, batch_item_id?: int, strategy?: string, metadata?: array<string, mixed>}
     */
    private function dispatchAutoCostChildRun(
        SeoContentGlobalBatch $batch,
        SeoContentGlobalBatchItem $item,
        SeoProject $project,
        string $strategy,
        ActorContext $actor,
    ): array {
        $batchId = (int) $batch->getKey();
        $settings = array_merge($this->autoCostPolicy->childRunSettingsForStrategy($strategy), [
            'global_batch_id' => $batchId,
            'global_batch_item_id' => (int) $item->getKey(),
            'global_auto_cost_strategy' => $strategy,
            'disable_batch_circuit_breaker' => true,
            'inherits_global_lock' => true,
            'lazy_bulk' => true,
            'use_php_engine' => true,
            // Isolate writing checkpoints across Free vs Paid strategies.
            'auto_cost_fresh_writing' => $strategy === GlobalAutoGenerationPolicy::STRATEGY_PAID,
        ]);

        /** @var ContentProjectActionResult $result */
        $result = $this->commandBus->dispatch(
            new GenerateProjectItemsCommand(
                projectRef: (int) $project->getKey(),
                itemRefs: [(int) $item->task_id],
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
                    'project_id' => (int) $project->getKey(),
                    'batch_item_id' => (int) $item->getKey(),
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
                'metadata' => ['project_id' => (int) $project->getKey()],
            ];
        }

        $attemptMeta = is_array($item->attempt_meta) ? $item->attempt_meta : [];
        $attemptNumber = count($attemptMeta) + 1;
        $attemptMeta[] = $this->autoCostPolicy->buildAttemptRecord(
            batchItemId: (int) $item->getKey(),
            strategy: $strategy,
            attemptNumber: $attemptNumber,
            provider: null,
            model: null,
            result: 'started',
            errorCategory: null,
            startedTs: now()->getTimestamp(),
            finishedTs: null,
        );

        $item->update([
            'status' => SeoContentGlobalBatchItem::STATUS_RUNNING,
            'current_strategy' => $strategy,
            'project_run_id' => $runId,
            'started_at' => $item->started_at ?? now(),
            'attempt_count' => max(1, (int) $item->attempt_count) + ($attemptNumber > 1 ? 1 : 0),
            'attempt_meta' => $attemptMeta,
            'finished_at' => null,
            'updated_at' => now(),
        ]);

        $batch->update([
            'current_project_id' => (int) $project->getKey(),
            'current_project_run_id' => $runId,
            'current_batch_item_id' => (int) $item->getKey(),
            'settings' => array_merge(is_array($batch->settings) ? $batch->settings : [], [
                'current_strategy' => $strategy,
                'retry_round' => (int) ($item->retry_count ?? 0),
            ]),
        ]);
        $this->recount($batch);

        return [
            'ok' => true,
            'project_id' => (int) $project->getKey(),
            'run_id' => $runId,
            'batch_item_id' => (int) $item->getKey(),
            'strategy' => $strategy,
            'message' => 'Started Auto Cost '.$strategy.' attempt.',
        ];
    }

    /**
     * Sync one Auto Cost article from its child run — never mark permanent fail on first Free failure.
     *
     * @return array{action: string, reason?: string, next_retry_at?: ?int, batch_item_id?: int}
     */
    private function syncAutoCostItemFromChildRun(SeoContentGlobalBatch $batch, int $projectRunId): array
    {
        $batchId = (int) $batch->getKey();
        $itemId = (int) ($batch->current_batch_item_id ?? 0);

        /** @var SeoContentGlobalBatchItem|null $item */
        $item = $itemId > 0
            ? SeoContentGlobalBatchItem::query()->whereKey($itemId)->first()
            : SeoContentGlobalBatchItem::query()
                ->where('batch_id', $batchId)
                ->where('project_run_id', $projectRunId)
                ->where('status', SeoContentGlobalBatchItem::STATUS_RUNNING)
                ->orderBy('position')
                ->first();

        if (! $item instanceof SeoContentGlobalBatchItem) {
            return ['action' => 'continue', 'reason' => 'item_missing'];
        }

        $runItem = SeoProjectRunItem::query()
            ->where('run_id', $projectRunId)
            ->where('task_id', (int) $item->task_id)
            ->articleExecution()
            ->orderByDesc('id')
            ->first();

        $status = $runItem instanceof SeoProjectRunItem
            ? SeoProjectRunItemStatus::fromLegacy($runItem->status !== null ? (string) $runItem->status : null)
            : null;

        $childRun = SeoProjectRun::query()->find($projectRunId);
        $childSettings = $childRun instanceof SeoProjectRun && is_array($childRun->settings)
            ? $childRun->settings
            : [];
        $strategy = (string) ($item->current_strategy
            ?? $childSettings['global_auto_cost_strategy']
            ?? GlobalAutoGenerationPolicy::STRATEGY_FREE);

        $success = $status === SeoProjectRunItemStatus::Success;
        $errorMessage = $runItem instanceof SeoProjectRunItem && $runItem->error_message !== null
            ? (string) $runItem->error_message
            : null;
        $errorCategory = $this->autoCostPolicy->classifyErrorCategory([
            'message' => $errorMessage,
            'failure_class' => is_array($runItem?->output_snapshot ?? null)
                ? ($runItem->output_snapshot['failure_class'] ?? $runItem->output_snapshot['error_category'] ?? null)
                : null,
        ]);

        $settings = is_array($batch->settings) ? $batch->settings : [];
        $outcome = $this->autoCostPolicy->afterAttempt([
            'strategy' => $strategy,
            'success' => $success,
            'error_category' => $success ? null : $errorCategory,
            'free_exhausted' => (bool) ($settings['free_exhausted'] ?? false),
            'paid_exhausted' => (bool) ($settings['paid_exhausted'] ?? false),
            'free_circuit_open_until' => $settings['free_circuit_open_until'] ?? null,
            'consecutive_free_rate_limits' => (int) ($settings['consecutive_free_rate_limits'] ?? 0),
            'free_attempted' => (bool) $item->free_attempted,
            'paid_attempted' => (bool) $item->paid_attempted,
            'retry_count' => (int) ($item->retry_count ?? 0),
            'now_ts' => now()->getTimestamp(),
            // Do not flip free_exhausted from a single article failure.
            'free_candidates_available' => true,
        ]);

        $this->persistAvailabilitySettings($batch, $outcome);

        $attemptMeta = is_array($item->attempt_meta) ? $item->attempt_meta : [];
        if ($attemptMeta !== []) {
            $last = count($attemptMeta) - 1;
            $attemptMeta[$last]['result'] = $success ? 'succeeded' : 'failed';
            $attemptMeta[$last]['error_category'] = $success ? null : $errorCategory;
            $attemptMeta[$last]['finished_at'] = now()->getTimestamp();
        } else {
            $attemptMeta[] = $this->autoCostPolicy->buildAttemptRecord(
                batchItemId: (int) $item->getKey(),
                strategy: $strategy,
                attemptNumber: 1,
                provider: null,
                model: null,
                result: $success ? 'succeeded' : 'failed',
                errorCategory: $success ? null : $errorCategory,
                startedTs: $item->started_at?->getTimestamp(),
                finishedTs: now()->getTimestamp(),
            );
        }

        if ($outcome['action'] === GlobalAutoGenerationPolicy::ACTION_SUCCEEDED) {
            $via = $strategy === GlobalAutoGenerationPolicy::STRATEGY_FREE ? 'free' : 'paid';
            $item->update([
                'status' => SeoContentGlobalBatchItem::STATUS_SUCCEEDED,
                'succeeded_via' => $via,
                'free_attempted' => (bool) $outcome['free_attempted'],
                'paid_attempted' => (bool) $outcome['paid_attempted'],
                'project_run_id' => $projectRunId,
                'run_item_id' => $runItem instanceof SeoProjectRunItem ? (int) $runItem->id : null,
                'attempt_meta' => $attemptMeta,
                'error_message' => null,
                'last_error_category' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            $settings = is_array($batch->settings) ? $batch->settings : [];
            if ($via === 'free') {
                $settings['free_succeeded'] = (int) ($settings['free_succeeded'] ?? 0) + 1;
            } else {
                $settings['paid_fallback_succeeded'] = (int) ($settings['paid_fallback_succeeded'] ?? 0) + 1;
            }
            $batch->update(['settings' => $settings]);
            $this->recount($batch);

            return ['action' => 'continue', 'reason' => $outcome['reason']];
        }

        $settings = is_array($batch->settings) ? $batch->settings : [];
        $settings['failed_attempt_count'] = (int) ($settings['failed_attempt_count'] ?? 0) + 1;
        $batch->update(['settings' => $settings]);

        if ($outcome['action'] === GlobalAutoGenerationPolicy::ACTION_RUN_PAID) {
            $item->update([
                'status' => SeoContentGlobalBatchItem::STATUS_PENDING,
                'current_strategy' => GlobalAutoGenerationPolicy::STRATEGY_PAID,
                'free_attempted' => true,
                'paid_attempted' => false,
                'attempt_meta' => $attemptMeta,
                'error_message' => $errorMessage,
                'last_error_category' => $errorCategory,
                'finished_at' => null,
                'updated_at' => now(),
            ]);
            $this->recount($batch);

            return [
                'action' => GlobalAutoGenerationPolicy::ACTION_RUN_PAID,
                'batch_item_id' => (int) $item->getKey(),
                'reason' => $outcome['reason'],
            ];
        }

        if ($outcome['action'] === GlobalAutoGenerationPolicy::ACTION_WAITING_RETRY) {
            $nextTs = $outcome['next_retry_at'] ?? $this->autoCostPolicy->computeNextRetryAt((int) $outcome['retry_count']);
            $item->update([
                'status' => SeoContentGlobalBatchItem::STATUS_WAITING_RETRY,
                'free_attempted' => (bool) $outcome['free_attempted'],
                'paid_attempted' => (bool) $outcome['paid_attempted'],
                'retry_count' => (int) $outcome['retry_count'],
                'next_retry_at' => Carbon::createFromTimestamp($nextTs),
                'attempt_meta' => $attemptMeta,
                'error_message' => $errorMessage,
                'last_error_category' => $errorCategory,
                'finished_at' => null,
                'updated_at' => now(),
            ]);
            $this->recount($batch);

            return [
                'action' => GlobalAutoGenerationPolicy::ACTION_WAITING_RETRY,
                'next_retry_at' => $nextTs,
                'reason' => $outcome['reason'],
            ];
        }

        if ($outcome['action'] === GlobalAutoGenerationPolicy::ACTION_STOP_AI_UNAVAILABLE) {
            $item->update([
                'status' => SeoContentGlobalBatchItem::STATUS_WAITING_RETRY,
                'free_attempted' => (bool) $outcome['free_attempted'],
                'paid_attempted' => (bool) $outcome['paid_attempted'],
                'retry_count' => (int) $outcome['retry_count'],
                'attempt_meta' => $attemptMeta,
                'error_message' => $errorMessage,
                'last_error_category' => $errorCategory,
                'updated_at' => now(),
            ]);
            $this->recount($batch);

            return [
                'action' => GlobalAutoGenerationPolicy::ACTION_STOP_AI_UNAVAILABLE,
                'reason' => $outcome['reason'],
            ];
        }

        if ($outcome['action'] === GlobalAutoGenerationPolicy::ACTION_PERMANENT_FAIL) {
            $item->update([
                'status' => SeoContentGlobalBatchItem::STATUS_FAILED,
                'free_attempted' => (bool) $outcome['free_attempted'],
                'paid_attempted' => (bool) $outcome['paid_attempted'],
                'retry_count' => (int) $outcome['retry_count'],
                'attempt_meta' => $attemptMeta,
                'error_message' => $errorMessage ?? 'Permanently failed after Auto Rerun cap.',
                'last_error_category' => $errorCategory,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->recount($batch);

            return ['action' => 'continue', 'reason' => 'permanent_fail'];
        }

        // Unknown / system — fail closed without unsafe retry of succeeded articles.
        $item->update([
            'status' => SeoContentGlobalBatchItem::STATUS_FAILED,
            'attempt_meta' => $attemptMeta,
            'error_message' => $errorMessage ?? 'Auto Cost system failure.',
            'last_error_category' => $errorCategory,
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recount($batch);

        return [
            'action' => GlobalAutoGenerationPolicy::ACTION_STOP_SYSTEM,
            'reason' => $outcome['reason'] ?? 'unknown_outcome',
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     */
    private function persistAvailabilitySettings(SeoContentGlobalBatch $batch, array $decision): void
    {
        $settings = is_array($batch->settings) ? $batch->settings : [];
        if (array_key_exists('free_exhausted', $decision)) {
            $settings['free_exhausted'] = (bool) $decision['free_exhausted'];
        }
        if (array_key_exists('paid_exhausted', $decision)) {
            $settings['paid_exhausted'] = (bool) $decision['paid_exhausted'];
        }
        if (array_key_exists('free_circuit_open_until', $decision)) {
            $settings['free_circuit_open_until'] = $decision['free_circuit_open_until'];
        }
        if (array_key_exists('consecutive_free_rate_limits', $decision)) {
            $settings['consecutive_free_rate_limits'] = (int) $decision['consecutive_free_rate_limits'];
        }
        $batch->update(['settings' => $settings]);
    }

    /**
     * Ensure a delayed wake exists for Auto Cost waiting_retry (idempotent redispatch helper).
     * Used by reconciler when wake jobs were dropped after worker/queue restart.
     *
     * @return array{ok: bool, message: string, metadata: array<string, mixed>}
     */
    public function ensureAutoRetryWakeScheduled(int $batchId): array
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if (! $batch instanceof SeoContentGlobalBatch) {
            return ['ok' => false, 'message' => 'Batch not found.', 'metadata' => []];
        }

        if ($batch->isTerminal()) {
            return ['ok' => true, 'message' => 'Batch terminal — no wake.', 'metadata' => []];
        }

        $settings = is_array($batch->settings) ? $batch->settings : [];
        if ($batch->stopRequested() || ! empty($settings['auto_rerun_disabled'])) {
            return ['ok' => true, 'message' => 'Auto rerun disabled — no wake.', 'metadata' => ['reason' => 'auto_rerun_disabled']];
        }

        if (! GlobalMonthlyAiPolicy::isAutoCost($batch->ai_cost_policy)) {
            return ['ok' => true, 'message' => 'Not Auto Cost.', 'metadata' => []];
        }

        /** @var SeoContentGlobalBatchItem|null $earliest */
        $earliest = SeoContentGlobalBatchItem::query()
            ->where('batch_id', $batchId)
            ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
            ->orderBy('next_retry_at')
            ->first();

        if (! $earliest instanceof SeoContentGlobalBatchItem) {
            return ['ok' => true, 'message' => 'No waiting_retry items.', 'metadata' => []];
        }

        $next = $earliest->next_retry_at?->getTimestamp() ?? now()->getTimestamp();
        if ($next <= now()->getTimestamp()) {
            // Due now — wake immediately (still subject to concurrency guards).
            return $this->wakeForAutoRetry($batchId, ActorContext::system());
        }

        $this->scheduleWake($batchId, $next);

        return [
            'ok' => true,
            'message' => 'Wake scheduled for waiting_retry cooldown.',
            'metadata' => ['next_retry_at' => $next],
        ];
    }

    private function scheduleWake(int $batchId, mixed $nextRetryAt): void
    {
        /** @var SeoContentGlobalBatch|null $batch */
        $batch = SeoContentGlobalBatch::query()->find($batchId);
        if ($batch instanceof SeoContentGlobalBatch) {
            $settings = is_array($batch->settings) ? $batch->settings : [];
            if ($batch->stopRequested() || ! empty($settings['auto_rerun_disabled'])) {
                return;
            }
        }

        $delaySeconds = 60;
        if (is_int($nextRetryAt) && $nextRetryAt > 0) {
            $delaySeconds = max(5, $nextRetryAt - time());
        } elseif ($nextRetryAt instanceof \DateTimeInterface) {
            $delaySeconds = max(5, $nextRetryAt->getTimestamp() - time());
        }

        try {
            WakeGlobalMonthlyBatchJob::dispatch($batchId)->delay(now()->addSeconds($delaySeconds));
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'global_monthly_batch.wake_dispatch',
                'batch_id' => $batchId,
            ]);
        }
    }

    private function markWaitingRetryCancelled(SeoContentGlobalBatch $batch): void
    {
        SeoContentGlobalBatchItem::query()
            ->where('batch_id', (int) $batch->id)
            ->where('status', SeoContentGlobalBatchItem::STATUS_WAITING_RETRY)
            ->update([
                'status' => SeoContentGlobalBatchItem::STATUS_CANCELLED,
                'finished_at' => now(),
                'updated_at' => now(),
                'skip_reason' => 'batch_stop_requested',
                'next_retry_at' => null,
            ]);
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
                default => null,
            };
            // Enum has no Cancelled case — map legacy cancelled string explicitly.
            if ($mapped === null && strtolower(trim((string) ($runItem->status ?? ''))) === 'cancelled') {
                $mapped = SeoContentGlobalBatchItem::STATUS_CANCELLED;
            }

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
                + (int) ($counts[SeoContentGlobalBatchItem::STATUS_RUNNING] ?? 0)
                + (int) ($counts[SeoContentGlobalBatchItem::STATUS_WAITING_RETRY] ?? 0),
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
