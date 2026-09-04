<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Jobs;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectArticleRunner;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectRunEngine;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\RunCancellationGuard;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;
use App\Support\RuntimeLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One article (full workflow) inside a Content Project run.
 * Does not own run-level dispatch — calls engine after finish.
 */
final class RunContentProjectArticleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $runId,
        public readonly int $taskId,
        public readonly int $runItemId,
        public readonly int $attempt,
        public readonly string $dispatchToken,
    ) {
        $this->onQueue(ContentProjectRunEngineFeature::queueName());
    }

    public function uniqueId(): string
    {
        return 'content-project-run-article:'.$this->runId.':'.$this->runItemId.':'.$this->attempt;
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        ContentProjectRunEngine $engine,
        ContentProjectArticleRunner $articleRunner,
        RunCancellationGuard $cancellationGuard,
        ContentProjectRunStatusMapper $statusMapper,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();

        $run = SeoProjectRun::query()->find($this->runId);
        if (! $run instanceof SeoProjectRun) {
            RuntimeLogger::warning('content_project_run.stale_job_ignored', [
                'run_id' => $this->runId,
                'task_id' => $this->taskId,
                'reason' => 'missing_run',
            ]);

            return;
        }

        $run->loadMissing('project');
        $projectSiteId = (int) ($run->project?->site_id ?? 0);
        if ($projectSiteId > 0) {
            $databaseConnection->bootstrapSeoDatabaseConnection($projectSiteId);
            $run = SeoProjectRun::query()->find($this->runId) ?? $run;
        }

        if (! $this->dispatchTokenMatches($run)) {
            RuntimeLogger::info('content_project_run.stale_job_ignored', [
                'run_id' => $this->runId,
                'task_id' => $this->taskId,
                'run_item_id' => $this->runItemId,
                'reason' => 'dispatch_token_mismatch',
            ]);

            return;
        }

        $item = SeoProjectRunItem::query()->find($this->runItemId);
        if (! $item instanceof SeoProjectRunItem) {
            $engine->handleArticleFinished($run, new ArticleExecutionResult(
                runId: $this->runId,
                taskId: $this->taskId,
                runItemId: $this->runItemId,
                status: ContentProjectArticleSemanticStatus::Failed,
                message: 'Run item không tồn tại.',
                mayDispatchNextOverride: true,
            ));

            return;
        }

        // Terminal success/skip — do not re-execute; do not overwrite; continue chain once.
        if (in_array((string) $item->status, [
            SeoProjectRunItemStatus::Success->value,
            SeoProjectRunItemStatus::Skipped->value,
            SeoProjectRunItemStatus::Manual->value,
        ], true)) {
            RuntimeLogger::info('content_project_run.stale_job_ignored', [
                'run_id' => $this->runId,
                'run_item_id' => $this->runItemId,
                'status' => (string) $item->status,
                'reason' => 'already_terminal_success',
            ]);
            $engine->handleArticleFinished(
                $run,
                ArticleExecutionResult::fromLegacyItemRow(
                    $this->runId,
                    $this->taskId,
                    $this->runItemId,
                    [
                        'status' => 'success',
                        'article_id' => $item->article_id,
                        'message' => (string) ($item->message ?? 'Already completed.'),
                    ],
                    $statusMapper,
                ),
            );

            return;
        }

        if ((string) $item->status === SeoProjectRunItemStatus::Failed->value) {
            RuntimeLogger::info('content_project_run.stale_job_ignored', [
                'run_id' => $this->runId,
                'run_item_id' => $this->runItemId,
                'reason' => 'already_terminal_failed',
            ]);
            $engine->handleArticleFinished($run, new ArticleExecutionResult(
                runId: $this->runId,
                taskId: $this->taskId,
                runItemId: $this->runItemId,
                status: ContentProjectArticleSemanticStatus::Failed,
                articleId: $item->article_id !== null ? (int) $item->article_id : null,
                message: (string) ($item->message ?? 'Already failed.'),
                mayDispatchNextOverride: true,
            ));

            return;
        }

        // Circuit breaker: leave item pending — do not execute or mark error.
        if ($engine->isCircuitBreakerStopped($run)) {
            RuntimeLogger::info('content_project_run.stale_job_ignored', [
                'run_id' => $this->runId,
                'run_item_id' => $this->runItemId,
                'reason' => 'circuit_breaker_stopped',
            ]);
            $engine->releaseSkippedDispatch($run, $this->runItemId, $this->dispatchToken);

            return;
        }

        // Boundary: cancel before execute
        if ($cancellationGuard->isStopRequested($run) || $cancellationGuard->isTerminal($run)) {
            $this->markItemCancelled($item, $statusMapper);
            $engine->handleArticleFinished($run, new ArticleExecutionResult(
                runId: $this->runId,
                taskId: $this->taskId,
                runItemId: $this->runItemId,
                status: ContentProjectArticleSemanticStatus::Cancelled,
                message: $statusMapper->cancelledArticleErrorMessage(),
                mayDispatchNextOverride: false,
            ));

            return;
        }

        RuntimeLogger::info('content_project_run.article_claimed', [
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'run_item_id' => $this->runItemId,
            'attempt' => $this->attempt,
            'token' => $this->dispatchToken,
            'feature_flag' => ContentProjectRunEngineFeature::enabledFor($run),
        ]);

        $engine->touchHeartbeat($run, $this->runItemId, $this->dispatchToken, 'claimed');

        $settings = is_array($run->settings) ? $run->settings : [];
        $phpEngine = is_array($settings['php_engine'] ?? null) ? $settings['php_engine'] : [];
        $active = is_array($phpEngine['active_dispatch'] ?? null) ? $phpEngine['active_dispatch'] : [];
        $dispatchedAt = (string) ($active['dispatched_at'] ?? '');
        if ($dispatchedAt !== '') {
            try {
                $delay = max(0, (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($dispatchedAt)));
                RuntimeLogger::info('content_project_run.metrics', [
                    'run_id' => $this->runId,
                    'run_item_id' => $this->runItemId,
                    'dispatch_delay_seconds' => $delay,
                    'decision' => 'dispatch_delay',
                ]);
            } catch (\Throwable) {
                // ignore parse
            }
        }

        // Re-check ngay trước provider call (race stop/abandon sau token check).
        $run->refresh();
        $item->refresh();
        if (! $this->dispatchTokenMatches($run)) {
            RuntimeLogger::info('content_project_run.stale_job_ignored', [
                'run_id' => $this->runId,
                'run_item_id' => $this->runItemId,
                'reason' => 'dispatch_token_mismatch_pre_run',
            ]);

            return;
        }
        if ($cancellationGuard->isStopRequested($run) || $cancellationGuard->isTerminal($run)) {
            $this->markItemCancelled($item, $statusMapper);
            $engine->handleArticleFinished($run, new ArticleExecutionResult(
                runId: $this->runId,
                taskId: $this->taskId,
                runItemId: $this->runItemId,
                status: ContentProjectArticleSemanticStatus::Cancelled,
                message: $statusMapper->cancelledArticleErrorMessage(),
                mayDispatchNextOverride: false,
            ));

            return;
        }
        if (! in_array((string) $item->status, [
            SeoProjectRunItemStatus::Pending->value,
            SeoProjectRunItemStatus::Processing->value,
        ], true)) {
            RuntimeLogger::info('content_project_run.stale_job_ignored', [
                'run_id' => $this->runId,
                'run_item_id' => $this->runItemId,
                'status' => (string) $item->status,
                'reason' => 'item_not_runnable_pre_run',
            ]);
            $engine->handleArticleFinished(
                $run,
                ArticleExecutionResult::fromLegacyItemRow(
                    $this->runId,
                    $this->taskId,
                    $this->runItemId,
                    [
                        'status' => (string) $item->status === SeoProjectRunItemStatus::Success->value
                            ? 'success'
                            : 'failed',
                        'article_id' => $item->article_id,
                        'message' => (string) ($item->message ?? 'Already terminal.'),
                        'error_detail' => (string) ($item->error_message ?? ''),
                    ],
                    $statusMapper,
                ),
            );

            return;
        }

        $articleStarted = microtime(true);
        $engine->touchHeartbeat($run, $this->runItemId, $this->dispatchToken, 'running_article');
        $result = $articleRunner->run($run, $this->taskId, $this->runItemId);
        $engine->touchHeartbeat($run->fresh() ?? $run, $this->runItemId, $this->dispatchToken, 'article_finished');

        RuntimeLogger::info('content_project_run.metrics', [
            'run_id' => $this->runId,
            'run_item_id' => $this->runItemId,
            'task_id' => $this->taskId,
            'article_duration_seconds' => (int) round(microtime(true) - $articleStarted),
            'status' => $result->status->value,
            'decision' => 'article_metrics',
        ]);

        // Late cancel after runner: if cancelled and non-success, ensure DB terminal.
        if ($result->isCancelled()) {
            $item->refresh();
            if (in_array((string) $item->status, [
                SeoProjectRunItemStatus::Pending->value,
                SeoProjectRunItemStatus::Processing->value,
            ], true)) {
                $this->markItemCancelled($item, $statusMapper);
            }
        }

        $engine->handleArticleFinished($run->fresh() ?? $run, $result);
    }

    public function failed(?\Throwable $exception): void
    {
        RuntimeLogger::error('content_project_run.article_failed', [
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'run_item_id' => $this->runItemId,
            'error' => $exception?->getMessage(),
            'failure_class' => $exception !== null ? $exception::class : null,
            'phase' => 'job_failed_handler',
        ]);

        try {
            $engine = app(ContentProjectRunEngine::class);
            $run = SeoProjectRun::query()->find($this->runId);
            if (! $run instanceof SeoProjectRun) {
                return;
            }

            $item = SeoProjectRunItem::query()->find($this->runItemId);
            if ($item instanceof SeoProjectRunItem
                && in_array((string) $item->status, [
                    SeoProjectRunItemStatus::Pending->value,
                    SeoProjectRunItemStatus::Processing->value,
                ], true)
            ) {
                $item->update([
                    'status' => SeoProjectRunItemStatus::Failed->value,
                    'message' => $exception?->getMessage() ?? 'Article job failed.',
                    'error_message' => $exception?->getMessage() ?? 'Article job failed.',
                    'finished_at' => now(),
                ]);
            }

            // Domain-side terminal for this article → continue run (mayDispatchNext true).
            $engine->handleArticleFinished($run, new ArticleExecutionResult(
                runId: $this->runId,
                taskId: $this->taskId,
                runItemId: $this->runItemId,
                status: ContentProjectArticleSemanticStatus::Failed,
                message: $exception?->getMessage() ?? 'Article job failed.',
                mayDispatchNextOverride: true,
            ));
        } catch (\Throwable $inner) {
            RuntimeLogger::error('content_project_run.article_failed', [
                'run_id' => $this->runId,
                'error' => $inner->getMessage(),
                'phase' => 'job_failed_handler_error',
            ]);
        }
    }

    private function dispatchTokenMatches(SeoProjectRun $run): bool
    {
        $settings = is_array($run->settings) ? $run->settings : [];
        $engine = is_array($settings['php_engine'] ?? null) ? $settings['php_engine'] : [];
        $active = is_array($engine['active_dispatch'] ?? null) ? $engine['active_dispatch'] : [];

        return (string) ($active['token'] ?? '') === $this->dispatchToken
            && (int) ($active['run_item_id'] ?? 0) === $this->runItemId;
    }

    private function markItemCancelled(
        SeoProjectRunItem $item,
        ContentProjectRunStatusMapper $statusMapper,
    ): void {
        $message = $statusMapper->cancelledArticleErrorMessage();
        $item->update([
            'status' => SeoProjectRunItemStatus::Failed->value,
            'message' => $message,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}
