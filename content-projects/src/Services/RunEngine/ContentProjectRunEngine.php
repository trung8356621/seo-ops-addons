<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\RunEngine;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectRunSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectRunItemService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowStepRetryService;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchCircuitBreakerState;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectBatchFailureSignature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunHealthReport;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;
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

        if ($this->tryResumeAfterCircuitBreaker($run)) {
            return;
        }

        if (! $this->cancellationGuard->allowsDispatch($run)) {
            $this->finalizeIfDone($run);

            return;
        }

        $this->dispatchNextArticle($run);
    }

    /**
     * Resume a run halted by consecutive identical failures — pending items stay pending.
     */
    private function tryResumeAfterCircuitBreaker(SeoProjectRun $run): bool
    {
        $engine = $this->engineBag($run);
        $breaker = is_array($engine['circuit_breaker'] ?? null) ? $engine['circuit_breaker'] : null;
        if ($breaker === null || empty($breaker['stopped'])) {
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
            unset($engine['circuit_breaker'], $engine['finalized_at'], $engine['final_status']);
            $engine = ContentProjectBatchCircuitBreakerState::clearForResume($engine);
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
     * Phase 1: max 1 active article per run.
     */
    public function dispatchNextArticle(SeoProjectRun $run): void
    {
        $dispatch = DB::connection('omi_seo_ai')->transaction(function () use ($run): ?array {
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

            // Failed items stay failed — never auto-reset here.
            // Reservation = settings.active_dispatch (+ item still pending/processing).
            // Actual claim pending→processing happens inside ExecutionService → runTaskPipeline → claimForExecution.
            $dispatchToken = hash('sha256', implode('|', [
                (int) $locked->id,
                (int) $next->id,
                (int) $next->attempt,
                (string) microtime(true),
            ]));

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

        if ($dispatch === null) {
            RuntimeLogger::info('content_project_run.next_dispatch_skipped', [
                'run_id' => (int) $run->id,
                'reason' => 'no_candidate_or_busy_or_stopped',
            ]);
            $this->finalizeIfDone($run->fresh() ?? $run);

            return;
        }

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

    public function handleArticleFinished(SeoProjectRun $run, ArticleExecutionResult $result): void
    {
        $started = microtime(true);
        $run->refresh();
        $this->clearActiveDispatch($run, $result->taskId, $result->runItemId);

        match ($result->status) {
            ContentProjectArticleSemanticStatus::Completed,
            ContentProjectArticleSemanticStatus::Skipped => $this->events->articleCompleted($run, $result),
            ContentProjectArticleSemanticStatus::Cancelled => $this->events->articleCancelled($run, $result),
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
                $engine['circuit_breaker'] = [
                    'stopped' => true,
                    'signature' => $signature,
                    'count' => $count,
                    'stopped_at' => now()->toIso8601String(),
                    'reason' => $this->circuitBreakerUserMessage($signature),
                ];
                $engine['stop_reason'] = $engine['circuit_breaker']['reason'];
                $engine['finalized_at'] = now()->toIso8601String();
                $engine['final_status'] = 'failed_circuit_breaker';
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

                $this->abandonPendingArticles($locked);
                $this->clearActiveDispatch($locked, null, null);

                $locked = $this->runItemService->syncMirrorAndCounters($locked, false);
                $previous = (string) $locked->status;
                $engine = $this->engineBag($locked);
                $engine['finalized_at'] = now()->toIso8601String();
                $engine['final_status'] = 'cancelled';
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
            $errors[] = 'run_terminal_with_pending_article_items';
        }

        if ($status->isTerminal() && $pendingHelperItems->isNotEmpty()) {
            $warnings[] = 'run_terminal_with_pending_helper_items';
            $details['helper_note'] = 'Helper/step còn pending|processing — không suy UI running; dùng recover --action=normalize-terminal-helpers.';
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
            if ($pendingArticleIds !== []) {
                $recommended = 'inspect_pending_article_items';
                $blockers[] = 'pending_article_items';
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

        return [
            'run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'semantic_status' => $status->value,
            'active_dispatch' => $active,
            'dispatch_age_seconds' => $ages['dispatch_age_seconds'] ?? null,
            'heartbeat_age_seconds' => $ages['heartbeat_age_seconds'] ?? null,
            'processing_count' => $processing,
            'pending_article_items' => $pendingArticleIds,
            'pending_helper_items' => $pendingHelperIds,
            'token' => is_array($active) ? ($active['token'] ?? null) : null,
            'eligible_for_stale_release' => $eligible,
            'eligible_for_normalize_terminal_helpers' => $eligibleNormalizeHelpers,
            'recommended_action' => $recommended,
            'blockers' => $blockers,
            'health' => $health->toArray(),
            'apply_requires' => [
                'stale_release' => [
                    'ttl_expired',
                    'heartbeat_dead',
                    'processing_count_0',
                    'run_not_terminal',
                    'token_match_inspected',
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
                'Heartbeat stale + processing = warning only (Phase 1.5 limitation).',
            ],
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

    private function clearActiveDispatch(SeoProjectRun $run, ?int $taskId, ?int $runItemId): void
    {
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
        if ($matchesTask && $matchesItem) {
            unset($engine['active_dispatch']);
            $settings[self::SETTINGS_ENGINE_KEY] = $engine;
            $run->update(['settings' => $settings]);
            $run->refresh();
        }
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

    private function abandonPendingArticles(SeoProjectRun $run): void
    {
        $message = $this->statusMapper->cancelledArticleErrorMessage();

        SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->articleExecution()
            ->where('status', SeoProjectRunItemStatus::Pending->value)
            ->update([
                'status' => SeoProjectRunItemStatus::Failed->value,
                'message' => $message,
                'error_message' => $message,
                'finished_at' => now(),
            ]);
    }

    private function stopReason(SeoProjectRun $run): ?string
    {
        $engine = $this->engineBag($run);
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
     *     dispatch_ttl_expired: bool
     * }
     */
    private function dispatchAges(array $active): array
    {
        $dispatchAge = $this->ageSeconds($active['dispatched_at'] ?? null);
        $heartbeatAge = $this->ageSeconds($active['last_heartbeat_at'] ?? $active['dispatched_at'] ?? null);
        $ttlSeconds = ContentProjectRunEngineFeature::activeDispatchTtlMinutes() * 60;
        $heartbeatStaleSeconds = ContentProjectRunEngineFeature::heartbeatStaleMinutes() * 60;

        $heartbeatAlive = $heartbeatAge !== null && $heartbeatAge < $heartbeatStaleSeconds;
        $heartbeatStaleWarn = $heartbeatAge !== null && $heartbeatAge >= $heartbeatStaleSeconds;
        $dispatchTtlExpired = $dispatchAge !== null && $dispatchAge >= $ttlSeconds;

        return [
            'dispatch_age_seconds' => $dispatchAge,
            'heartbeat_age_seconds' => $heartbeatAge,
            'heartbeat_alive' => $heartbeatAlive,
            'heartbeat_stale_warn' => $heartbeatStaleWarn,
            'dispatch_ttl_expired' => $dispatchTtlExpired,
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
