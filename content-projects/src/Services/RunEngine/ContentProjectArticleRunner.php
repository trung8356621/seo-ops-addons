<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\RunEngine;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectTransientAiRetryPolicy;
use App\Support\RuntimeLogger;

/**
 * Owns one article execution inside a Content Project run.
 * Does NOT dispatch next article. Does NOT call retryTask (Phase 1.7).
 */
final class ContentProjectArticleRunner
{
    public function __construct(
        private readonly ContentProjectTaskExecutionService $taskExecution,
        private readonly RunCancellationGuard $cancellationGuard,
        private readonly ContentProjectRunStatusMapper $statusMapper,
        private readonly ContentProjectRunEventPublisher $events,
    ) {}

    public function run(SeoProjectRun $run, int $taskId, ?int $runItemId = null): ArticleExecutionResult
    {
        $run->refresh();

        if ($this->cancellationGuard->isStopRequested($run)) {
            return $this->cancelledResult($run, $taskId, $runItemId, 'Run đã stopping/cancelled trước khi chạy article.');
        }

        if (app(ContentProjectRunEngine::class)->isCircuitBreakerStopped($run)) {
            RuntimeLogger::info('seo.content_project_run.article.skipped_circuit_breaker', [
                'run_id' => (int) $run->id,
                'task_id' => $taskId,
                'run_item_id' => $runItemId,
            ]);

            if ($runItemId !== null && $runItemId > 0) {
                $item = SeoProjectRunItem::query()->find($runItemId);
                if ($item instanceof SeoProjectRunItem
                    && (string) $item->status === \Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus::Processing->value
                ) {
                    $item->update([
                        'status' => \Omnichannel\Addons\ContentProjects\Enums\SeoProjectRunItemStatus::Pending->value,
                        'started_at' => null,
                        'finished_at' => null,
                        'message' => 'Deferred: batch circuit breaker stopped further articles.',
                    ]);
                }
            }

            return new ArticleExecutionResult(
                runId: (int) $run->id,
                taskId: $taskId,
                runItemId: $runItemId,
                status: ContentProjectArticleSemanticStatus::Cancelled,
                message: 'Skipped: circuit breaker stopped batch.',
                mayDispatchNextOverride: false,
            );
        }

        $this->cancellationGuard->assertAllowsArticleExecution($run);

        $this->events->articleStarted($run, $taskId, $runItemId);

        RuntimeLogger::info('seo.content_project_run.article.start', [
            'run_id' => (int) $run->id,
            'task_id' => $taskId,
            'run_item_id' => $runItemId,
        ]);

        try {
            $settings = is_array($run->settings) ? $run->settings : [];
            $policy = \Omnichannel\Addons\AiPrompt\Support\ArticleGenerationModePreference::resolveForRun(
                $settings,
                (int) ($run->user_id ?? 0),
            );
            $execution = AiCostPolicyScope::run(
                $policy,
                fn () => $this->taskExecution->execute(
                    $run,
                    $taskId,
                    markCompleted: false,
                    forcedArticleId: null,
                    forceRetry: true,
                ),
            );
        } catch (\Throwable $exception) {
            RuntimeLogger::error('seo.content_project_run.article.exception', [
                'run_id' => (int) $run->id,
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
                'class' => $exception::class,
            ]);

            $run->refresh();
            if ($this->cancellationGuard->isStopRequested($run)) {
                return $this->cancelledResult(
                    $run,
                    $taskId,
                    $runItemId,
                    'Cancelled during article execution.',
                );
            }

            $retryMeta = ContentProjectTransientAiRetryPolicy::fromException($exception);
            if ($retryMeta !== null) {
                return $this->deferredTransientResult($run, $taskId, $runItemId, $exception->getMessage(), $retryMeta);
            }

            return new ArticleExecutionResult(
                runId: (int) $run->id,
                taskId: $taskId,
                runItemId: $runItemId,
                status: ContentProjectArticleSemanticStatus::Failed,
                articleId: null,
                message: $exception->getMessage(),
                errorCode: ContentProjectErrorCode::ExternalWorkflowFailed->value,
                payload: [],
            );
        }

        $run->refresh();
        $itemRow = $execution->toLegacyItemRow();
        $resolvedItemId = $runItemId
            ?? $execution->runItemId
            ?? $this->resolveRunItemId($run, $taskId);

        // Cooperative boundary after provider/workflow returns.
        if ($this->cancellationGuard->isStopRequested($run)) {
            if ($execution->success) {
                return new ArticleExecutionResult(
                    runId: (int) $run->id,
                    taskId: $taskId,
                    runItemId: $resolvedItemId,
                    status: $execution->toArticleSemanticStatus(),
                    articleId: $execution->articleId,
                    message: $execution->message,
                    errorCode: $execution->errorCode,
                    payload: $itemRow,
                );
            }

            return $this->cancelledResult(
                $run,
                $taskId,
                $resolvedItemId,
                'Cancelled after workflow return — output discarded for non-success.',
            );
        }

        if (! $execution->success && ! $execution->cancelled) {
            $retryMeta = ContentProjectTransientAiRetryPolicy::fromFailedItemRow($itemRow);
            if ($retryMeta !== null) {
                return $this->deferredTransientResult(
                    $run,
                    $taskId,
                    $resolvedItemId,
                    $execution->message !== '' ? $execution->message : (string) ($itemRow['message'] ?? ''),
                    $retryMeta,
                    $itemRow,
                );
            }
        }

        return new ArticleExecutionResult(
            runId: (int) $run->id,
            taskId: $taskId,
            runItemId: $resolvedItemId,
            status: $execution->toArticleSemanticStatus(),
            articleId: $execution->articleId,
            message: $execution->message,
            errorCode: $execution->errorCode,
            payload: $itemRow,
        );
    }

