<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\RunEngine;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRunSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleRuntimeStatusResolver;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkItemDecision;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectBulkItemDecisionService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectFreshKeywordWorkspaceResetService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchCircuitBreakerState;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchFailureSignature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunHealthReport;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunRecoverableState;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;
use Omnichannel\Addons\ContentProjects\Support\SeoProjectRunItemClassifier;
use App\Support\RuntimeLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns Content Project run lifecycle + article dispatch.
 * Does not call AI/workflow nodes directly.
 */
final class ContentProjectRunEngine
{
    public const SETTINGS_ENGINE_KEY = 'php_engine';

    public function __construct(
        private readonly SeoProjectWorkflowRunService $workflowRunService,
        private readonly SeoProjectRunItemService $runItemService,
        private readonly SeoProjectWorkflowStepRetryService $stepRetryService,
        private readonly RunCancellationGuard $cancellationGuard,
        private readonly ContentProjectRunStatusMapper $statusMapper,
        private readonly ContentProjectRunEventPublisher $events,
    ) {}

    /**
     * Start orchestration for an already-seeded run.
     * Idempotent: second start while work is live does not duplicate dispatch.
     * Web request returns quickly — no provider call.
     */
    public function start(SeoProjectRun $run): void
    {
        $decision = DB::connection('omi_seo_ai')->transaction(function () use ($run): string {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectRun) {
                return 'missing';
            }

            $status = $this->statusMapper->runFromDb((string) $locked->status);
            if ($status->isTerminal()) {
                return 'terminal';
            }

            if ($status === ContentProjectRunSemanticStatus::Stopping) {
                return 'stopping';
            }

            $this->sweepStaleActiveDispatch($locked);

            if ($status === ContentProjectRunSemanticStatus::Running
                && ($this->hasBlockingActiveDispatch($locked) || $this->activeProcessingCount($locked) > 0)
            ) {
                return 'already_running';
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $firstStart = empty($engine['started_at']);

            $engine['enabled'] = true;
            $engine['use_php_engine'] = true;
            $engine['orchestration'] = 'php';
            $engine['started_at'] = $engine['started_at'] ?? now()->toIso8601String();
            $engine['max_parallel_articles'] = ContentProjectRunEngineFeature::effectiveMaxParallelArticles();
            $settings['use_php_engine'] = true;
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;

            $previousStatus = (string) $locked->status;
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Running),
                'finished_at' => null,
                'settings' => $settings,
            ]);

            RuntimeLogger::info('content_project_run.transition', [
                'run_id' => (int) $locked->id,
                'before' => $previousStatus,
                'after' => 'running',
                'decision' => $firstStart ? 'dispatch_fresh' : 'dispatch_resume',
                'reason' => 'start',
            ]);

