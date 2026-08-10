<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Enums\ContentProjectPublishQueueStatus;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncFlagService;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncPublishedArticleToWordPressCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\Publishing\Services\Publishing\PostPublishWordPressPostReconciler;
use Omnichannel\Addons\Publishing\Services\Publishing\PostPublishWordPressSyncEligibility;
use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class SyncPublishedArticleToWordPressHandler implements ContentProjectCommandHandler
{
    public const META_POST_PUBLISH_SYNC_ERROR = 'post_publish_wp_sync_error';

    public const META_POST_PUBLISH_SYNC_OPERATION_ID = 'post_publish_wp_sync_operation_id';

    public const META_LAST_POST_PUBLISH_SYNC_AT = 'last_post_publish_wp_sync_at';

    public function __construct(
        private readonly PostPublishWordPressSyncEligibility $eligibility,
        private readonly PostPublishWordPressPostReconciler $reconciler,
        private readonly WordPressArticleSyncService $articleSync,
        private readonly ArticleWordPressSyncFlagService $syncFlags,
        private readonly ArticleLastSavedTimestampService $lastSavedTimestamps,
        private readonly ContentProjectTenantGuard $tenantGuard,
    ) {}

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        if (! $command instanceof SyncPublishedArticleToWordPressCommand) {
            throw new InvalidArgumentException('Expected SyncPublishedArticleToWordPressCommand.');
        }

        $article = SeoArticle::query()->find($command->articleId);
        if (! $article instanceof SeoArticle) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::ITEMS_NOT_FOUND,
                'Article not found.',
            );
        }

        $gate = $this->eligibility->evaluate($article);
        if (! ($gate['allowed'] ?? false)) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_BLOCKED,
                (string) ($gate['message'] ?? 'Không đủ điều kiện đồng bộ post-publish.'),
                projectId: $command->projectRef,
                metadata: [
                    'article_id' => (int) $article->id,
                    'block_code' => (string) ($gate['code'] ?? ''),
                    'publish_queue_status_unchanged' => true,
                ],
            );
        }

        /** @var SeoProjectTask $task */
        $task = $gate['task'];
        $projectId = (int) ($task->project_id ?? $command->projectRef ?? 0) ?: null;
        $itemId = (int) ($task->id ?? $command->itemRef ?? 0);

        try {
            $project = $task->project;
            if ($project === null) {
                $task->loadMissing('project');
                $project = $task->project;
            }
            if ($project !== null) {
                $this->tenantGuard->assertCanAccessProject($project, $actor);
            }
        } catch (Throwable $e) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::FORBIDDEN,
                $e->getMessage(),
                projectId: $projectId,
            );
        }

        $lockKey = 'seo-post-publish-sync-article-'.(int) $article->id;
        $lock = Cache::lock($lockKey, 180);
        if (! $lock->get()) {
            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::LOCK_BUSY,
                'Đang có lượt đồng bộ cập nhật khác cho bài này.',
                projectId: $projectId,
                affectedItemIds: $itemId > 0 ? [$itemId] : [],
                metadata: ['publish_queue_status_unchanged' => true],
            );
        }

        $attemptId = (string) Str::uuid();
        $actorUserId = (int) ($actor->actorId ?? 0);

        try {
            $article = $article->fresh() ?? $article;
            $gate = $this->eligibility->evaluate($article);
            if (! ($gate['allowed'] ?? false)) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_BLOCKED,
                    (string) ($gate['message'] ?? 'Không đủ điều kiện đồng bộ post-publish.'),
                    projectId: $projectId,
                    metadata: [
                        'block_code' => (string) ($gate['code'] ?? ''),
                        'publish_queue_status_unchanged' => true,
                    ],
                );
            }

            $sideEffect = new ManualWordPressContext(
                userId: max(1, $actorUserId),
                requestId: $attemptId,
                articleId: (int) $article->id,
                siteId: (int) ($article->site_id ?? 0),
                reason: 'post_publish_sync:'.$attemptId,
                correlationId: (string) ($actor->correlationId ?? $attemptId),
            );

            $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            if ($wpPostId <= 0) {
                $reconcile = $this->reconciler->reconcile($article, $sideEffect);
                $outcome = (string) ($reconcile['outcome'] ?? '');
                if ($outcome === PostPublishWordPressPostReconciler::OUTCOME_NOT_FOUND
                    || $outcome === PostPublishWordPressPostReconciler::OUTCOME_PROBE_FAILED
                ) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_BLOCKED,
                        (string) ($reconcile['message'] ?? 'Không tìm thấy bài WordPress đã xuất bản. Hãy đối soát trước khi đồng bộ.'),
                        projectId: $projectId,
                        affectedItemIds: $itemId > 0 ? [$itemId] : [],
                        metadata: [
                            'reconcile_outcome' => $outcome,
                            'create_post_called' => false,
                            'publish_queue_status_unchanged' => true,
                        ],
                    );
                }
                if ($outcome === PostPublishWordPressPostReconciler::OUTCOME_AMBIGUOUS) {
                    return ContentProjectActionResult::fail(
                        ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_BLOCKED,
                        (string) ($reconcile['message'] ?? 'Tìm thấy nhiều bài WordPress phù hợp. Cần chọn bài đúng trước khi đồng bộ.'),
                        projectId: $projectId,
                        affectedItemIds: $itemId > 0 ? [$itemId] : [],
                        metadata: [
                            'reconcile_outcome' => $outcome,
                            'match_count' => (int) ($reconcile['match_count'] ?? 0),
                            'create_post_called' => false,
                            'publish_queue_status_unchanged' => true,
                        ],
                    );
                }
                $article = $article->fresh() ?? $article;
                $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0);
            }

            if ($wpPostId <= 0) {
                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_BLOCKED,
                    'Không tìm thấy bài WordPress đã xuất bản. Hãy đối soát trước khi đồng bộ.',
                    projectId: $projectId,
                    metadata: ['create_post_called' => false, 'publish_queue_status_unchanged' => true],
                );
            }

            $syncResult = $this->articleSync->updatePublishedArticleOnly(
                $article,
                $sideEffect,
                $command->seoOverride,
            );

            if (! ($syncResult['success'] ?? false)) {
                $this->storePostPublishError($article, (string) ($syncResult['message'] ?? 'sync failed'), $attemptId);
                $this->notifySyncFailure($actorUserId, $article, $attemptId, (string) ($syncResult['message'] ?? ''));

                return ContentProjectActionResult::fail(
                    ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_FAILED,
                    'Không thể đồng bộ thay đổi lên WordPress. Bài đã xuất bản vẫn được giữ nguyên.',
                    projectId: $projectId,
                    affectedItemIds: $itemId > 0 ? [$itemId] : [],
                    metadata: [
                        'article_id' => (int) $article->id,
                        'wp_post_id' => $wpPostId,
                        'create_post_called' => (bool) ($syncResult['create_post_called'] ?? false),
                        'detail' => (string) ($syncResult['message'] ?? ''),
                        'operation_id' => $attemptId,
                        'publish_queue_status' => ContentProjectPublishQueueStatus::Published->value,
                        'publish_queue_status_unchanged' => true,
                        'wordpress_url' => $this->resolvePermalink($article),
                    ],
                );
            }

            $returnedWp = (int) ($syncResult['returned_wp_post_id'] ?? $syncResult['canonical_wp_post_id'] ?? $wpPostId);
            $this->persistSuccess($article, $task, $returnedWp, $attemptId, $command->contentHash);

            RuntimeLogger::info('post_publish_wp.synced', [
                'article_id' => (int) $article->id,
                'wp_post_id' => $returnedWp,
                'task_id' => $itemId > 0 ? $itemId : null,
                'create_post_called' => false,
                'attempt_id' => $attemptId,
                'initiated_from' => $command->initiatedFrom,
            ]);

            $permalink = $this->resolvePermalink($article->fresh() ?? $article);

            return ContentProjectActionResult::ok(
                ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNCED,
                'Đã đồng bộ bài viết lên WordPress.',
                projectId: $projectId,
                affectedItemIds: $itemId > 0 ? [$itemId] : [],
                metadata: [
                    'article_id' => (int) $article->id,
                    'wp_post_id' => $returnedWp,
                    'create_post_called' => false,
                    'operation_id' => $attemptId,
                    'publish_queue_status' => ContentProjectPublishQueueStatus::Published->value,
                    'publish_published_at' => $task->publish_published_at?->toIso8601String(),
                    'last_synced_at' => now()->toIso8601String(),
                    'wordpress_url' => $permalink,
                    'open_wordpress_action' => $permalink !== '' ? 'Mở bài WordPress' : null,
                    'outgoing_post_content_sample' => $this->sampleContent(
                        (string) ($syncResult['outgoing_post_content'] ?? ''),
                    ),
                ],
            );
        } catch (Throwable $e) {
            RuntimeLogger::report($e, [
                'endpoint' => 'post_publish_wp.sync',
                'article_id' => (int) $article->id,
            ]);
            $this->storePostPublishError($article, $e->getMessage(), $attemptId);

            return ContentProjectActionResult::fail(
                ContentProjectActionCodes::PUBLISHED_ARTICLE_WP_SYNC_FAILED,
                'Không thể đồng bộ thay đổi lên WordPress. Bài đã xuất bản vẫn được giữ nguyên.',
                projectId: $projectId,
                metadata: [
                    'operation_id' => $attemptId,
                    'publish_queue_status_unchanged' => true,
                    'detail' => $e->getMessage(),
                ],
            );
        } finally {
            $lock->release();
        }
    }

    private function persistSuccess(
        SeoArticle $article,
        SeoProjectTask $task,
        int $wpPostId,
        string $operationId,
        ?string $contentHash,
    ): void {
        $fresh = $article->fresh() ?? $article;
        $queueBefore = (string) ($task->publish_queue_status ?? '');

        $this->lastSavedTimestamps->touchSynced($fresh);
        $this->syncFlags->clearLocalEditPending($fresh);
        $hash = trim((string) ($contentHash ?? ''));
        if ($hash === '') {
            $hash = trim((string) ($this->syncFlags->localContentHash($fresh) ?? ''));
        }
        if ($hash !== '') {
            $this->syncFlags->rememberPublishedContentHash($fresh, $hash);
        }

        $fresh->articleMetas()->where('meta_key', self::META_POST_PUBLISH_SYNC_ERROR)->delete();
        $fresh->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_POST_PUBLISH_SYNC_OPERATION_ID],
            ['meta_value' => $operationId],
        );
        $fresh->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_LAST_POST_PUBLISH_SYNC_AT],
            ['meta_value' => now()->toIso8601String()],
        );

        // Never mutate Publishing Queue lifecycle on editorial sync success.
        $taskFresh = $task->fresh() ?? $task;
        if ((string) ($taskFresh->publish_queue_status ?? '') !== ContentProjectPublishQueueStatus::Published->value
            && $queueBefore === ContentProjectPublishQueueStatus::Published->value
        ) {
            $taskFresh->forceFill([
                'publish_queue_status' => ContentProjectPublishQueueStatus::Published->value,
            ])->saveQuietly();
        }

        unset($wpPostId); // verified earlier; sticky identity already on article
    }

    private function storePostPublishError(SeoArticle $article, string $message, string $operationId): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_POST_PUBLISH_SYNC_ERROR],
            ['meta_value' => mb_substr($message, 0, 2000)],
        );
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => self::META_POST_PUBLISH_SYNC_OPERATION_ID],
            ['meta_value' => $operationId],
        );
    }

    private function notifySyncFailure(int $actorUserId, SeoArticle $article, string $operationId, string $detail): void
    {
        if ($actorUserId <= 0 || ! \Illuminate\Support\Facades\Schema::hasTable('notifications')) {
            return;
        }

        $user = \App\Models\User::query()->find($actorUserId);
        if ($user === null) {
            return;
        }

        try {
            \Filament\Notifications\Notification::make()
                ->title('Không thể đồng bộ thay đổi lên WordPress')
                ->body('Bài đã xuất bản vẫn được giữ nguyên. Operation: '.$operationId.($detail !== '' ? ' — '.$detail : ''))
                ->danger()
                ->sendToDatabase($user);
        } catch (Throwable $e) {
            RuntimeLogger::warning('post_publish_wp.notify_failed', [
                'article_id' => (int) $article->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolvePermalink(SeoArticle $article): string
    {
        $meta = trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')
            ?->meta_value ?? ''));
        if ($meta !== '') {
            return $meta;
        }

        $article->loadMissing('articleMetas');

        return trim((string) ($article->articleMetas
            ->firstWhere('meta_key', 'wp_permalink')
            ?->meta_value ?? ''));
    }

    private function sampleContent(string $html): string
    {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');

        return mb_substr($plain, 0, 160);
    }
}