    /**
     * @param  array<string, mixed>  $retryMeta
     * @param  array<string, mixed>  $itemRow
     */
    private function deferredTransientResult(
        SeoProjectRun $run,
        int $taskId,
        ?int $runItemId,
        string $message,
        array $retryMeta,
        array $itemRow = [],
    ): ArticleExecutionResult {
        $item = $runItemId !== null && $runItemId > 0
            ? SeoProjectRunItem::query()->find($runItemId)
            : null;
        $currentAttempt = $item instanceof SeoProjectRunItem ? max(1, (int) $item->attempt) : 1;
        $nextAttempt = $currentAttempt + 1;

        if ($currentAttempt >= ContentProjectTransientAiRetryPolicy::MAX_TRANSIENT_ARTICLE_ATTEMPTS) {
            RuntimeLogger::warning('content_project.ai_retry_exhausted', [
                'run_id' => (int) $run->id,
                'task_id' => $taskId,
                'run_item_id' => $runItemId,
                'attempts' => $currentAttempt,
                'message' => $message,
            ]);

            return new ArticleExecutionResult(
                runId: (int) $run->id,
                taskId: $taskId,
                runItemId: $runItemId,
                status: ContentProjectArticleSemanticStatus::Failed,
                articleId: $item instanceof SeoProjectRunItem && $item->article_id !== null
                    ? (int) $item->article_id
                    : null,
                message: $message,
                errorCode: ContentProjectErrorCode::ExternalWorkflowFailed->value,
                payload: array_merge($itemRow, $retryMeta, [
                    ContentProjectTransientAiRetryPolicy::PAYLOAD_FLAG => false,
                    'ai_transient_retry_exhausted' => true,
                    'attempts' => $currentAttempt,
                ]),
            );
        }

        $delay = ContentProjectTransientAiRetryPolicy::delaySeconds(
            $nextAttempt,
            isset($retryMeta['retry_after_seconds']) ? (int) $retryMeta['retry_after_seconds'] : null,
        );
        $deferMessage = ContentProjectTransientAiRetryPolicy::deferMessage($delay);

        if ($item instanceof SeoProjectRunItem) {
            $item->update([
                'status' => SeoProjectRunItemStatus::Pending->value,
                'started_at' => null,
                'finished_at' => null,
                'message' => $deferMessage,
                'error_message' => null,
            ]);
        }

        RuntimeLogger::info('content_project.ai_retry_scheduled', [
            'run_id' => (int) $run->id,
            'task_id' => $taskId,
            'run_item_id' => $runItemId,
            'current_attempt' => $currentAttempt,
            'next_attempt' => $nextAttempt,
            'delay_seconds' => $delay,
            'exhaustion_kind' => $retryMeta['exhaustion_kind'] ?? null,
            'failed_hook' => $retryMeta['failed_hook'] ?? null,
        ]);

        return new ArticleExecutionResult(
            runId: (int) $run->id,
            taskId: $taskId,
            runItemId: $runItemId,
            status: ContentProjectArticleSemanticStatus::Pending,
            articleId: $item instanceof SeoProjectRunItem && $item->article_id !== null
                ? (int) $item->article_id
                : null,
            message: $deferMessage,
            errorCode: null,
            payload: array_merge($itemRow, $retryMeta, [
                ContentProjectTransientAiRetryPolicy::PAYLOAD_FLAG => true,
                'retry_after_seconds' => $delay,
                'current_attempt' => $currentAttempt,
                'next_attempt' => $nextAttempt,
            ]),
            mayDispatchNextOverride: false,
        );
    }

    private function cancelledResult(
        SeoProjectRun $run,
        int $taskId,
        ?int $runItemId,
        string $message,
    ): ArticleExecutionResult {
        return new ArticleExecutionResult(
            runId: (int) $run->id,
            taskId: $taskId,
            runItemId: $runItemId,
            status: ContentProjectArticleSemanticStatus::Cancelled,
            articleId: null,
            message: $message !== '' ? $message : $this->statusMapper->cancelledArticleErrorMessage(),
            errorCode: ContentProjectErrorCode::TaskCancelled->value,
            payload: [],
        );
    }

    private function resolveRunItemId(SeoProjectRun $run, int $taskId): ?int
    {
        $item = SeoProjectRunItem::query()
            ->where('run_id', (int) $run->id)
            ->where('task_id', $taskId)
            ->articleExecution()
            ->orderByDesc('id')
            ->first();

        return $item instanceof SeoProjectRunItem ? (int) $item->id : null;
    }
}
