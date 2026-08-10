<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Services\Publishing;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishReconcileResult;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishingWordPressReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\WordPress\Services\SideEffect\SystemWordPressContext;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use App\Support\RuntimeLogger;
use Throwable;

/**
 * Safe reconcile for CP tasks that already exist on WordPress but Laravel stayed open.
 */
final class PublishingQueueRemoteReconcileService
{
    public const CLASS_REMOTE_PUBLISHED_MATCHING = 'remote_published_matching';

    public const CLASS_REMOTE_DRAFT_MATCHING = 'remote_draft_matching';

    public const CLASS_REMOTE_MISSING = 'remote_missing';

    public const CLASS_REMOTE_AMBIGUOUS = 'remote_ambiguous';

    public const CLASS_LOCAL_ALREADY_PUBLISHED = 'local_already_published';

    public function __construct(
        private readonly PublishingWordPressReconciler $reconciler,
        private readonly ContentProjectPublishingQueueService $queue,
        private readonly WordPressArticleSyncService $syncService,
    ) {}

    /**
     * @param  list<int>  $taskIds
     * @return list<array<string, mixed>>
     */
    public function classifyTasks(array $taskIds, bool $dryRun = true): array
    {
        $rows = [];
        foreach ($taskIds as $taskId) {
            $rows[] = $this->classifyOne((int) $taskId, $dryRun);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function classifyOne(int $taskId, bool $dryRun = true): array
    {
        $task = SeoProjectTask::query()->with('article')->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            return [
                'task_id' => $taskId,
                'classification' => self::CLASS_REMOTE_AMBIGUOUS,
                'action' => 'skip',
                'message' => 'Task not found.',
                'dry_run' => $dryRun,
            ];
        }

        $status = (string) ($task->publish_queue_status ?? '');
        if ($status === ContentProjectPublishQueueStatus::Published->value) {
            return [
                'task_id' => $taskId,
                'article_id' => (int) ($task->article_id ?? 0),
                'classification' => self::CLASS_LOCAL_ALREADY_PUBLISHED,
                'action' => 'none',
                'message' => 'Already published locally.',
                'dry_run' => $dryRun,
            ];
        }

        $result = $this->reconciler->reconcile($task);
        $classification = $this->mapReconcileToClass($result);
        $action = 'none';
        $applied = false;

        if ($classification === self::CLASS_REMOTE_PUBLISHED_MATCHING) {
            $action = 'mark_published';
            if (! $dryRun) {
                $article = $task->article;
                if ($article instanceof SeoArticle && $result->wpPostId !== null && $result->wpPostId > 0) {
                    if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
                        $article->forceFill(['wp_post_id' => $result->wpPostId])->saveQuietly();
                    }
                    $permalink = trim((string) ($result->permalink ?? ''));
                    if ($permalink !== '') {
                        $article->articleMetas()->updateOrCreate(
                            ['meta_key' => 'wp_permalink'],
                            ['meta_value' => $permalink],
                        );
                    }
                }
                $this->queue->markPublishedFromReconcile($task->fresh() ?? $task);
                $applied = true;
                RuntimeLogger::info('publishing.reconcile_marked_published', [
                    'task_id' => $taskId,
                    'article_id' => (int) ($task->article_id ?? 0),
                    'wp_post_id' => $result->wpPostId,
                    'via' => 'remote_reconcile',
                ]);

                app(\Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ContentProjectDomainEvents::class)
                    ->dispatchAfterCommit(new \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Events\ArticlePublished(
                        projectId: (int) ($task->project_id ?? 0),
                        itemId: $taskId,
                        articleId: (int) ($task->article_id ?? 0),
                        wpPostId: (int) ($result->wpPostId ?? 0),
                    ));
            }
        } elseif ($classification === self::CLASS_REMOTE_MISSING) {
            $action = 'keep_retry_safe';
        } elseif ($classification === self::CLASS_REMOTE_AMBIGUOUS) {
            $action = 'manual_review';
        } elseif ($classification === self::CLASS_REMOTE_DRAFT_MATCHING) {
            $action = 'manual_review';
        }

        return [
            'task_id' => $taskId,
            'article_id' => (int) ($task->article_id ?? 0),
            'project_id' => (int) ($task->project_id ?? 0),
            'local_status' => $status,
            'classification' => $classification,
            'action' => $action,
            'applied' => $applied,
            'wp_post_id' => $result->wpPostId,
            'permalink' => $result->permalink,
            'remote_status' => $result->remoteStatus,
            'message' => $result->message !== '' ? $result->message : $classification,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Update existing WP post content only (no create). Dry-run reports whether HTML differs after repair.
     *
     * @return array<string, mixed>
     */
    public function resyncContent(int $taskId, bool $dryRun = true): array
    {
        $task = SeoProjectTask::query()->with('article')->find($taskId);
        if (! $task instanceof SeoProjectTask) {
            return ['task_id' => $taskId, 'ok' => false, 'message' => 'Task not found.', 'dry_run' => $dryRun];
        }

        $article = $task->article;
        if (! $article instanceof SeoArticle) {
            return ['task_id' => $taskId, 'ok' => false, 'message' => 'Article missing.', 'dry_run' => $dryRun];
        }

        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            return [
                'task_id' => $taskId,
                'article_id' => (int) $article->id,
                'ok' => false,
                'message' => 'No wp_post_id — refuse create.',
                'dry_run' => $dryRun,
            ];
        }

        $prepared = $this->syncService->prepareEditorSyncPayload($article);
        $outgoing = (string) ($prepared['post_content'] ?? '');
        $rawBody = (string) ($article->body ?? '');
        $contentChanged = $outgoing !== '' && $outgoing !== $rawBody;

        if ($dryRun) {
            return [
                'task_id' => $taskId,
                'article_id' => (int) $article->id,
                'wp_post_id' => $wpPostId,
                'ok' => true,
                'would_update' => true,
                'content_differs_from_body' => $contentChanged,
                'outgoing_has_inline_spaces' => str_contains($outgoing, ' <strong>') || str_contains($outgoing, '</strong> '),
                'message' => 'Dry-run: would update existing post content only.',
                'dry_run' => true,
            ];
        }

        try {
            $siteId = (int) ($article->site_id ?? $task->site_id ?? 0);
            $sideEffect = new SystemWordPressContext(
                requestId: 'publish-content-resync-'.$taskId,
                articleId: (int) $article->id,
                siteId: $siteId,
                reason: 'publishing.content_resync',
                correlationId: 'task-'.$taskId,
            );
            $result = $this->syncService->syncForArticle($article, $sideEffect);
            $ok = (bool) ($result['success'] ?? false);

            return [
                'task_id' => $taskId,
                'article_id' => (int) $article->id,
                'wp_post_id' => $wpPostId,
                'ok' => $ok,
                'message' => (string) ($result['message'] ?? ($ok ? 'Updated.' : 'Sync failed.')),
                'dry_run' => false,
            ];
        } catch (Throwable $e) {
            return [
                'task_id' => $taskId,
                'article_id' => (int) $article->id,
                'wp_post_id' => $wpPostId,
                'ok' => false,
                'message' => $e->getMessage(),
                'dry_run' => false,
            ];
        }
    }

    private function mapReconcileToClass(PublishReconcileResult $result): string
    {
        if ($result->isPublished()) {
            return self::CLASS_REMOTE_PUBLISHED_MATCHING;
        }

        $status = strtolower((string) ($result->remoteStatus ?? ''));
        if ($result->wpPostId !== null && $result->wpPostId > 0 && in_array($status, ['draft', 'pending', 'private'], true)) {
            return self::CLASS_REMOTE_DRAFT_MATCHING;
        }

        if ($result->outcome === PublishReconcileResult::OUTCOME_NOT_PUBLISHED) {
            return self::CLASS_REMOTE_MISSING;
        }

        return self::CLASS_REMOTE_AMBIGUOUS;
    }
}