            return $firstStart ? 'dispatch_fresh' : 'dispatch_resume';
        });

        $run->refresh();

        RuntimeLogger::info('content_project_run.started', [
            'run_id' => (int) $run->id,
            'decision' => $decision,
            'feature_flag' => ContentProjectRunEngineFeature::enabledFor($run),
            'feature_flag_global' => ContentProjectRunEngineFeature::enabled(),
            'status' => (string) $run->status,
        ]);

        if ($decision === 'terminal' || $decision === 'stopping' || $decision === 'already_running' || $decision === 'missing') {
            RuntimeLogger::info('content_project_run.next_dispatch_skipped', [
                'run_id' => (int) $run->id,
                'reason' => $decision,
            ]);

            return;
        }

        if ($decision === 'dispatch_fresh') {
            $this->events->runStarted($run);
        }

        $this->dispatchNextArticle($run);
    }

    public function resume(SeoProjectRun $run): void
    {
        $run->refresh();

        if ((string) $run->status === SeoProjectRun::STATUS_STOPPING) {
            $this->clearStoppingToRunning($run);
            $run->refresh();
        }

        if ($this->tryResumeAfterWorkerLost($run)) {
            return;
        }

        if ($this->tryResumeAfterCircuitBreaker($run)) {
            return;
        }

        if (! $this->cancellationGuard->allowsDispatch($run)) {
            $this->finalizeIfDone($run);

            return;
        }

        $this->dispatchNextArticle($run);
    }

    public function isRecoverable(SeoProjectRun $run): bool
    {
        return ContentProjectRunRecoverableState::isRecoverableRun($run);
    }

    /**
     * Cooperative stop → running so dispatch can continue without a live worker.
     */
    private function clearStoppingToRunning(SeoProjectRun $run): void
    {
        DB::connection('omi_seo_ai')->transaction(function () use ($run): void {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return;
            }
            if ((string) $locked->status !== SeoProjectRun::STATUS_STOPPING) {
                return;
            }

            $previousStatus = (string) $locked->status;
            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $engine = ContentProjectRunRecoverableState::clearStopAndFinalMarkers($engine);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Running),
                'finished_at' => null,
                'settings' => $settings,
            ]);

            RuntimeLogger::info('content_project_run.transition', [
                'run_id' => (int) $locked->id,
                'before' => $previousStatus,
                'after' => 'running',
                'decision' => 'dispatch_resume',
                'reason' => 'resume_from_stopping',
            ]);
        });
    }

    /**
     * Resume after hard worker death — re-queue the interrupted item (attempt N → N+1).
     * Does not auto-advance to the next article before that item finishes.
     */
    private function tryResumeAfterWorkerLost(SeoProjectRun $run): bool
    {
        $engine = $this->engineBag($run);
        if (ContentProjectRunRecoverableState::reasonFromEngine($engine)
            !== ContentProjectRunRecoverableState::REASON_WORKER_LOST
        ) {
            return false;
        }

        $lost = is_array($engine[ContentProjectRunRecoverableState::WORKER_LOST_KEY] ?? null)
            ? $engine[ContentProjectRunRecoverableState::WORKER_LOST_KEY]
            : [];
        $runItemId = (int) ($lost['run_item_id'] ?? ($engine[ContentProjectRunRecoverableState::SETTINGS_KEY]['run_item_id'] ?? 0));
        if ($runItemId <= 0) {
            return false;
        }

        $item = SeoProjectRunItem::query()->find($runItemId);
        if (! $item instanceof SeoProjectRunItem || (int) $item->run_id !== (int) $run->id) {
            return false;
        }

        $status = $this->statusMapper->runFromDb((string) $run->status);
        if ($status !== ContentProjectRunSemanticStatus::Failed
            && $status !== ContentProjectRunSemanticStatus::Running
        ) {
            return false;
        }

        $currentAttempt = max(1, (int) ($item->attempt ?? 1));
        $nextAttempt = $currentAttempt + 1;
        if ($nextAttempt > ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS) {
            RuntimeLogger::warning('content_project_run.worker_lost_resume_exhausted', [
                'run_id' => (int) $run->id,
                'run_item_id' => $runItemId,
                'attempt' => $currentAttempt,
            ]);

            return false;
        }

        $dispatch = DB::connection('omi_seo_ai')->transaction(function () use ($run, $item, $nextAttempt): ?array {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return null;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            if (ContentProjectRunRecoverableState::reasonFromEngine($engine)
                !== ContentProjectRunRecoverableState::REASON_WORKER_LOST
            ) {
                return null;
            }

            /** @var SeoProjectRunItem|null $lockedItem */
            $lockedItem = SeoProjectRunItem::query()
                ->whereKey((int) $item->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedItem instanceof SeoProjectRunItem) {
                return null;
            }

            $lockedItem->update([
                'status' => SeoProjectRunItemStatus::Pending->value,
                'attempt' => $nextAttempt,
                'started_at' => null,
                'finished_at' => null,
                'message' => 'Resumed after worker loss — attempt '.$nextAttempt.'.',
                'error_message' => null,
            ]);

            $engine = ContentProjectRunRecoverableState::clear($engine);
            unset($engine['active_dispatch']);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Running),
                'finished_at' => null,
                'settings' => $settings,
            ]);

            return [
                'run_id' => (int) $locked->id,
                'task_id' => (int) $lockedItem->task_id,
                'run_item_id' => (int) $lockedItem->id,
                'attempt' => $nextAttempt,
            ];
        });

        if ($dispatch === null) {
            return false;
        }

        $run->refresh();
        RuntimeLogger::info('content_project_run.worker_lost_resumed', [
            'run_id' => (int) $run->id,
            'run_item_id' => $dispatch['run_item_id'],
            'attempt' => $dispatch['attempt'],
        ]);

        // Same interrupted item is now the lowest pending id with attempt bumped — claim it first.
        $this->dispatchNextArticle($run);

        return true;
    }

    /**
     * Resume a run halted by consecutive identical failures — pending items stay pending.
     */
    private function tryResumeAfterCircuitBreaker(SeoProjectRun $run): bool
    {
        $engine = $this->engineBag($run);
        $reason = ContentProjectRunRecoverableState::reasonFromEngine($engine);
        $breaker = is_array($engine['circuit_breaker'] ?? null) ? $engine['circuit_breaker'] : null;
        if ($reason !== ContentProjectRunRecoverableState::REASON_CIRCUIT_BREAKER
            && ($breaker === null || empty($breaker['stopped']))
        ) {
            return false;
        }

        $pending = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->where('status', SeoProjectRunItemStatus::Pending->value)
            ->count();
        if ($pending <= 0) {
            return false;
        }

        $status = $this->statusMapper->runFromDb((string) $run->status);
        if (! $status->isTerminal() && $status !== ContentProjectRunSemanticStatus::Running) {
            return false;
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($run): void {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $engine = ContentProjectRunRecoverableState::clear($engine);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Running),
                'finished_at' => null,
                'settings' => $settings,
            ]);
        });

        $run->refresh();
        RuntimeLogger::info('content_project_run.circuit_breaker_resumed', [
            'run_id' => (int) $run->id,
        ]);
        $this->dispatchNextArticle($run);

        return true;
    }

    public function requestStop(
        SeoProjectRun $run,
        ?int $actorId = null,
        ?string $reason = null,
    ): void {
        $run->refresh();

        if ($this->cancellationGuard->isTerminal($run)) {
            return;
        }

        $reason = $reason !== null && trim($reason) !== ''
            ? trim($reason)
            : 'Stopped by user.';

        $alreadyStopping = $this->statusMapper->runFromDb((string) $run->status)
            === ContentProjectRunSemanticStatus::Stopping;

        if ($alreadyStopping) {
            RuntimeLogger::info('content_project_run.stop_requested', [
                'run_id' => (int) $run->id,
                'decision' => 'already_stopping',
                'reason' => $reason,
            ]);
            $this->finalizeIfDone($run);

            return;
        }

        $transitioned = DB::connection('omi_seo_ai')->transaction(function () use ($run, $actorId, $reason): bool {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectRun) {
                return false;
            }

            $status = $this->statusMapper->runFromDb((string) $locked->status);
            if ($status->isTerminal() || $status === ContentProjectRunSemanticStatus::Stopping) {
                return false;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $engine['stop_requested_at'] = now()->toIso8601String();
            $engine['stop_requested_by'] = $actorId;
            $engine['stop_reason'] = $reason;
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;

            $previous = (string) $locked->status;
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Stopping),
                'settings' => $settings,
            ]);

            RuntimeLogger::info('content_project_run.transition', [
                'run_id' => (int) $locked->id,
                'before' => $previous,
                'after' => 'stopping',
                'decision' => 'request_stop',
                'reason' => $reason,
            ]);

            return true;
        });

        $run->refresh();

        if ($transitioned) {
            $this->events->runStopping($run, $reason);
            RuntimeLogger::info('content_project_run.stop_requested', [
                'run_id' => (int) $run->id,
                'actor_id' => $actorId,
                'reason' => $reason,
                'status' => (string) $run->status,
                'decision' => 'stopping',
            ]);

            try {
                $this->stepRetryService->cancelAllActiveSteps($run);
            } catch (\Throwable $exception) {
                RuntimeLogger::warning('seo.content_project_run.engine.stop_cancel_steps_failed', [
                    'run_id' => (int) $run->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->finalizeIfDone($run->fresh() ?? $run);
    }

    /**
     * Atomically reserve next pending article-level item and dispatch job.
     * Lazy bulk: JIT decide NOW (generate/resume/restart/skip) — never use launch-time partition.
     * Max 1 active article per run (and thus per bulk).
     */
    public function dispatchNextArticle(SeoProjectRun $run): void
    {
        $maxSkipPasses = 500;
        for ($pass = 0; $pass < $maxSkipPasses; $pass++) {
            $dispatch = $this->claimNextPendingArticle($run);
            if ($dispatch === null) {
                RuntimeLogger::info('content_project_run.next_dispatch_skipped', [
                    'run_id' => (int) $run->id,
                    'reason' => 'no_candidate_or_busy_or_stopped',
                ]);
                $this->finalizeIfDone($run->fresh() ?? $run);

                return;
            }

            if (($dispatch['fail_closed'] ?? false) === true) {
                $this->failClosedBulk($run->fresh() ?? $run, (string) ($dispatch['reason'] ?? 'orchestration_corrupt'));

                return;
            }

            $run = $run->fresh() ?? $run;
            $settings = is_array($run->settings) ? $run->settings : [];
            $lazyBulk = (bool) ($settings['lazy_bulk'] ?? false);

            if (! $lazyBulk) {
                $this->enqueueArticleJob($run, $dispatch);

                return;
            }

            $decision = $this->decideLazyBulkItem($run, (int) $dispatch['task_id']);
            if ($decision->isSkip()) {
                $this->skipClaimedBulkItem($run, $dispatch, $decision);
                $run = $run->fresh() ?? $run;
                continue;
            }

            $prepared = $this->applyLazyBulkDecision($run, $dispatch, $decision);
            if ($prepared === null) {
                $this->failClosedBulk($run->fresh() ?? $run, 'lazy_bulk_apply_failed');

                return;
            }

            $this->enqueueArticleJob($run->fresh() ?? $run, $prepared);

            return;
        }

        $this->failClosedBulk($run->fresh() ?? $run, 'lazy_bulk_skip_loop_exhausted');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimNextPendingArticle(SeoProjectRun $run): ?array
    {
        return DB::connection('omi_seo_ai')->transaction(function () use ($run): ?array {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectRun) {
                return null;
            }

            try {
                $this->sweepStaleActiveDispatch($locked);
            } catch (\Throwable $e) {
                RuntimeLogger::error('content_project_run.sweep_stale_failed', [
                    'run_id' => (int) $locked->id,
                    'error' => $e->getMessage(),
                ]);

                return ['fail_closed' => true, 'reason' => 'active_dispatch_sweep_failed'];
            }
            $locked->refresh();

            $status = $this->statusMapper->runFromDb((string) $locked->status);
            if (! $status->allowsDispatch()) {
                return null;
            }

            if ($this->isCircuitBreakerStopped($locked)) {
                return null;
            }

            if ($this->hasBlockingActiveDispatch($locked) || $this->activeProcessingCount($locked) > 0) {
                return null;
            }

            /** @var SeoProjectRunItem|null $next */
            $next = SeoProjectRunItem::query()
                ->where('run_id', (int) $locked->id)
                ->articleExecution()
                ->where('status', SeoProjectRunItemStatus::Pending->value)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $next instanceof SeoProjectRunItem) {
                return null;
            }

            $dispatchToken = hash('sha256', implode('|', [
                (int) $locked->id,
                (int) $next->id,
                (int) $next->attempt,
                (string) microtime(true),
            ]));

            $articleUpdatedAt = null;
            $task = SeoProjectTask::query()->with('article')->find((int) $next->task_id);
            if ($task instanceof SeoProjectTask && (int) ($task->article_id ?? 0) > 0) {
                $article = $task->article;
                if ($article !== null && $article->updated_at !== null) {
                    $articleUpdatedAt = $article->updated_at->toIso8601String();
                }
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $engine['active_dispatch'] = [
                'task_id' => (int) $next->task_id,
                'run_item_id' => (int) $next->id,
                'article_id' => $next->article_id !== null ? (int) $next->article_id : null,
                'attempt' => (int) $next->attempt,
                'token' => $dispatchToken,
                'dispatched_at' => now()->toIso8601String(),
                'last_heartbeat_at' => now()->toIso8601String(),
                'claimed_at' => null,
                'current_step' => 'queued',
                'article_updated_at_snapshot' => $articleUpdatedAt,
            ];
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update(['settings' => $settings]);

            return [
                'run_id' => (int) $locked->id,
                'task_id' => (int) $next->task_id,
                'run_item_id' => (int) $next->id,
                'attempt' => (int) $next->attempt,
                'token' => $dispatchToken,
            ];
        });
    }

    private function decideLazyBulkItem(SeoProjectRun $run, int $taskId): ContentProjectBulkItemDecision
    {
        $run->loadMissing('project');
        $project = $run->project;
        $task = SeoProjectTask::query()->with('article')->find($taskId);
        if (! $project instanceof SeoProject || ! $task instanceof SeoProjectTask) {
            return new ContentProjectBulkItemDecision(
                taskId: $taskId,
                operation: ContentProjectBulkItemDecision::OP_SKIP,
                reason: 'task_or_project_missing',
            );
        }

        $settings = is_array($run->settings) ? $run->settings : [];
        $allowImprove = (bool) ($settings[\Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectImproveManualOnlyGenerationGuard::ALLOW_IMPROVE_GENERATION_SETTING] ?? false);

        return app(ContentProjectBulkItemDecisionService::class)->decide($project, $task, [
            'allow_improve_generation' => $allowImprove,
            'recover_stale' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $dispatch
     */
    private function skipClaimedBulkItem(
        SeoProjectRun $run,
        array $dispatch,
        ContentProjectBulkItemDecision $decision,
    ): void {
        $runItemId = (int) ($dispatch['run_item_id'] ?? 0);
        $item = SeoProjectRunItem::query()->find($runItemId);
        if ($item instanceof SeoProjectRunItem) {
            $this->runItemService->markSkipped(
                $item,
                'Skipped: '.$decision->reason,
            );
        }

        $this->clearActiveDispatch($run, (int) $dispatch['task_id'], $runItemId);
        $run = $this->runItemService->syncMirrorAndCounters($run->fresh() ?? $run, false);
        $this->events->runProgressUpdated($run);

        $skipResult = new ArticleExecutionResult(
            runId: (int) $run->id,
            taskId: (int) $dispatch['task_id'],
            runItemId: $runItemId > 0 ? $runItemId : null,
            status: ContentProjectArticleSemanticStatus::Skipped,
            message: 'Skipped: '.$decision->reason,
            payload: ['skip_reason' => $decision->reason],
        );
        $this->events->articleCompleted($run, $skipResult);

        RuntimeLogger::info('content_project_run.lazy_bulk_item_skipped', [
            'run_id' => (int) $run->id,
            'task_id' => (int) $dispatch['task_id'],
            'run_item_id' => $runItemId,
            'reason' => $decision->reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $dispatch
     * @return array<string, mixed>|null
     */
    private function applyLazyBulkDecision(
        SeoProjectRun $run,
        array $dispatch,
        ContentProjectBulkItemDecision $decision,
    ): ?array {
        return DB::connection('omi_seo_ai')->transaction(function () use ($run, $dispatch, $decision): ?array {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return null;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            if ($active === null
                || (int) ($active['run_item_id'] ?? 0) !== (int) $dispatch['run_item_id']
                || (string) ($active['token'] ?? '') !== (string) ($dispatch['token'] ?? '')
            ) {
                return null;
            }

            // Clear ephemeral per-item overlays then apply JIT settings.
            foreach ([
                'rerun',
                'rerun_scope',
                'rerun_from_step',
                'rerun_include_downstream',
                'resume_partial_split',
                'resume_prior_run_item_id',
                'resume_split_progress',
                'generation_mode',
                'generation_keyword_override',
            ] as $key) {
                unset($settings[$key]);
            }

            foreach ($decision->executionSettings as $key => $value) {
                if ($value === null) {
                    unset($settings[$key]);
                } else {
                    $settings[$key] = $value;
                }
            }

            $active['operation'] = $decision->operation;
            $active['operation_reason'] = $decision->reason;
            $active['current_step'] = 'jit_'.$decision->operation;
            $engine['active_dispatch'] = $active;
            $engine['item_operation'] = $decision->toArray();
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update(['settings' => $settings]);

            if (($decision->meta['needs_workspace_reset'] ?? false) === true) {
                $task = SeoProjectTask::query()->find((int) $dispatch['task_id']);
                if ($task instanceof SeoProjectTask) {
                    app(ContentProjectFreshKeywordWorkspaceResetService::class)->resetForTask($task);
                }
            }

            return [
                'run_id' => (int) $locked->id,
                'task_id' => (int) $dispatch['task_id'],
                'run_item_id' => (int) $dispatch['run_item_id'],
                'attempt' => (int) $dispatch['attempt'],
                'token' => (string) $dispatch['token'],
                'operation' => $decision->operation,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $dispatch
     */
    private function enqueueArticleJob(SeoProjectRun $run, array $dispatch): void
    {
        $run->refresh();
        $settings = is_array($run->settings) ? $run->settings : [];
        $sync = (bool) ($settings['rerun_sync'] ?? false);

        if ($sync) {
            RunContentProjectArticleJob::dispatchSync(
                runId: $dispatch['run_id'],
                taskId: $dispatch['task_id'],
                runItemId: $dispatch['run_item_id'],
                attempt: $dispatch['attempt'],
                dispatchToken: $dispatch['token'],
            );
        } else {
            $pending = RunContentProjectArticleJob::dispatch(
                runId: $dispatch['run_id'],
                taskId: $dispatch['task_id'],
                runItemId: $dispatch['run_item_id'],
                attempt: $dispatch['attempt'],
                dispatchToken: $dispatch['token'],
            )->onQueue(ContentProjectRunEngineFeature::queueName());

            if (! app()->runningInConsole()) {
                $pending->afterResponse();
            }
        }

        RuntimeLogger::info('content_project_run.article_dispatched', $dispatch + [
            'feature_flag' => ContentProjectRunEngineFeature::enabledFor($run->fresh() ?? $run),
            'sync' => $sync,
        ]);
    }

    /**
     * Orchestration corruption — fail closed: keep completed items, leave unvisited untouched.
     */
    public function failClosedBulk(SeoProjectRun $run, string $reason): void
    {
        RuntimeLogger::error('content_project_run.fail_closed_bulk', [
            'run_id' => (int) $run->id,
            'reason' => $reason,
        ]);

        DB::connection('omi_seo_ai')->transaction(function () use ($run, $reason): void {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            unset($engine['active_dispatch']);
            $engine['fail_closed'] = [
                'reason' => $reason,
                'at' => now()->toIso8601String(),
            ];
            $engine['final_status'] = 'failed_fail_closed';
            $engine['intentional_unvisited_pending'] = true;
            $engine['stop_reason'] = 'Fail-closed orchestration: '.$reason;
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;

            // Leave pending run-items pending (unvisited) — do not mutate task lifecycle.
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Failed),
                'finished_at' => now(),
                'settings' => $settings,
            ]);
        });

        $fresh = $run->fresh() ?? $run;
        $this->events->runFailed($fresh, $reason);
    }

    public function handleArticleFinished(
        SeoProjectRun $run,
        ArticleExecutionResult $result,
        ?string $dispatchToken = null,
    ): void {
        $started = microtime(true);
        $run->refresh();

        $token = $dispatchToken;
        if ($token === null || $token === '') {
            $payloadToken = $result->payload['dispatch_token'] ?? null;
            $token = is_string($payloadToken) && $payloadToken !== '' ? $payloadToken : null;
        }

        if (! $this->ownsCurrentDispatch($run, $result->taskId, $result->runItemId, $token)) {
            RuntimeLogger::info('content_project_run.stale_result_ignored', [
                'run_id' => (int) $run->id,
                'task_id' => $result->taskId,
                'run_item_id' => $result->runItemId,
                'reason' => 'dispatch_ownership_revoked',
                'has_token' => $token !== null,
            ]);

            return;
        }

        $this->clearActiveDispatch($run, $result->taskId, $result->runItemId, $token);

        match ($result->status) {
            ContentProjectArticleSemanticStatus::Completed,
            ContentProjectArticleSemanticStatus::Skipped => $this->events->articleCompleted($run, $result),
            ContentProjectArticleSemanticStatus::Cancelled => $this->events->articleCancelled($run, $result),
            ContentProjectArticleSemanticStatus::Pending => null,
            default => $this->events->articleFailed($run, $result),
        };

        RuntimeLogger::info('content_project_run.article_'.$result->status->value, [
            'run_id' => $result->runId,
            'task_id' => $result->taskId,
            'run_item_id' => $result->runItemId,
            'article_id' => $result->articleId,
            'message' => $result->message,
            'error_code' => $result->errorCode,
            'may_dispatch_next' => $result->mayDispatchNext(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        if ($this->scheduleTransientAiRetryIfNeeded($run, $result)) {
            $run = $this->runItemService->syncMirrorAndCounters($run, false);
            $this->events->runProgressUpdated($run);

            return;
        }

        $run = $this->runItemService->syncMirrorAndCounters($run, false);
        $this->events->runProgressUpdated($run);

        if ($this->recordConsecutiveFailureAndMaybeTrip($run, $result)) {
            return;
        }

        if ($this->cancellationGuard->isStopRequested($run) || ! $result->mayDispatchNext()) {
            RuntimeLogger::info('content_project_run.next_dispatch_skipped', [
                'run_id' => (int) $run->id,
                'reason' => $this->cancellationGuard->isStopRequested($run) ? 'stop_requested' : 'result_forbids_next',
                'task_id' => $result->taskId,
            ]);
            $this->finalizeIfDone($run);

            return;
        }

        if ($this->cancellationGuard->isTerminal($run)) {
            return;
        }

        $this->dispatchNextArticle($run);
    }

    /**
     * Deferred transient AI exhaustion: reset item pending, bump attempt, delayed re-dispatch.
     * Does not trip circuit breaker and does not advance to the next article.
     */
    private function scheduleTransientAiRetryIfNeeded(SeoProjectRun $run, ArticleExecutionResult $result): bool
    {
        if (! ContentProjectTransientAiRetryPolicy::isTransientResult($result)) {
            return false;
        }
        if ($this->cancellationGuard->isStopRequested($run) || $this->cancellationGuard->isTerminal($run)) {
            return false;
        }
        if ($this->isCircuitBreakerStopped($run)) {
            return false;
        }

        $runItemId = (int) ($result->runItemId ?? 0);
        if ($runItemId <= 0) {
            return false;
        }

        $item = SeoProjectRunItem::query()->find($runItemId);
        if (! $item instanceof SeoProjectRunItem) {
            return false;
        }

        $currentAttempt = max(1, (int) ($result->payload['current_attempt'] ?? $item->attempt ?? 1));
        $nextAttempt = max(1, (int) ($result->payload['next_attempt'] ?? ($currentAttempt + 1)));
        if ($currentAttempt >= ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS) {
            return false;
        }

        $delay = ContentProjectTransientAiRetryPolicy::delaySeconds(
            $nextAttempt,
            isset($result->payload['retry_after_seconds']) ? (int) $result->payload['retry_after_seconds'] : null,
        );

        $dispatch = DB::connection('omi_seo_ai')->transaction(function () use ($run, $item, $nextAttempt, $delay, $result): ?array {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return null;
            }
            if ($this->cancellationGuard->isStopRequested($locked) || $this->cancellationGuard->isTerminal($locked)) {
                return null;
            }

            /** @var SeoProjectRunItem|null $lockedItem */
            $lockedItem = SeoProjectRunItem::query()
                ->whereKey((int) $item->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedItem instanceof SeoProjectRunItem) {
                return null;
            }

            $lockedItem->update([
                'status' => SeoProjectRunItemStatus::Pending->value,
                'attempt' => $nextAttempt,
                'started_at' => null,
                'finished_at' => null,
                'message' => ContentProjectTransientAiRetryPolicy::deferMessage($delay),
                'error_message' => null,
            ]);

            $dispatchToken = hash('sha256', implode('|', [
                (int) $locked->id,
                (int) $lockedItem->id,
                $nextAttempt,
                (string) microtime(true),
            ]));

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            unset($engine['active_dispatch']);
            $failedHook = trim((string) ($result->payload['failed_hook'] ?? ''));
            $engine[ContentProjectTransientAiRetryPolicy::SETTINGS_KEY] = array_filter([
                'run_item_id' => (int) $lockedItem->id,
                'task_id' => (int) $lockedItem->task_id,
                'attempt' => $nextAttempt,
                'delay_seconds' => $delay,
                'scheduled_at' => now()->toIso8601String(),
                'exhaustion_kind' => $result->payload['exhaustion_kind'] ?? null,
                'failed_hook' => $failedHook !== '' ? $failedHook : null,
            ], static fn (mixed $v): bool => $v !== null && $v !== '');
            $engine['active_dispatch'] = [
                'task_id' => (int) $lockedItem->task_id,
                'run_item_id' => (int) $lockedItem->id,
                'article_id' => $lockedItem->article_id !== null ? (int) $lockedItem->article_id : null,
                'attempt' => $nextAttempt,
                'token' => $dispatchToken,
                'dispatched_at' => now()->toIso8601String(),
                'last_heartbeat_at' => now()->toIso8601String(),
                'claimed_at' => null,
                // Prefer the failed hook so UI can show Outline/Vocabulary while waiting.
                'current_step' => $failedHook !== '' ? $failedHook : 'ai_retry_delayed',
            ];
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update(['settings' => $settings]);

            return [
                'run_id' => (int) $locked->id,
                'task_id' => (int) $lockedItem->task_id,
                'run_item_id' => (int) $lockedItem->id,
                'attempt' => $nextAttempt,
                'token' => $dispatchToken,
                'delay' => $delay,
            ];
        });

        if ($dispatch === null) {
            return false;
        }

        $pending = RunContentProjectArticleJob::dispatch(
            runId: $dispatch['run_id'],
            taskId: $dispatch['task_id'],
            runItemId: $dispatch['run_item_id'],
            attempt: $dispatch['attempt'],
            dispatchToken: $dispatch['token'],
        )->onQueue(ContentProjectRunEngineFeature::queueName())
            ->delay(now()->addSeconds((int) $dispatch['delay']));

        if (! app()->runningInConsole()) {
            $pending->afterResponse();
        }

        RuntimeLogger::info('content_project.ai_retry_scheduled', [
            'run_id' => $dispatch['run_id'],
            'task_id' => $dispatch['task_id'],
            'run_item_id' => $dispatch['run_item_id'],
            'current_attempt' => $currentAttempt,
            'next_attempt' => $dispatch['attempt'],
            'delay_seconds' => $dispatch['delay'],
        ]);

        return true;
    }

    /**
     * @return bool true when batch was stopped by circuit breaker
     */
    private function recordConsecutiveFailureAndMaybeTrip(SeoProjectRun $run, ArticleExecutionResult $result): bool
    {
        if ($result->isSuccess() || $result->isCancelled()) {
            $this->resetConsecutiveFailure($run);

            return false;
        }

        if (! $result->isFailed()) {
            return false;
        }

        // Deferred / pending transient AI must never count toward the breaker.
        if (ContentProjectTransientAiRetryPolicy::isTransientResult($result)) {
            return false;
        }

        $signature = ContentProjectBatchFailureSignature::fromResult($result);
        $tripped = false;
        $count = 0;

        DB::connection('omi_seo_ai')->transaction(function () use ($run, $signature, &$tripped, &$count): void {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $recorded = ContentProjectBatchCircuitBreakerState::recordFailure($engine, $signature);
            $engine = $recorded['engine'];
            $count = $recorded['count'];
            $tripped = $recorded['tripped'];

            if ($tripped) {
                $message = $this->circuitBreakerUserMessage($signature);
                $engine['circuit_breaker'] = [
                    'stopped' => true,
                    'signature' => $signature,
                    'count' => $count,
                    'stopped_at' => now()->toIso8601String(),
                    'reason' => $message,
                ];
                $engine = ContentProjectRunRecoverableState::stampCircuitBreaker(
                    $engine,
                    $message,
                    $signature,
                    $count,
                );
                $engine['stop_reason'] = $message;
                $engine['finalized_at'] = now()->toIso8601String();
                $engine['final_status'] = 'failed_circuit_breaker';
                // Remaining items stay Pending so the run can be resumed. Clearing the
                // reservation is what keeps the ops UI from showing them as running —
                // ContentProjectArticleRuntimeStatusResolver requires a matching dispatch.
                unset($engine['active_dispatch']);
                $settings[self::SETTINGS_ENGINE_KEY] = $engine;
                $locked->update([
                    'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Failed),
                    'finished_at' => now(),
                    'settings' => $settings,
                ]);

                return;
            }

            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update(['settings' => $settings]);
        });

        $run->refresh();

        if (! $tripped) {
            return false;
        }

        $message = $this->circuitBreakerUserMessage($signature);
        RuntimeLogger::warning('content_project_run.circuit_breaker_tripped', [
            'run_id' => (int) $run->id,
            'signature' => $signature,
            'count' => $count,
            'message' => $message,
        ]);
        $this->events->runFailed($run, $message);
        $this->logRunMetrics($run, 'failed_circuit_breaker');

        return true;
    }

    private function resetConsecutiveFailure(SeoProjectRun $run): void
    {
        DB::connection('omi_seo_ai')->transaction(function () use ($run): void {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $settings[self::SETTINGS_ENGINE_KEY] = ContentProjectBatchCircuitBreakerState::recordSuccess($engine);
            $locked->update(['settings' => $settings]);
        });
        $run->refresh();
    }

    public function isCircuitBreakerStopped(SeoProjectRun $run): bool
    {
        return ContentProjectBatchCircuitBreakerState::isStopped($this->engineBag($run));
    }

    /**
     * Queued job arrived after circuit breaker — release reservation, keep item Pending.
     * If the item was falsely claimed as Processing without a real in-flight worker, reconcile to Pending.
     */
    public function releaseSkippedDispatch(SeoProjectRun $run, int $runItemId, string $dispatchToken): void
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active !== null
            && (int) ($active['run_item_id'] ?? 0) === $runItemId
            && (string) ($active['token'] ?? '') === $dispatchToken
        ) {
            unset($engine['active_dispatch']);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $run->update(['settings' => $settings]);
            $run->refresh();
        }

        $item = SeoProjectRunItem::query()->find($runItemId);
        if ($item instanceof SeoProjectRunItem
            && (int) $item->run_id === (int) $run->id
            && (string) $item->status === SeoProjectRunItemStatus::Processing->value
        ) {
            $item->update([
                'status' => SeoProjectRunItemStatus::Pending->value,
                'started_at' => null,
                'finished_at' => null,
                'message' => 'Deferred: batch circuit breaker stopped further articles.',
            ]);
        }
    }

    private function circuitBreakerUserMessage(string $signature): string
    {
        if ($signature === ContentProjectBatchFailureSignature::SYSTEMIC_ROUTING
            || str_starts_with($signature, 'ai_routing|')
        ) {
            return 'Đã dừng Generate: hết AI route hợp lệ 3 lần liên tiếp (systemic routing).';
        }

        $parts = explode('|', $signature);
        $node = $parts[0] ?? 'article';
        $classification = $parts[1] ?? 'error';
        $provider = $parts[2] ?? '';

        $detail = match (true) {
            $classification === 'empty_response' && $provider !== '' => ucfirst($provider).' · empty response',
            $classification === 'empty_response' => 'empty response',
            $provider !== '' => ucfirst($provider).' · '.$classification,
            default => $classification,
        };

        $nodeLabel = str_contains($node, 'outline') ? 'Outline' : ucfirst(str_replace('_', ' ', $node));

        return 'Đã dừng Generate: '.$nodeLabel.' gặp cùng lỗi 3 lần liên tiếp. '.$detail;
    }

    public function finalizeIfDone(SeoProjectRun $run): void
    {
        $outcome = DB::connection('omi_seo_ai')->transaction(function () use ($run): ?string {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof SeoProjectRun) {
                return null;
            }

            $this->sweepStaleActiveDispatch($locked);
            $locked->refresh();

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];

            if (! empty($engine['finalized_at'])) {
                return 'already_finalized';
            }

            $status = $this->statusMapper->runFromDb((string) $locked->status);
            if ($status === ContentProjectRunSemanticStatus::Completed
                || $status === ContentProjectRunSemanticStatus::Cancelled
                || $status === ContentProjectRunSemanticStatus::Failed
            ) {
                return 'already_terminal';
            }

            $pendingOrRunning = SeoProjectRunItem::query()
                ->where('run_id', (int) $locked->id)
                ->articleExecution()
                ->whereIn('status', [
                    SeoProjectRunItemStatus::Pending->value,
                    SeoProjectRunItemStatus::Processing->value,
                ])
                ->count();

            if ($status === ContentProjectRunSemanticStatus::Stopping
                || $status === ContentProjectRunSemanticStatus::Cancelled
            ) {
                if ($this->activeProcessingCount($locked) > 0 || $this->hasBlockingActiveDispatch($locked)) {
                    return 'wait_active';
                }

                // Unvisited pending membership stays pending — stop/cancel is not a failure.
                $this->clearActiveDispatch($locked, null, null);

                $locked = $this->runItemService->syncMirrorAndCounters($locked, false);
                $previous = (string) $locked->status;
                $engine = $this->engineBag($locked);
                $engine['finalized_at'] = now()->toIso8601String();
                $engine['final_status'] = 'cancelled';
                $engine['intentional_unvisited_pending'] = true;
                $settings = is_array($locked->settings) ? $locked->settings : [];
                $settings[self::SETTINGS_ENGINE_KEY] = $engine;

                $locked->update([
                    'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Cancelled),
                    'finished_at' => now(),
                    'settings' => $settings,
                ]);

                RuntimeLogger::info('content_project_run.transition', [
                    'run_id' => (int) $locked->id,
                    'before' => $previous,
                    'after' => 'cancelled',
                    'decision' => 'finalize',
                    'reason' => $this->stopReason($locked),
                ]);

                return 'cancelled';
            }

            if ($pendingOrRunning > 0) {
                return 'wait_pending';
            }

            if ($status->isTerminal()) {
                return 'already_terminal';
            }

            // Mark finalize claim BEFORE completeRunQueue to block concurrent callers.
            $engine['finalized_at'] = now()->toIso8601String();
            $engine['final_status'] = 'completed';
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update(['settings' => $settings]);

            return 'complete';
        });

        if ($outcome === null || $outcome === 'wait_active' || $outcome === 'wait_pending') {
            return;
        }

        $run->refresh();

        if ($outcome === 'already_finalized' || $outcome === 'already_terminal') {
            $this->normalizeTerminalHelperRows($run);

            return;
        }

        if ($outcome === 'cancelled') {
            $this->normalizeTerminalHelperRows($run);
            $reason = $this->stopReason($run);
            $this->events->runCancelled($run, $reason);
            $this->logRunMetrics($run, 'cancelled');
            RuntimeLogger::info('content_project_run.finalized', [
                'run_id' => (int) $run->id,
                'final_status' => 'cancelled',
                'reason' => $reason,
                'decision' => 'cancelled',
            ]);

            return;
        }

        // complete path (outside long provider — completeRunQueue is DB/bookkeeping)
        $previous = (string) $run->status;
        try {
            $completed = $this->workflowRunService->completeRunQueue($run);
        } catch (\Throwable $exception) {
            $this->clearFinalizeStamp($run);
            RuntimeLogger::error('content_project_run.finalized', [
                'run_id' => (int) $run->id,
                'decision' => 'complete_failed',
                'reason' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        $this->normalizeTerminalHelperRows($completed);
        $this->events->runCompleted($completed);
        $this->logRunMetrics($completed, 'completed');

        RuntimeLogger::info('content_project_run.transition', [
            'run_id' => (int) $completed->id,
            'before' => $previous,
            'after' => (string) $completed->status,
            'decision' => 'finalize',
            'reason' => 'all_articles_terminal',
        ]);
        RuntimeLogger::info('content_project_run.finalized', [
            'run_id' => (int) $completed->id,
            'final_status' => 'completed',
            'succeeded' => (int) $completed->succeeded,
            'failed' => (int) $completed->failed,
            'total' => (int) $completed->total,
            'decision' => 'completed',
        ]);
    }

    /**
     * Job heartbeat — short settings write; never hold lock across provider.
     */
    public function touchHeartbeat(
        SeoProjectRun $run,
        int $runItemId,
        string $token,
        ?string $currentStep = null,
    ): void {
        DB::connection('omi_seo_ai')->transaction(function () use ($run, $runItemId, $token, $currentStep): void {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            if ($active === null) {
                return;
            }
            if ((string) ($active['token'] ?? '') !== $token
                || (int) ($active['run_item_id'] ?? 0) !== $runItemId
            ) {
                return;
            }

            $active['last_heartbeat_at'] = now()->toIso8601String();
            if ($currentStep !== null && $currentStep !== '') {
                $active['current_step'] = $currentStep;
            }
            if (empty($active['claimed_at']) && $currentStep === 'claimed') {
                $active['claimed_at'] = now()->toIso8601String();
            }
            $engine['active_dispatch'] = $active;
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update(['settings' => $settings]);
        });
    }

    public function healthCheck(SeoProjectRun $run): ContentProjectRunHealthReport
    {
        $run->refresh();
        $warnings = [];
        $errors = [];
        $details = [];

        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        $status = $this->statusMapper->runFromDb((string) $run->status);

        $processingItems = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->where('status', SeoProjectRunItemStatus::Processing->value)
            ->get(['id', 'task_id', 'article_id', 'status', 'updated_at']);

        $details['processing_count'] = $processingItems->count();
        $details['active_dispatch'] = $active;
        $details['semantic_status'] = $status->value;

        if ($processingItems->count() > 1) {
            $errors[] = 'duplicated_active_article';
            $details['processing_ids'] = $processingItems->pluck('id')->all();
        }

        if ($active !== null && $processingItems->count() > 1) {
            $errors[] = 'active_dispatch_with_multi_processing';
            // Ops UI can only mark one row as genuinely running — surface the mismatch.
            RuntimeLogger::warning(ContentProjectArticleRuntimeStatusResolver::LOG_INCONSISTENT, [
                'run_id' => (int) $run->id,
                'processing_count' => $processingItems->count(),
                'processing_ids' => $processingItems->pluck('id')->all(),
                'active_run_item_id' => (int) ($active['run_item_id'] ?? 0),
            ]);
        }

        if ($active !== null) {
            $ages = $this->dispatchAges($active);
            $details['dispatch_age_seconds'] = $ages['dispatch_age_seconds'];
            $details['heartbeat_age_seconds'] = $ages['heartbeat_age_seconds'];
            $details['heartbeat_alive'] = $ages['heartbeat_alive'];
            $details['dispatch_ttl_expired'] = $ages['dispatch_ttl_expired'];

            if ($ages['heartbeat_stale_warn']) {
                if ($processingItems->isNotEmpty()) {
                    $warnings[] = 'heartbeat_stale_but_processing_active';
                    $details['heartbeat_note'] = 'Heartbeat stale nhưng article item vẫn processing — KHÔNG coi worker chết; KHÔNG auto-release active_dispatch.';
                } else {
                    $warnings[] = 'heartbeat_stale';
                    $details['heartbeat_note'] = 'Heartbeat stale và không có processing row — kiểm tra worker; chỉ release khi TTL cũng hết (xem recover).';
                }
            }

            if ($ages['dispatch_ttl_expired'] && ! $ages['heartbeat_alive'] && $processingItems->isEmpty()) {
                $warnings[] = 'stale_dispatch_releasable';
            } elseif ($ages['dispatch_ttl_expired'] && ! $ages['heartbeat_alive'] && $processingItems->isNotEmpty()) {
                $warnings[] = 'ttl_expired_but_processing_keeps_dispatch';
                $details['release_blocked_reason'] = 'TTL hết nhưng còn processing — giữ active_dispatch chống duplicate.';
            }

            if (($ages['worker_death_expired'] ?? false) && $processingItems->isNotEmpty()) {
                $warnings[] = 'worker_death_lease_expired';
                $details['worker_death_note'] = 'Hard lease expired — watchdog có thể declare WORKER_LOST (không auto-dispatch next).';
            }

            $activeItemId = (int) ($active['run_item_id'] ?? 0);
            $activeItem = $activeItemId > 0 ? SeoProjectRunItem::query()->find($activeItemId) : null;
            if ($activeItem instanceof SeoProjectRunItem
                && ! in_array((string) $activeItem->status, [
                    SeoProjectRunItemStatus::Pending->value,
                    SeoProjectRunItemStatus::Processing->value,
                ], true)
            ) {
                $warnings[] = 'active_dispatch_points_terminal_item';
            }
        }

        foreach ($processingItems as $item) {
            $activeId = (int) ($active['run_item_id'] ?? 0);
            if ($active === null || $activeId !== (int) $item->id) {
                $warnings[] = 'orphan_processing_row:'.$item->id;
            }
        }

        if ($status === ContentProjectRunSemanticStatus::Stopping
            && $processingItems->isEmpty()
            && $active === null
        ) {
            $pending = SeoProjectRunItem::query()
                ->where('run_id', (int) $run->id)
                ->articleExecution()
                ->where('status', SeoProjectRunItemStatus::Pending->value)
                ->count();
            if ($pending === 0 && empty($engine['finalized_at'])) {
                $warnings[] = 'stopping_mismatch_should_finalize';
            }
        }

        if ($status->isTerminal() && $processingItems->isNotEmpty()) {
            $errors[] = 'terminal_mismatch_processing_rows';
        }

        if ($status->isTerminal() && $active !== null) {
            $warnings[] = 'terminal_with_active_dispatch';
        }

        $pendingArticleItems = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->where('status', SeoProjectRunItemStatus::Pending->value)
            ->get(['id', 'action', 'status', 'task_id']);

        $pendingHelperItems = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->helperOrControl()
            ->whereIn('status', [
                SeoProjectRunItemStatus::Pending->value,
                SeoProjectRunItemStatus::Processing->value,
            ])
            ->get(['id', 'action', 'status', 'task_id']);

        $details['pending_article_items'] = $pendingArticleItems->map(static fn (SeoProjectRunItem $row): array => [
            'id' => (int) $row->id,
            'action' => (string) $row->action,
            'kind' => $row->kind()->value,
            'status' => (string) $row->status,
            'task_id' => (int) ($row->task_id ?? 0),
        ])->all();
        $details['pending_helper_items'] = $pendingHelperItems->map(static fn (SeoProjectRunItem $row): array => [
            'id' => (int) $row->id,
            'action' => (string) $row->action,
            'kind' => $row->kind()->value,
            'status' => (string) $row->status,
            'task_id' => (int) ($row->task_id ?? 0),
        ])->all();

        if ($status->isTerminal() && $pendingArticleItems->isNotEmpty()) {
            if ($this->hasIntentionalUnvisitedPending($engine, $status)) {
                $details['intentional_unvisited_pending'] = true;
                $details['pending_note'] = 'Pending article membership is intentional (stop/cancel/circuit_breaker/worker_lost) — not orchestration corruption.';
            } else {
                $errors[] = 'run_terminal_with_pending_article_items';
            }
        }

        if ($status->isTerminal() && $pendingHelperItems->isNotEmpty()) {
            $warnings[] = 'run_terminal_with_pending_helper_items';
            $details['helper_note'] = 'Helper/step còn pending|processing — không suy UI running; dùng recover --action=normalize-terminal-helpers.';
        }

        $recoverableReason = ContentProjectRunRecoverableState::reasonFromEngine($engine);
        if ($recoverableReason !== null) {
            $details['recoverable_reason'] = $recoverableReason;
            $details['recoverable_message'] = ContentProjectRunRecoverableState::userVisibleMessage($engine);
        }

        return new ContentProjectRunHealthReport(
            runId: (int) $run->id,
            ok: $errors === [],
            warnings: array_values(array_unique($warnings)),
            errors: array_values(array_unique($errors)),
            details: $details,
        );
    }

    /**
     * @param  array<string, mixed>  $engine
     */
    private function hasIntentionalUnvisitedPending(array $engine, ContentProjectRunSemanticStatus $status): bool
    {
        if (! empty($engine['intentional_unvisited_pending'])) {
            return true;
        }

        if (ContentProjectRunRecoverableState::isRecoverableEngine($engine)) {
            return true;
        }

        $finalStatus = (string) ($engine['final_status'] ?? '');
        if (in_array($finalStatus, [
            'cancelled',
            'failed_circuit_breaker',
            'failed_worker_lost',
            'failed_fail_closed',
        ], true)) {
            return true;
        }

        return $status === ContentProjectRunSemanticStatus::Cancelled;
    }

    /**
     * Operator recovery inspect (no writes). Used by seo:content-project-run:recover.
     *
     * @return array<string, mixed>
     */
    public function recoveryPlan(SeoProjectRun $run): array
    {
        $run->refresh();
        $health = $this->healthCheck($run);
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        $ages = is_array($active) ? $this->dispatchAges($active) : null;
        $processing = $this->activeProcessingCount($run);
        $status = $this->statusMapper->runFromDb((string) $run->status);

        $pendingArticleIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            is_array($health->details['pending_article_items'] ?? null)
                ? $health->details['pending_article_items']
                : [],
        );
        $pendingHelperIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            is_array($health->details['pending_helper_items'] ?? null)
                ? $health->details['pending_helper_items']
                : [],
        );

        $eligible = false;
        $eligibleNormalizeHelpers = false;
        $recommended = 'noop';
        $blockers = [];

        if ($status->isTerminal()) {
            $blockers[] = 'run_terminal';
            if ($pendingArticleIds !== [] && ! $this->hasIntentionalUnvisitedPending($engine, $status)) {
                $recommended = 'inspect_pending_article_items';
                $blockers[] = 'pending_article_items';
            } elseif ($pendingArticleIds !== [] && $this->hasIntentionalUnvisitedPending($engine, $status)) {
                $recommended = 'noop_intentional_unvisited_pending';
            } elseif ($pendingHelperIds !== [] && $active === null && $processing === 0) {
                $recommended = 'normalize_terminal_helper_rows';
                $eligibleNormalizeHelpers = true;
            } else {
                $recommended = 'noop_terminal';
            }
        } elseif ($active === null) {
            if ($status === ContentProjectRunSemanticStatus::Stopping && $processing === 0) {
                $recommended = 'call_finalize_or_request_stop_again';
            } else {
                $recommended = 'noop_no_active_dispatch';
            }
            $blockers[] = 'no_active_dispatch';
        } else {
            $workerDeath = $ages !== null && ($ages['worker_death_expired'] ?? false);
            if ($processing > 0 && $workerDeath) {
                $eligible = true;
                $recommended = 'declare_worker_lost';
            } else {
                if ($processing > 0) {
                    $blockers[] = 'processing_rows_present';
                }
                if ($ages !== null && ! $ages['dispatch_ttl_expired']) {
                    $blockers[] = 'ttl_not_expired';
                }
                if ($ages !== null && $ages['heartbeat_alive']) {
                    $blockers[] = 'heartbeat_still_alive';
                }

                $eligible = $processing === 0
                    && $ages !== null
                    && $ages['dispatch_ttl_expired']
                    && ! $ages['heartbeat_alive']
                    && ! $status->isTerminal();

                $recommended = $eligible
                    ? 'release_stale_active_dispatch'
                    : 'wait_or_inspect_worker';
            }
        }

        return [
            'run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'semantic_status' => $status->value,
            'active_dispatch' => $active,
            'dispatch_age_seconds' => $ages['dispatch_age_seconds'] ?? null,
            'heartbeat_age_seconds' => $ages['heartbeat_age_seconds'] ?? null,
            'worker_death_expired' => $ages['worker_death_expired'] ?? false,
            'processing_count' => $processing,
            'pending_article_items' => $pendingArticleIds,
            'pending_helper_items' => $pendingHelperIds,
            'token' => is_array($active) ? ($active['token'] ?? null) : null,
            'eligible_for_stale_release' => $eligible && $recommended === 'release_stale_active_dispatch',
            'eligible_for_worker_lost' => $recommended === 'declare_worker_lost',
            'eligible_for_normalize_terminal_helpers' => $eligibleNormalizeHelpers,
            'recommended_action' => $recommended,
            'blockers' => $blockers,
            'health' => $health->toArray(),
            'recoverable_reason' => ContentProjectRunRecoverableState::reasonFromEngine($engine),
            'recoverable_message' => ContentProjectRunRecoverableState::userVisibleMessage($engine),
            'apply_requires' => [
                'stale_release' => [
                    'ttl_expired',
                    'heartbeat_dead',
                    'processing_count_0',
                    'run_not_terminal',
                    'token_match_inspected',
                ],
                'worker_lost' => [
                    'hard_lease_expired',
                    'item_still_processing',
                    'same_dispatch_token',
                    'run_non_terminal',
                ],
                'normalize_terminal_helpers' => [
                    'run_terminal',
                    'active_dispatch_null',
                    'processing_article_count_0',
                    'pending_helper_items_non_empty',
                    'no_pending_article_items',
                ],
            ],
            'notes' => [
                'Dry-run mặc định — không ghi DB.',
                'Không reset failed→pending.',
                'Không resume cancelled.',
                'Không clear queue toàn hệ thống.',
                'Không đổi article pending → success.',
                'Normalize helper dùng status skipped (terminal-neutral).',
                'Heartbeat stale + processing = warning only until hard worker-death lease expires.',
                'WORKER_LOST does not auto-dispatch the next article.',
            ],
        ];
    }

    /**
     * Backend watchdog entry — never calls AI providers.
     * Scans non-terminal PHP-engine runs with expired hard leases.
     *
     * @return array{
     *     scanned: int,
     *     recovered: int,
     *     cancelled: int,
     *     run_ids: list<int>,
     *     cancelled_run_ids: list<int>
     * }
     */
    public function recoverLostWorkers(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $runs = SeoProjectRun::query()
            ->whereIn('status', [
                SeoProjectRun::STATUS_RUNNING,
                SeoProjectRun::STATUS_STOPPING,
            ])
            ->whereNull('finished_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $workerLost = [];
        $cancelled = [];
        foreach ($runs as $run) {
            if (! $run instanceof SeoProjectRun) {
                continue;
            }
            if (! ContentProjectRunEngineFeature::enabledFor($run)) {
                continue;
            }

            $outcome = $this->recoverDeadDispatchIfConfirmed($run);
            if ($outcome === 'worker_lost') {
                $workerLost[] = (int) $run->id;
            } elseif ($outcome === 'cancelled') {
                $cancelled[] = (int) $run->id;
            }
        }

        return [
            'scanned' => $runs->count(),
            'recovered' => count($workerLost),
            'cancelled' => count($cancelled),
            'run_ids' => $workerLost,
            'cancelled_run_ids' => $cancelled,
        ];
    }

    /**
     * Confirmed hard-lease death:
     * - running → WORKER_LOST (recoverable)
     * - stopping → cancelled (user stop wins; never WORKER_LOST)
     * Does NOT dispatch the next article.
     *
     * @return 'none'|'worker_lost'|'cancelled'
     */
    public function recoverDeadDispatchIfConfirmed(SeoProjectRun $run): string
    {
        $run->refresh();
        $status = $this->statusMapper->runFromDb((string) $run->status);
        if ($status === ContentProjectRunSemanticStatus::Stopping) {
            return $this->finalizeStoppingAfterDeadWorker($run) ? 'cancelled' : 'none';
        }
        if ($status === ContentProjectRunSemanticStatus::Running) {
            return $this->declareWorkerLostIfConfirmed($run) ? 'worker_lost' : 'none';
        }

        return 'none';
    }

    /**
     * Atomic WORKER_LOST transition when hard lease proves the worker is dead while RUNNING.
     * Does NOT apply to STOPPING (user stop ≠ infrastructure loss).
     * Does NOT dispatch the next article.
     */
    public function declareWorkerLostIfConfirmed(SeoProjectRun $run): bool
    {
        $applied = DB::connection('omi_seo_ai')->transaction(function () use ($run): bool {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return false;
            }

            $status = $this->statusMapper->runFromDb((string) $locked->status);
            // STOPPING must never become WORKER_LOST — use finalizeStoppingAfterDeadWorker.
            if ($status !== ContentProjectRunSemanticStatus::Running) {
                return false;
            }

            $verified = $this->verifyDeadDispatchOwnership($locked);
            if ($verified === null) {
                return false;
            }

            /** @var SeoProjectRunItem $item */
            $item = $verified['item'];
            /** @var array<string, mixed> $activeAgain */
            $activeAgain = $verified['active'];
            /** @var array<string, mixed> $engineAgain */
            $engineAgain = $verified['engine'];
            /** @var array<string, mixed> $settings */
            $settings = $verified['settings'];
            $ages = $verified['ages'];
            $runItemId = (int) $item->id;

            $attempt = max(1, (int) ($activeAgain['attempt'] ?? $item->attempt ?? 1));
            $maxAttempts = ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS;
            $message = ContentProjectRunRecoverableState::workerLostItemMessage(
                (int) $item->task_id,
                $attempt,
                $maxAttempts,
            );

            $item->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'attempt' => $attempt,
                'message' => $message,
                'error_message' => ContentProjectRunRecoverableState::ERROR_CODE_WORKER_LOST.': '.$message,
                'finished_at' => now(),
            ]);

            $engine = ContentProjectRunRecoverableState::stampWorkerLost($engineAgain, $activeAgain, $message);
            unset($engine['active_dispatch']);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;

            $previous = (string) $locked->status;
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Failed),
                'finished_at' => now(),
                'settings' => $settings,
            ]);

            RuntimeLogger::warning('content_project_run.worker_lost', [
                'run_id' => (int) $locked->id,
                'run_item_id' => $runItemId,
                'task_id' => (int) $item->task_id,
                'attempt' => $attempt,
                'lease_age_seconds' => $ages['worker_death_age_seconds'],
                'before' => $previous,
                'after' => 'failed',
                'decision' => 'worker_lost',
            ]);

            return true;
        });

        if (! $applied) {
            return false;
        }

        $fresh = $run->fresh() ?? $run;
        $this->runItemService->syncMirrorAndCounters($fresh, false);
        $message = $this->stopReason($fresh) ?? 'WORKER_LOST';
        $this->events->runFailed($fresh, $message);
        $this->logRunMetrics($fresh, 'failed_worker_lost');

        return true;
    }

    /**
     * STOPPING + hard lease expired: finalize as cancelled (user stop wins).
     * Never stamps recoverable WORKER_LOST. Never dispatches next article.
     */
    public function finalizeStoppingAfterDeadWorker(SeoProjectRun $run): bool
    {
        $applied = DB::connection('omi_seo_ai')->transaction(function () use ($run): bool {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return false;
            }

            $status = $this->statusMapper->runFromDb((string) $locked->status);
            if ($status !== ContentProjectRunSemanticStatus::Stopping) {
                return false;
            }

            $verified = $this->verifyDeadDispatchOwnership($locked);
            if ($verified === null) {
                return false;
            }

            /** @var SeoProjectRunItem $item */
            $item = $verified['item'];
            /** @var array<string, mixed> $engineAgain */
            $engineAgain = $verified['engine'];
            /** @var array<string, mixed> $settings */
            $settings = $verified['settings'];
            $ages = $verified['ages'];

            $cancelMessage = $this->statusMapper->cancelledArticleErrorMessage();
            $item->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => $cancelMessage,
                'error_message' => $cancelMessage,
                'finished_at' => now(),
            ]);

            // Revoke ownership; do NOT stamp worker_lost / recoverable.
            unset(
                $engineAgain['active_dispatch'],
                $engineAgain[ContentProjectRunRecoverableState::WORKER_LOST_KEY],
                $engineAgain[ContentProjectRunRecoverableState::SETTINGS_KEY],
            );
            $engineAgain['finalized_at'] = now()->toIso8601String();
            $engineAgain['final_status'] = 'cancelled';
            $engineAgain['intentional_unvisited_pending'] = true;
            if (empty($engineAgain['stop_reason'])) {
                $engineAgain['stop_reason'] = $cancelMessage;
            }
            $settings[self::SETTINGS_ENGINE_KEY] = $engineAgain;

            $previous = (string) $locked->status;
            $locked->update([
                'status' => $this->statusMapper->runToDb(ContentProjectRunSemanticStatus::Cancelled),
                'finished_at' => now(),
                'settings' => $settings,
            ]);

            RuntimeLogger::info('content_project_run.transition', [
                'run_id' => (int) $locked->id,
                'run_item_id' => (int) $item->id,
                'task_id' => (int) $item->task_id,
                'lease_age_seconds' => $ages['worker_death_age_seconds'],
                'before' => $previous,
                'after' => 'cancelled',
                'decision' => 'stopping_dead_worker_finalize',
                'reason' => 'user_stop_wins_over_worker_loss',
            ]);

            return true;
        });

        if (! $applied) {
            return false;
        }

        $fresh = $run->fresh() ?? $run;
        $this->runItemService->syncMirrorAndCounters($fresh, false);
        $this->normalizeTerminalHelperRows($fresh);
        $reason = $this->stopReason($fresh);
        $this->events->runCancelled($fresh, $reason);
        $this->logRunMetrics($fresh, 'cancelled');

        return true;
    }

    /**
     * Shared ownership + hard-lease gate for dead-worker recovery.
     *
     * @return array{
     *     item: SeoProjectRunItem,
     *     active: array<string, mixed>,
     *     engine: array<string, mixed>,
     *     settings: array<string, mixed>,
     *     ages: array<string, mixed>
     * }|null
     */
    private function verifyDeadDispatchOwnership(SeoProjectRun $locked): ?array
    {
        $settings = is_array($locked->settings) ? $locked->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active === null) {
            return null;
        }

        $ages = $this->dispatchAges($active);
        if (! ($ages['worker_death_expired'] ?? false)) {
            return null;
        }

        $runItemId = (int) ($active['run_item_id'] ?? 0);
        $token = (string) ($active['token'] ?? '');
        if ($runItemId <= 0 || $token === '') {
            return null;
        }

        /** @var SeoProjectRunItem|null $item */
        $item = SeoProjectRunItem::query()
            ->whereKey($runItemId)
            ->lockForUpdate()
            ->first();
        if (! $item instanceof SeoProjectRunItem
            || (int) $item->run_id !== (int) $locked->id
            || (string) $item->status !== SeoProjectRunItemStatus::Processing->value
        ) {
            return null;
        }

        // Re-read reservation after item lock (no newer dispatch).
        $locked->refresh();
        $settings = is_array($locked->settings) ? $locked->settings : [];
        $engineAgain = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $activeAgain = is_array($engineAgain['active_dispatch'] ?? null) ? $engineAgain['active_dispatch'] : null;
        if ($activeAgain === null
            || (string) ($activeAgain['token'] ?? '') !== $token
            || (int) ($activeAgain['run_item_id'] ?? 0) !== $runItemId
        ) {
            return null;
        }

        return [
            'item' => $item,
            'active' => $activeAgain,
            'engine' => $engineAgain,
            'settings' => $settings,
            'ages' => $ages,
        ];
    }

    /**
     * Normalize pending|processing helper/step rows on a terminal run.
     * Never touches article-execution rows. Never reopens run. Never dispatches.
     *
     * @param  list<int>|null  $onlyIds  When set, only these IDs (still must be helper/control + pending|processing).
     * @return array{
     *     applied: bool,
     *     reason: string,
     *     changed_ids: list<int>,
     *     skipped_ids: list<int>,
     *     terminal_status: string
     * }
     */
    public function normalizeTerminalHelperRows(SeoProjectRun $run, ?array $onlyIds = null): array
    {
        $run->refresh();
        $status = $this->statusMapper->runFromDb((string) $run->status);
        if (! $status->isTerminal()) {
            return [
                'applied' => false,
                'reason' => 'run_not_terminal',
                'changed_ids' => [],
                'skipped_ids' => [],
                'terminal_status' => $status->value,
            ];
        }

        $engine = $this->engineBag($run);
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active !== null) {
            return [
                'applied' => false,
                'reason' => 'active_dispatch_present',
                'changed_ids' => [],
                'skipped_ids' => [],
                'terminal_status' => $status->value,
            ];
        }

        if ($this->activeProcessingCount($run) > 0) {
            return [
                'applied' => false,
                'reason' => 'article_processing_present',
                'changed_ids' => [],
                'skipped_ids' => [],
                'terminal_status' => $status->value,
            ];
        }

        $query = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->helperOrControl()
            ->whereIn('status', [
                SeoProjectRunItemStatus::Pending->value,
                SeoProjectRunItemStatus::Processing->value,
            ]);

        if ($onlyIds !== null) {
            $ids = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => (int) $id, $onlyIds),
                static fn (int $id): bool => $id > 0,
            )));
            if ($ids === []) {
                return [
                    'applied' => false,
                    'reason' => 'no_ids',
                    'changed_ids' => [],
                    'skipped_ids' => [],
                    'terminal_status' => $status->value,
                ];
            }
            $query->whereIn('id', $ids);
        }

        $changedIds = [];
        $message = 'Normalized on terminal run (helper/step unused).';

        DB::connection('omi_seo_ai')->transaction(function () use ($query, $message, &$changedIds): void {
            $rows = $query->lockForUpdate()->get();
            foreach ($rows as $row) {
                if (! $row instanceof SeoProjectRunItem) {
                    continue;
                }
                if (! SeoProjectRunItemClassifier::isHelperOrControl(
                    $row->action !== null ? (string) $row->action : null
                )) {
                    continue;
                }
                if (! in_array((string) $row->status, [
                    SeoProjectRunItemStatus::Pending->value,
                    SeoProjectRunItemStatus::Processing->value,
                ], true)) {
                    continue;
                }

                $updated = SeoProjectRunItem::query()
                    ->whereKey((int) $row->id)
                    ->whereIn('status', [
                        SeoProjectRunItemStatus::Pending->value,
                        SeoProjectRunItemStatus::Processing->value,
                    ])
                    ->helperOrControl()
                    ->update([
                        'status' => SeoProjectRunItemStatus::Skipped->value,
                        'message' => $message,
                        'error_message' => null,
                        'finished_at' => now(),
                    ]);

                if ($updated > 0) {
                    $changedIds[] = (int) $row->id;
                }
            }
        });

        if ($changedIds !== []) {
            RuntimeLogger::info('content_project_run.normalize_terminal_helpers', [
                'run_id' => (int) $run->id,
                'changed_ids' => $changedIds,
                'terminal_status' => $status->value,
                'neutral_status' => SeoProjectRunItemStatus::Skipped->value,
            ]);
            $this->runItemService->syncMirrorAndCounters($run->fresh() ?? $run, false);
        }

        return [
            'applied' => true,
            'reason' => $changedIds === [] ? 'noop_already_clean' : 'normalized',
            'changed_ids' => $changedIds,
            'skipped_ids' => [],
            'terminal_status' => $status->value,
        ];
    }

    /**
     * Apply stale active_dispatch release under strict gates. Returns result array.
     *
     * @return array<string, mixed>
     */
    public function applyStaleDispatchRelease(SeoProjectRun $run, string $expectedToken): array
    {
        $plan = $this->recoveryPlan($run);
        if (! ($plan['eligible_for_stale_release'] ?? false)) {
            return [
                'applied' => false,
                'reason' => 'not_eligible',
                'plan' => $plan,
            ];
        }

        $token = (string) ($plan['token'] ?? '');
        if ($token === '' || ! hash_equals($token, $expectedToken)) {
            return [
                'applied' => false,
                'reason' => 'token_mismatch',
                'plan' => $plan,
            ];
        }

        $released = DB::connection('omi_seo_ai')->transaction(function () use ($run, $expectedToken): bool {
            /** @var SeoProjectRun|null $locked */
            $locked = SeoProjectRun::query()
                ->whereKey((int) $run->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof SeoProjectRun) {
                return false;
            }

            $status = $this->statusMapper->runFromDb((string) $locked->status);
            if ($status->isTerminal()) {
                return false;
            }
            if ($this->activeProcessingCount($locked) > 0) {
                return false;
            }

            $settings = is_array($locked->settings) ? $locked->settings : [];
            $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
                ? $settings[self::SETTINGS_ENGINE_KEY]
                : [];
            $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
            if ($active === null) {
                return false;
            }
            if ((string) ($active['token'] ?? '') !== $expectedToken) {
                return false;
            }

            $ages = $this->dispatchAges($active);
            if (! $ages['dispatch_ttl_expired'] || $ages['heartbeat_alive']) {
                return false;
            }

            unset($engine['active_dispatch']);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $locked->update(['settings' => $settings]);

            RuntimeLogger::info('content_project_run.stale_dispatch_released', [
                'run_id' => (int) $locked->id,
                'reason' => 'operator_recover_apply',
                'decision' => 'release',
                'dispatch_age_seconds' => $ages['dispatch_age_seconds'],
                'heartbeat_age_seconds' => $ages['heartbeat_age_seconds'],
            ]);

            return true;
        });

        $run->refresh();

        return [
            'applied' => $released,
            'reason' => $released ? 'released' : 'race_or_gate_failed',
            'plan_after' => $this->recoveryPlan($run),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function statusSnapshot(SeoProjectRun $run): array
    {
        $run->refresh();
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        $ages = is_array($active) ? $this->dispatchAges($active) : [
            'dispatch_age_seconds' => null,
            'heartbeat_age_seconds' => null,
            'heartbeat_alive' => false,
            'heartbeat_stale_warn' => false,
            'dispatch_ttl_expired' => false,
        ];

        $base = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution();

        $failedRows = (clone $base)->where('status', SeoProjectRunItemStatus::Failed->value)->get([
            'id', 'message', 'error_message',
        ]);
        $cancelledCount = 0;
        foreach ($failedRows as $row) {
            $msg = trim((string) ($row->error_message ?? $row->message ?? ''));
            if ($this->statusMapper->errorLooksCancelled($msg)) {
                $cancelledCount++;
            }
        }

        $counts = [
            'pending' => (clone $base)->where('status', SeoProjectRunItemStatus::Pending->value)->count(),
            'running' => (clone $base)->where('status', SeoProjectRunItemStatus::Processing->value)->count(),
            'completed' => (clone $base)->whereIn('status', [
                SeoProjectRunItemStatus::Success->value,
                SeoProjectRunItemStatus::Skipped->value,
                SeoProjectRunItemStatus::Manual->value,
            ])->count(),
            'failed' => max(0, $failedRows->count() - $cancelledCount),
            'cancelled' => $cancelledCount,
        ];

        $next = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->where('status', SeoProjectRunItemStatus::Pending->value)
            ->orderBy('id')
            ->first(['id', 'task_id', 'status', 'article_id']);

        $processing = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->where('status', SeoProjectRunItemStatus::Processing->value)
            ->orderBy('id')
            ->first(['id', 'task_id', 'article_id', 'status', 'updated_at']);

        $startedAt = isset($engine['started_at']) ? (string) $engine['started_at'] : null;
        $durationSeconds = null;
        if ($startedAt !== null && $startedAt !== '') {
            $durationSeconds = max(0, (int) now()->diffInSeconds(Carbon::parse($startedAt)));
        }

        $health = $this->healthCheck($run);

        return [
            'run' => [
                'id' => (int) $run->id,
                'project_id' => (int) ($run->project_id ?? 0),
                'status' => (string) $run->status,
                'semantic_status' => $this->statusMapper->runFromDb((string) $run->status)->value,
                'duration_seconds' => $durationSeconds,
                'finished_at' => $run->finished_at?->toIso8601String(),
            ],
            'feature_flag' => [
                'global' => ContentProjectRunEngineFeature::enabled(),
                'for_run' => ContentProjectRunEngineFeature::enabledFor($run),
                'orchestration' => $engine['orchestration'] ?? null,
            ],
            'queue' => ContentProjectRunEngineFeature::queueName(),
            'stop_requested' => $this->cancellationGuard->isStopRequested($run),
            'stop_reason' => $this->stopReason($run),
            'recoverable_reason' => ContentProjectRunRecoverableState::reasonFromEngine($engine),
            'recoverable_message' => ContentProjectRunRecoverableState::userVisibleMessage($engine),
            'is_recoverable' => ContentProjectRunRecoverableState::isRecoverableEngine($engine)
                && (string) $run->status === SeoProjectRun::STATUS_FAILED,
            'counts' => $counts,
            'outstanding_pending' => $counts['pending'],
            'current_processing' => $processing instanceof SeoProjectRunItem ? [
                'run_item_id' => (int) $processing->id,
                'task_id' => (int) $processing->task_id,
                'article_id' => $processing->article_id !== null ? (int) $processing->article_id : null,
            ] : null,
            'article' => [
                'active_run_item_id' => isset($active['run_item_id']) ? (int) $active['run_item_id'] : null,
                'active_task_id' => isset($active['task_id']) ? (int) $active['task_id'] : null,
                'active_article_id' => isset($active['article_id']) ? (int) $active['article_id'] : (
                    $processing?->article_id !== null ? (int) $processing->article_id : null
                ),
            ],
            'dispatch' => $active,
            'heartbeat' => [
                'last_heartbeat_at' => $active['last_heartbeat_at'] ?? null,
                'age_seconds' => $ages['heartbeat_age_seconds'],
                'alive' => $ages['heartbeat_alive'],
                'stale_warn' => $ages['heartbeat_stale_warn'],
            ],
            'current_job' => $active !== null ? [
                'run_item_id' => (int) ($active['run_item_id'] ?? 0),
                'task_id' => (int) ($active['task_id'] ?? 0),
                'attempt' => (int) ($active['attempt'] ?? 0),
                'token_prefix' => isset($active['token']) ? substr((string) $active['token'], 0, 12) : null,
                'dispatched_at' => $active['dispatched_at'] ?? null,
                'claimed_at' => $active['claimed_at'] ?? null,
            ] : null,
            'current_step' => $active['current_step'] ?? null,
            'dispatch_age_seconds' => $ages['dispatch_age_seconds'],
            'heartbeat_age_seconds' => $ages['heartbeat_age_seconds'],
            'ttl' => [
                'active_dispatch_ttl_minutes' => ContentProjectRunEngineFeature::activeDispatchTtlMinutes(),
                'heartbeat_stale_minutes' => ContentProjectRunEngineFeature::heartbeatStaleMinutes(),
                'dispatch_ttl_expired' => $ages['dispatch_ttl_expired'],
            ],
            'last_transition' => [
                'started_at' => $engine['started_at'] ?? null,
                'stop_requested_at' => $engine['stop_requested_at'] ?? null,
                'finalized_at' => $engine['finalized_at'] ?? null,
                'final_status' => $engine['final_status'] ?? null,
                'finished_at' => $run->finished_at?->toIso8601String(),
                'updated_at' => $run->updated_at?->toIso8601String(),
            ],
            'next_candidate' => $next instanceof SeoProjectRunItem ? [
                'run_item_id' => (int) $next->id,
                'task_id' => (int) $next->task_id,
                'article_id' => $next->article_id !== null ? (int) $next->article_id : null,
            ] : null,
            'counters' => [
                'total' => (int) $run->total,
                'succeeded' => (int) $run->succeeded,
                'failed' => (int) $run->failed,
            ],
            'health' => $health->toArray(),
            // backward-compatible flat keys
            'run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'active_dispatch' => $active,
        ];
    }

    private function activeProcessingCount(SeoProjectRun $run): int
    {
        return SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->where('status', SeoProjectRunItemStatus::Processing->value)
            ->count();
    }

    private function hasBlockingActiveDispatch(SeoProjectRun $run): bool
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active === null) {
            return false;
        }

        $ages = $this->dispatchAges($active);
        // Heartbeat còn sống → coi như blocking (không release / không dispatch khác).
        if ($ages['heartbeat_alive']) {
            return true;
        }

        $runItemId = (int) ($active['run_item_id'] ?? 0);
        if ($runItemId <= 0) {
            return false;
        }

        $item = SeoProjectRunItem::query()->find($runItemId);
        if (! $item instanceof SeoProjectRunItem) {
            return false;
        }

        return in_array((string) $item->status, [
            SeoProjectRunItemStatus::Pending->value,
            SeoProjectRunItemStatus::Processing->value,
        ], true);
    }

    private function sweepStaleActiveDispatch(SeoProjectRun $run): void
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active === null) {
            return;
        }

        $runItemId = (int) ($active['run_item_id'] ?? 0);
        $item = $runItemId > 0 ? SeoProjectRunItem::query()->find($runItemId) : null;
        $ages = $this->dispatchAges($active);

        $itemTerminal = $item instanceof SeoProjectRunItem
            && ! in_array((string) $item->status, [
                SeoProjectRunItemStatus::Pending->value,
                SeoProjectRunItemStatus::Processing->value,
            ], true);

        $itemMissing = ! $item instanceof SeoProjectRunItem;
        $itemProcessing = $item instanceof SeoProjectRunItem
            && (string) $item->status === SeoProjectRunItemStatus::Processing->value;

        // DB vẫn processing → không release (tránh double dispatch khi heartbeat chết giữa LLM).
        if ($itemProcessing) {
            if ($ages['heartbeat_stale_warn']) {
                RuntimeLogger::warning('content_project_run.heartbeat_stale', [
                    'run_id' => (int) $run->id,
                    'run_item_id' => $runItemId,
                    'heartbeat_age_seconds' => $ages['heartbeat_age_seconds'],
                    'decision' => 'keep_dispatch',
                    'reason' => 'item_still_processing',
                ]);
            }

            return;
        }

        // Worker còn heartbeat → không release dù TTL quá hạn.
        if ($ages['heartbeat_alive'] && ! $itemTerminal && ! $itemMissing) {
            return;
        }

        $releasableByTtl = $ages['dispatch_ttl_expired'] && ! $ages['heartbeat_alive'];

        if ($itemTerminal || $itemMissing || $releasableByTtl) {
            unset($engine['active_dispatch']);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $run->update(['settings' => $settings]);
            $run->refresh();

            $reason = $itemTerminal ? 'item_terminal' : ($itemMissing ? 'item_missing' : 'dispatch_ttl_and_heartbeat_dead');

            RuntimeLogger::info('content_project_run.stale_dispatch_released', [
                'run_id' => (int) $run->id,
                'run_item_id' => $runItemId,
                'reason' => $reason,
                'dispatch_age_seconds' => $ages['dispatch_age_seconds'],
                'heartbeat_age_seconds' => $ages['heartbeat_age_seconds'],
                'decision' => 'release',
            ]);
            RuntimeLogger::info('content_project_run.stale_job_ignored', [
                'run_id' => (int) $run->id,
                'run_item_id' => $runItemId,
                'reason' => $reason,
            ]);
        }
    }

    private function clearActiveDispatch(
        SeoProjectRun $run,
        ?int $taskId,
        ?int $runItemId,
        ?string $dispatchToken = null,
    ): void {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;
        if ($active === null) {
            return;
        }

        $matchesTask = $taskId === null || (int) ($active['task_id'] ?? 0) === $taskId;
        $matchesItem = $runItemId === null || (int) ($active['run_item_id'] ?? 0) === $runItemId;
        $matchesToken = $dispatchToken === null || $dispatchToken === ''
            || (string) ($active['token'] ?? '') === $dispatchToken;
        if ($matchesTask && $matchesItem && $matchesToken) {
            unset($engine['active_dispatch'], $engine['item_operation']);
            // Clear ephemeral per-item overlays so next JIT item starts clean.
            if ((bool) ($settings['lazy_bulk'] ?? false)) {
                foreach ([
                    'rerun',
                    'rerun_scope',
                    'rerun_from_step',
                    'rerun_include_downstream',
                    'resume_partial_split',
                    'resume_prior_run_item_id',
                    'resume_split_progress',
                    'generation_mode',
                    'generation_keyword_override',
                ] as $key) {
                    unset($settings[$key]);
                }
            }
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $run->update(['settings' => $settings]);
            $run->refresh();
        }
    }

    /**
     * Ownership gate: stale workers must not clear a newer reservation or advance the run.
     */
    private function ownsCurrentDispatch(
        SeoProjectRun $run,
        ?int $taskId,
        ?int $runItemId,
        ?string $dispatchToken,
    ): bool {
        $engine = $this->engineBag($run);
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : null;

        // No reservation left — allow terminal bookkeeping only when caller still targets the item
        // that already finished (idempotent re-entry). Do not advance if token was explicitly revoked.
        if ($active === null) {
            return $dispatchToken === null || $dispatchToken === '';
        }

        if ($dispatchToken === null || $dispatchToken === '') {
            // Legacy callers without token — still require task/item identity match.
            $matchesTask = $taskId === null || (int) ($active['task_id'] ?? 0) === $taskId;
            $matchesItem = $runItemId === null || (int) ($active['run_item_id'] ?? 0) === $runItemId;

            return $matchesTask && $matchesItem;
        }

        return (string) ($active['token'] ?? '') === $dispatchToken
            && ($runItemId === null || (int) ($active['run_item_id'] ?? 0) === $runItemId)
            && ($taskId === null || (int) ($active['task_id'] ?? 0) === $taskId);
    }

    private function clearFinalizeStamp(SeoProjectRun $run): void
    {
        $run->refresh();
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings[self::SETTINGS_ENGINE_KEY] ?? null)
            ? $settings[self::SETTINGS_ENGINE_KEY]
            : [];
        unset($engine['finalized_at'], $engine['final_status']);
        $settings[self::SETTINGS_ENGINE_KEY] = $engine;
        $run->update(['settings' => $settings]);
        $run->refresh();
    }

    /**
     * @deprecated Kept for source compatibility — stop/cancel must NOT convert unvisited pending to failed.
     */
    private function abandonPendingArticles(SeoProjectRun $run): void
    {
        RuntimeLogger::info('content_project_run.abandon_pending_skipped', [
            'run_id' => (int) $run->id,
            'reason' => 'unvisited_membership_retained',
        ]);
    }

    private function stopReason(SeoProjectRun $run): ?string
    {
        $engine = $this->engineBag($run);
        $visible = ContentProjectRunRecoverableState::userVisibleMessage($engine);
        if ($visible !== null) {
            return $visible;
        }
        $reason = $engine['stop_reason'] ?? null;

        return is_string($reason) ? $reason : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function engineBag(SeoProjectRun $run): array
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = $settings[self::SETTINGS_ENGINE_KEY] ?? null;

        return is_array($engine) ? $engine : [];
    }

    /**
     * @param  array<string, mixed>  $active
     * @return array{
     *     dispatch_age_seconds: ?int,
     *     heartbeat_age_seconds: ?int,
     *     heartbeat_alive: bool,
     *     heartbeat_stale_warn: bool,
     *     dispatch_ttl_expired: bool,
     *     worker_death_expired: bool,
     *     worker_death_age_seconds: ?int
     * }
     */
    private function dispatchAges(array $active): array
    {
        $dispatchAge = $this->ageSeconds($active['dispatched_at'] ?? null);
        $heartbeatAge = $this->ageSeconds($active['last_heartbeat_at'] ?? $active['dispatched_at'] ?? null);
        $ttlSeconds = ContentProjectRunEngineFeature::activeDispatchTtlMinutes() * 60;
        $heartbeatStaleSeconds = ContentProjectRunEngineFeature::heartbeatStaleMinutes() * 60;
        $workerDeathSeconds = ContentProjectRunEngineFeature::workerDeathThresholdSeconds();

        // Lease age prefers last heartbeat (proves liveness); falls back to dispatch time.
        $leaseAge = $heartbeatAge ?? $dispatchAge;

        $heartbeatAlive = $heartbeatAge !== null && $heartbeatAge < $heartbeatStaleSeconds;
        $heartbeatStaleWarn = $heartbeatAge !== null && $heartbeatAge >= $heartbeatStaleSeconds;
        $dispatchTtlExpired = $dispatchAge !== null && $dispatchAge >= $ttlSeconds;
        $workerDeathExpired = $leaseAge !== null && $leaseAge >= $workerDeathSeconds;

        return [
            'dispatch_age_seconds' => $dispatchAge,
            'heartbeat_age_seconds' => $heartbeatAge,
            'heartbeat_alive' => $heartbeatAlive,
            'heartbeat_stale_warn' => $heartbeatStaleWarn,
            'dispatch_ttl_expired' => $dispatchTtlExpired,
            'worker_death_expired' => $workerDeathExpired,
            'worker_death_age_seconds' => $leaseAge,
        ];
    }

    private function ageSeconds(mixed $iso): ?int
    {
        if (! is_string($iso) || trim($iso) === '') {
            return null;
        }

        try {
            return max(0, (int) now()->diffInSeconds(Carbon::parse($iso)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function logRunMetrics(SeoProjectRun $run, string $finalStatus): void
    {
        $engine = $this->engineBag($run);
        $startedAt = isset($engine['started_at']) ? (string) $engine['started_at'] : null;
        $runDuration = $startedAt !== null && $startedAt !== ''
            ? max(0, (int) now()->diffInSeconds(Carbon::parse($startedAt)))
            : null;

        $total = max(1, (int) $run->total);
        $failed = (int) $run->failed;
        $succeeded = (int) $run->succeeded;
        $failedPct = round(($failed / $total) * 100, 2);
        $cancelPct = $finalStatus === 'cancelled' ? 100.0 : 0.0;
        $avgArticle = $runDuration !== null && $succeeded + $failed > 0
            ? (int) round($runDuration / max(1, $succeeded + $failed))
            : null;

        RuntimeLogger::info('content_project_run.metrics', [
            'run_id' => (int) $run->id,
            'final_status' => $finalStatus,
            'run_duration_seconds' => $runDuration,
            'average_article_seconds' => $avgArticle,
            'failed_pct' => $failedPct,
            'cancel_pct' => $cancelPct,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'total' => (int) $run->total,
            'decision' => 'metrics',
        ]);
    }
}
