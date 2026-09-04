<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\RunEngine;

use Omnichannel\Addons\AiPrompt\Support\AiCostPolicy;
use Omnichannel\Addons\AiPrompt\Support\AiCostPolicyScope;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectArticleSemanticStatus;
use Omnichannel\Addons\ContentProjects\Enums\ContentProjectErrorCode;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRunItem;
use Omnichannel\Addons\Content\Support\RunEngine\ArticleExecutionResult;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunStatusMapper;
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
            $policy = AiCostPolicy::tryFromMixed($settings[AiCostPolicy::SETTING_KEY] ?? null);
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
