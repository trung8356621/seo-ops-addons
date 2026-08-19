<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassifier;
use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Omnichannel\Addons\WordPress\Services\SyncArticleToWordPressPipeline;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;
use Throwable;

/**
 * When async automation worker never starts (DELIVERY_WORKER_STALLED) but the item is
 * already overdue, publish inline during stuck recovery instead of another retry_wait loop.
 */
final class PublishingOverdueInlineDeliveryService
{
    /** Minimum dispatch attempts without publisher_started_at before inline fallback. */
    private const MIN_STALLED_DISPATCH_COUNT = 2;

    public function __construct(
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly SyncArticleToWordPressPipeline $pipeline,
        private readonly WordPressArticleSyncService $articleSync,
        private readonly PublishFailureClassifier $classifier,
        private readonly ContentPublishingStrategyResolver $strategyResolver = new ContentPublishingStrategyResolver,
    ) {}

    public function shouldAttemptInline(SeoProjectTask $task): bool
    {
        if ((int) ($task->publish_attempt_count ?? 0) > 0) {
            return false;
        }

        $dispatchCount = (int) ($task->dispatch_count ?? 0);
        if ($dispatchCount < self::MIN_STALLED_DISPATCH_COUNT) {
            return false;
        }

        if ($this->isScheduleOverdue($task)) {
            return true;
        }

        if ($task->next_publish_retry_at !== null && $task->next_publish_retry_at->lte(now('UTC'))) {
            return true;
        }

        return $dispatchCount >= 3;
    }

    /**
     * @return 'published'|'retry_wait'|'failed'|null null = caller should use default stall recovery
     */
    public function attempt(SeoProjectTask $task, string $batchId): ?string
    {
        if (! $this->shouldAttemptInline($task)) {
            return null;
        }

        $task = $task->fresh(['article', 'project']) ?? $task;
        $article = $task->article;
        if (! $article instanceof SeoArticle) {
            return null;
        }

        $attemptToken = trim((string) ($task->publish_attempt_token ?? ''));
        $start = $this->queue->beginPublisherAttempt(
            $task,
            $attemptToken !== '' ? $attemptToken : null,
        );
        if ($start === 'superseded' || $start === 'not_found') {
            return null;
        }

        $task = $task->fresh(['article', 'project']) ?? $task;
        $attemptToken = trim((string) ($task->publish_attempt_token ?? ''));
        $taskId = (int) $task->getKey();
        $articleId = (int) $article->id;
        $siteId = (int) ($article->site_id ?? 0);
        $actorUserId = $this->resolveActorUserId($task);

        $strategy = $this->strategyResolver->resolve($task, $article);
        $mode = $strategy->isImmediateUpdate() ? 'update_existing' : 'publish';

        RuntimeLogger::info('publishing.overdue_inline_delivery_started', [
            'task_id' => $taskId,
            'article_id' => $articleId,
            'batch_id' => $batchId,
            'dispatch_count' => (int) ($task->dispatch_count ?? 0),
            'mode' => $mode,
        ]);

        try {
            $sideEffect = new ManualWordPressContext(
                userId: $actorUserId,
                requestId: 'cp-overdue-inline-'.$taskId.'-'.Str::lower(Str::random(8)),
                articleId: $articleId,
                siteId: $siteId,
                reason: 'publishing.overdue_inline_delivery',
                correlationId: $batchId,
            );

            $result = SeoQueueContext::runWpSyncFromQueue(function () use ($article, $sideEffect, $mode): array {
                return $this->pipeline->run($article, $sideEffect, $mode);
            });
        } catch (Throwable $e) {
            RuntimeLogger::warning('publishing.overdue_inline_delivery_exception', [
                'task_id' => $taskId,
                'article_id' => $articleId,
                'batch_id' => $batchId,
                'message' => $e->getMessage(),
            ]);

            return $this->applyFailure($task, $this->classifier->classify($e, [
                'code' => 'overdue_inline_exception',
                'message' => $e->getMessage(),
            ]), $batchId);
        }

        if (! ($result['success'] ?? false)) {
            $message = trim((string) ($result['message'] ?? 'WordPress sync failed.'));
            $code = trim((string) ($result['error_code'] ?? 'overdue_inline_failed'));

            RuntimeLogger::warning('publishing.overdue_inline_delivery_failed', [
                'task_id' => $taskId,
                'article_id' => $articleId,
                'batch_id' => $batchId,
                'error_code' => $code,
                'message' => $message,
            ]);

            return $this->applyFailure($task, $this->classifier->classify(null, [
                'code' => $code !== '' ? $code : 'overdue_inline_failed',
                'message' => $message,
            ]), $batchId);
        }

        $article = $article->fresh() ?? $article;
        $this->articleSync->confirmContentProjectPublishDelivery(
            $article,
            $taskId,
            $attemptToken !== '' ? $attemptToken : null,
        );

        RuntimeLogger::info('publishing.overdue_inline_delivery_published', [
            'task_id' => $taskId,
            'article_id' => $articleId,
            'batch_id' => $batchId,
            'wp_post_id' => (int) ($result['wp_post_id'] ?? $article->wordpressLink?->wp_post_id ?? 0) ?: null,
        ]);

        return 'published';
    }

    private function isScheduleOverdue(SeoProjectTask $task): bool
    {
        $scheduled = $task->scheduled_publish_at;
        if ($scheduled === null) {
            return false;
        }

        return $scheduled->lte(now('UTC'));
    }

    private function resolveActorUserId(SeoProjectTask $task): int
    {
        $project = $task->project;
        if ($project instanceof SeoProject) {
            $ownerId = (int) ($project->user_id ?? 0);
            if ($ownerId > 0) {
                return $ownerId;
            }
        }

        return 1;
    }

    /**
     * @return 'retry_wait'|'failed'
     */
    private function applyFailure(
        SeoProjectTask $task,
        \Omnichannel\Addons\Publishing\Application\Publishing\PublishFailureClassification $classification,
        string $batchId,
    ): string {
        $attempt = max(1, (int) ($task->publish_attempt_count ?? 0));
        $retryPolicy = app(\Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy::class);

        if ($classification->retryable && $retryPolicy->canRetry($attempt)) {
            $nextAt = $this->isScheduleOverdue($task) || ($task->next_publish_retry_at !== null && $task->next_publish_retry_at->lte(now('UTC')))
                ? now('UTC')
                : $retryPolicy->nextRetryAt($attempt, $classification->retryAfter);
            $this->queue->markRetryWait($task->fresh() ?? $task, $classification, $nextAt);

            RuntimeLogger::info('publishing.overdue_inline_retry_scheduled', [
                'task_id' => (int) $task->getKey(),
                'batch_id' => $batchId,
                'next_publish_retry_at' => $nextAt?->toIso8601String(),
            ]);

            return 'retry_wait';
        }

        $this->queue->markFailedFromClassification($task->fresh() ?? $task, $classification);

        return 'failed';
    }
}
