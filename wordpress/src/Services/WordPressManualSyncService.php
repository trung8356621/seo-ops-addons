<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessActionDispatcher;
use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Commerce\Services\ProductReview\ProductReviewAutomationSettingsResolver;
use Omnichannel\Addons\WordPress\Jobs\ManualWordPressSyncJob;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditorBundleApplyService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressSyncEligibility;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncLeaseService;
use Omnichannel\Addons\WordPress\Services\ArticleWpSyncQueueService;
use Omnichannel\Addons\WordPress\Services\ManualSyncContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\PublishProjectItemsNowCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SyncPublishedArticleToWordPressCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectCommandBus;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectArticleMembership;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Omnichannel\Addons\Publishing\Services\Publishing\PostPublishWordPressSyncEligibility;
use Omnichannel\Addons\WordPress\Services\SideEffect\ManualWordPressContext;
use Omnichannel\Addons\WordPress\Services\SideEffect\WordPressExecutionContext;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\Content\Support\ArticleEditorSaveContext;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\User;
use App\Support\RuntimeLogger;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manual WordPress UI entry → domain sync (no Automation Rule / AvailabilityGate).
 * Local persist trước sync đi qua BusinessActionDispatcher (article.content.update).
 */
final class WordPressManualSyncService
{
    public function __construct(
        private readonly ArticleEditorBundleApplyService $bundleApply,
        private readonly ArticleWpSyncQueueService $syncQueue,
        private readonly ArticleWpSyncLeaseService $lease,
        private readonly BusinessActionDispatcher $actions,
        private readonly ContentProjectArticleMembership $contentProjectMembership,
        private readonly ContentProjectCommandBus $contentProjectCommandBus,
        private readonly ArticleWordPressSyncEligibility $syncEligibility,
        private readonly WordPressArticleSyncService $articleSync,
        private readonly ArticleWordPressBusinessSequence $businessSequence,
        private readonly ProductReviewAutomationSettingsResolver $reviewSettingsResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $bundle
     * @return array<string, mixed>
     */
    public function enqueueFromEditorBundle(SeoArticle $article, array $bundle, User $actor, string $initiatedFrom): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);
        abort_unless($actor->getKey() > 0, 403);

        // Content Project create items wait for Publishing Queue; rewrite/improve updates existing WP posts only.
        if ($this->contentProjectMembership->belongsToContentProject($article)) {
            $eligibility = $this->syncEligibility->evaluate($article);
            if (! ($eligibility['allowed'] ?? false)) {
                return $this->blocked(
                    (string) ($eligibility['reason'] ?? 'content_project_manual_sync_forbidden'),
                    (string) ($eligibility['message'] ?? __('seo-content-ai::filament.automation.content_project_manual_sync_forbidden')),
                    [
                        'block_code' => (string) ($eligibility['reason'] ?? 'not_published'),
                        'post_publish_eligible' => false,
                    ],
                );
            }

            if (($eligibility['mode'] ?? null) === ArticleWordPressSyncEligibility::MODE_REWRITE_UPDATE_EXISTING) {
                return $this->syncRewriteExistingFromEditorBundle($article, $bundle, $actor, $initiatedFrom, $eligibility);
            }

            return $this->syncPublishedFromEditorBundle($article, $bundle, $actor, $initiatedFrom, $eligibility);
        }

        $bundle = $this->syncQueue->applyPublishImmediatelyToBundle($bundle);
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $fresh = $article->fresh() ?? $article;
        $persist = $this->actions->dispatch(
            'article.content.update',
            [
                'article_id' => (int) $fresh->id,
                'content' => $html,
                'title' => $context->title,
                'slug' => $context->slug,
                'status' => $context->status,
                'post_type' => $context->postType,
                'visibility' => $context->visibility,
                'publish_day' => $context->publishDay,
                'publish_month' => $context->publishMonth,
                'publish_year' => $context->publishYear,
                'publish_hour' => $context->publishHour,
                'publish_minute' => $context->publishMinute,
                'seo_meta_description' => $context->seoMetaDescription,
                'focus_keyword' => $context->focusKeyword,
            ],
            ActionContext::fromArray([
                'origin' => 'manual_wordpress_sync',
                'correlation_id' => Str::uuid()->toString(),
                'actor_id' => (int) $actor->id,
                'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
            ]),
        );

        if (! $persist->success) {
            return [
                'success' => false,
                'status' => 'blocked',
                'message' => (string) ($persist->error['message'] ?? 'Không lưu được bài trước khi sync WordPress.'),
            ];
        }

        $article = $fresh->fresh() ?? $fresh;

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => $this->standalonePipelineMode($article),
                'seo_override' => $context->seoPayloadForWordPress(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function resyncQueued(SeoArticle $article, User $actor, string $initiatedFrom): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        if ($this->contentProjectMembership->belongsToContentProject($article)) {
            $eligibility = app(PostPublishWordPressSyncEligibility::class)->evaluate($article);
            if (! ($eligibility['allowed'] ?? false)) {
                return $this->blocked(
                    'content_project_manual_sync_forbidden',
                    (string) __('seo-content-ai::filament.automation.content_project_manual_sync_forbidden'),
                    [
                        'block_code' => (string) ($eligibility['code'] ?? 'not_published'),
                        'post_publish_eligible' => false,
                    ],
                );
            }

            $task = $eligibility['task'] ?? null;
            $result = $this->contentProjectCommandBus->dispatch(
                new SyncPublishedArticleToWordPressCommand(
                    articleId: (int) $article->id,
                    projectRef: $task !== null ? (int) ($task->project_id ?? 0) ?: null : null,
                    itemRef: $task !== null ? (int) $task->id : null,
                    initiatedFrom: $initiatedFrom,
                ),
                ActorContext::user((int) $actor->id, (int) ($article->site_id ?? 0) ?: null),
            );

            return $this->mapPostPublishCommandResult($result, $article);
        }

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            ['mode' => $this->standalonePipelineMode($article)],
        );
    }

    /**
     * @param  array<string, mixed>|null  $seoOverride
     * @return array<string, mixed>
     */
    public function publishNow(SeoArticle $article, User $actor, string $initiatedFrom, ?array $seoOverride = null): array
    {
        abort_if(SeoAccessControl::isContentManager(), 403);
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        // Content Project: publish qua Command Bus (PublishProjectItemsNowCommand).
        $task = $this->contentProjectMembership->activeTaskForArticle($article);
        if ($task !== null) {
            $siteId = (int) ($article->site_id ?? 0) ?: null;
            $result = $this->contentProjectCommandBus->dispatch(
                new PublishProjectItemsNowCommand(
                    projectRef: (int) ($task->project_id ?? 0),
                    itemRefs: [(int) $task->id],
                ),
                ActorContext::user((int) $actor->id, $siteId),
            );

            RuntimeLogger::info('content_project_publish_queued', [
                'article_id' => (int) $article->id,
                'task_id' => (int) $task->id,
                'project_id' => (int) ($task->project_id ?? 0) ?: null,
                'actor_id' => (int) $actor->id,
                'initiated_from' => $initiatedFrom,
                'result_code' => $result->code,
            ]);

            if (! $result->success) {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'queued' => false,
                    'workspace_only' => false,
                    'message' => $result->message,
                    'notification' => [
                        'title' => __('seo-content-ai::filament.automation.content_project_publish_queued_title'),
                        'body' => $result->message,
                        'status' => 'danger',
                    ],
                ];
            }

            return [
                'success' => true,
                'status' => 'queued_for_publish',
                'queued' => true,
                'workspace_only' => false,
                'message' => __('seo-content-ai::filament.automation.content_project_publish_queued'),
                'notification' => [
                    'title' => __('seo-content-ai::filament.automation.content_project_publish_queued_title'),
                    'body' => __('seo-content-ai::filament.automation.content_project_publish_queued'),
                    'status' => 'success',
                ],
            ];
        }

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => 'publish',
                'seo_override' => $seoOverride ?? [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $seoOverride
     * @return array<string, mixed>
     */
    public function syncSeoMeta(SeoArticle $article, User $actor, string $initiatedFrom, array $seoOverride): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => 'seo_meta',
                'seo_override' => $seoOverride,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function syncSlug(SeoArticle $article, User $actor, string $initiatedFrom, string $slug): array
    {
        abort_unless(SeoAccessControl::canSyncArticlesToWordPress(), 403);
        abort_unless(SeoAccessControl::canAccessArticle($article), 403);

        return $this->enqueueManual(
            $article,
            $actor,
            $initiatedFrom,
            [
                'mode' => 'slug',
                'slug' => $slug,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function enqueueManual(
        SeoArticle $article,
        User $actor,
        string $initiatedFrom,
        array $settings,
    ): array {
        $siteId = (int) ($article->site_id ?? 0);
        if ($siteId <= 0) {
            return $this->blocked(
                'CONNECTION_MISSING',
                __('seo-content-ai::filament.automation.manual_sync_no_site'),
            );
        }

        $lockKey = 'manual-wp-sync:'.(int) $article->id;
        [$lock, $acquired] = $this->acquireEnqueueLock($lockKey, 120);
        if (! $acquired) {
            // isActive() tự clear meta mồ côi (pending không có job).
            if ($this->syncQueue->isActive($article)) {
                return $this->deduplicated($this->syncQueue->activeOperation($article) ?? [], $article);
            }

            return $this->blocked(
                'SYNC_IN_PROGRESS',
                __('seo-content-ai::filament.automation.manual_sync_in_progress'),
            );
        }

        try {
            if ($this->syncQueue->isActive($article)) {
                $active = $this->syncQueue->activeOperation($article);

                return $this->deduplicated($active ?? [], $article);
            }

            $manual = ManualSyncContext::make(
                initiatedBy: (int) $actor->getKey(),
                source: $initiatedFrom !== '' ? $initiatedFrom : 'editor',
                articleId: (int) $article->id,
                domainId: $siteId,
            );

            $syncJob = $this->lease->enqueue(
                article: $article,
                source: $manual->source,
                initiatedBy: $manual->initiatedBy,
                requestId: $manual->requestId,
                correlationId: $manual->correlationId,
                settings: $settings,
                auditMeta: $manual->toAuditMeta(),
            );

            if ((string) $syncJob->request_id !== $manual->requestId) {
                return $this->deduplicated($this->lease->toOperationPayload($syncJob), $article);
            }

            ManualWordPressSyncJob::dispatch(
                articleId: (int) $article->id,
                userId: $manual->initiatedBy,
                source: $manual->source,
                requestId: $manual->requestId,
                correlationId: $manual->correlationId,
                domainId: $manual->domainId,
                requestedAt: $manual->requestedAt,
                syncJobId: (int) $syncJob->id,
                settings: $settings,
                auditMeta: $manual->toAuditMeta(),
            )->afterCommit();

            Log::info('manual_wordpress_sync.queued', array_merge($manual->toAuditMeta(), [
                'article_id' => (int) $article->id,
                'sync_id' => (int) $syncJob->id,
                'sync_job_id' => (int) $syncJob->id,
                'site_id' => $siteId,
                'queue_name' => ArticleWpSyncQueueService::QUEUE_NAME,
                'status' => $syncJob->status?->value ?? 'pending',
                'endpoint' => 'manual_wordpress_sync.enqueue',
            ]));

            return [
                'success' => true,
                'status' => 'dispatched',
                'queued' => true,
                'already_queued' => false,
                'message' => __('seo-content-ai::filament.automation.manual_sync_queued'),
                'manual' => true,
                'request_id' => $manual->requestId,
                'correlation_id' => $manual->correlationId,
                'source' => $manual->source,
                'initiated_by' => $manual->initiatedBy,
                'execution_id' => null,
                'rule_code' => null,
                'sync_id' => (int) $syncJob->id,
                'sync_job_id' => (int) $syncJob->id,
                'data' => [
                    'sync_id' => (int) $syncJob->id,
                    'status' => 'queued',
                    'already_queued' => false,
                ],
                'operation' => $this->lease->toOperationPayload($syncJob),
                'notification' => [
                    'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                    'body' => __('seo-content-ai::filament.automation.manual_sync_queued'),
                    'status' => 'success',
                ],
            ];
        } finally {
            if ($lock instanceof Lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // lock may already be released
                }
            }
        }
    }

    /**
     * Prefer database locks. File driver can throw intermittent
     * "Failed to open stream: No such file or directory" on nested hash dirs
     * (permission / cache:clear race). DB lease still serializes enqueue when
     * cache lock cannot be acquired safely.
     *
     * @return array{0: ?Lock, 1: bool} [lock, acquired]
     */
    private function acquireEnqueueLock(string $lockKey, int $seconds): array
    {
        $stores = $this->enqueueLockStores();

        foreach ($stores as $storeName) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $lock = Cache::store($storeName)->lock($lockKey, $seconds);
                    if ($lock->get()) {
                        return [$lock, true];
                    }

                    return [null, false];
                } catch (Throwable $e) {
                    RuntimeLogger::warning('manual_wordpress_sync.lock_failed', [
                        'lock_key' => $lockKey,
                        'store' => $storeName,
                        'attempt' => $attempt,
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);

                    if ($attempt < 2) {
                        usleep(50_000);
                    }
                }
            }
        }

        // Continue without cache lock — ArticleWpSyncLeaseService::enqueue uses DB lockForUpdate.
        return [null, true];
    }

    /**
     * @return list<string>
     */
    private function enqueueLockStores(): array
    {
        $stores = [];
        $default = (string) config('cache.default', 'database');

        if (is_array(config('cache.stores.database'))) {
            $stores[] = 'database';
        }

        if ($default !== '' && $default !== 'database') {
            $stores[] = $default;
        }

        if ($stores === []) {
            $stores[] = $default !== '' ? $default : 'file';
        }

        return array_values(array_unique($stores));
    }

    /**
     * @param  array<string, mixed>  $active
     * @return array<string, mixed>
     */
    private function deduplicated(array $active, SeoArticle $article): array
    {
        $message = __('seo-content-ai::filament.automation.manual_sync_already_queued');
        $operation = $active !== [] ? $active : ($this->syncQueue->activeOperation($article) ?? []);
        $syncId = (int) ($operation['sync_job_id'] ?? $operation['id'] ?? 0);

        return [
            'success' => true,
            'status' => 'deduplicated',
            'queued' => true,
            'already_queued' => true,
            'message' => $message,
            'manual' => true,
            'sync_id' => $syncId > 0 ? $syncId : null,
            'sync_job_id' => $syncId > 0 ? $syncId : null,
            'data' => [
                'sync_id' => $syncId > 0 ? $syncId : null,
                'status' => (string) ($operation['status'] ?? 'queued'),
                'already_queued' => true,
            ],
            'operation' => $operation !== [] ? $operation : null,
            'request_id' => $active['request_id'] ?? $operation['request_id'] ?? null,
            'correlation_id' => $active['correlation_id'] ?? $operation['correlation_id'] ?? null,
            'notification' => [
                'title' => __('seo-content-ai::filament.automation.manual_sync_queued_title'),
                'body' => $message,
                'status' => 'info',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $eligibility
     * @return array<string, mixed>
     */
    private function syncPublishedFromEditorBundle(
        SeoArticle $article,
        array $bundle,
        User $actor,
        string $initiatedFrom,
        array $eligibility,
    ): array {
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $fresh = $article->fresh() ?? $article;
        $persist = $this->actions->dispatch(
            'article.content.update',
            [
                'article_id' => (int) $fresh->id,
                'content' => $html,
                'title' => $context->title,
                'slug' => $context->slug,
                'status' => $context->status,
                'post_type' => $context->postType,
                'visibility' => $context->visibility,
                'publish_day' => $context->publishDay,
                'publish_month' => $context->publishMonth,
                'publish_year' => $context->publishYear,
                'publish_hour' => $context->publishHour,
                'publish_minute' => $context->publishMinute,
                'seo_meta_description' => $context->seoMetaDescription,
                'focus_keyword' => $context->focusKeyword,
            ],
            ActionContext::fromArray([
                'origin' => 'post_publish_wordpress_sync',
                'correlation_id' => Str::uuid()->toString(),
                'actor_id' => (int) $actor->id,
                'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
            ]),
        );

        if (! $persist->success) {
            return [
                'success' => false,
                'status' => 'blocked',
                'queued' => false,
                'workspace_only' => false,
                'message' => (string) ($persist->error['message'] ?? 'Không lưu được bài trước khi đồng bộ WordPress.'),
            ];
        }

        $article = $fresh->fresh() ?? $fresh;
        $task = $eligibility['task'] ?? null;
        $result = $this->contentProjectCommandBus->dispatch(
            new SyncPublishedArticleToWordPressCommand(
                articleId: (int) $article->id,
                projectRef: $task !== null ? (int) ($task->project_id ?? 0) ?: null : null,
                itemRef: $task !== null ? (int) $task->id : null,
                seoOverride: $context->seoPayloadForWordPress(),
                initiatedFrom: $initiatedFrom !== '' ? $initiatedFrom : 'article_editor.post_publish_sync',
            ),
            ActorContext::user((int) $actor->id, (int) ($article->site_id ?? 0) ?: null),
        );

        return $this->mapPostPublishCommandResult($result, $article);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @param  array<string, mixed>  $eligibility
     * @return array<string, mixed>
     */
    private function syncRewriteExistingFromEditorBundle(
        SeoArticle $article,
        array $bundle,
        User $actor,
        string $initiatedFrom,
        array $eligibility,
    ): array {
        $context = ArticleEditorSaveContext::fromBundle($article, $bundle);
        $this->bundleApply->apply($article, $bundle, $context);

        $html = (string) ($bundle['html'] ?? '');
        $fresh = $article->fresh() ?? $article;
        $persist = $this->actions->dispatch(
            'article.content.update',
            [
                'article_id' => (int) $fresh->id,
                'content' => $html,
                'title' => $context->title,
                'slug' => $context->slug,
                'status' => $context->status,
                'post_type' => $context->postType,
                'visibility' => $context->visibility,
                'publish_day' => $context->publishDay,
                'publish_month' => $context->publishMonth,
                'publish_year' => $context->publishYear,
                'publish_hour' => $context->publishHour,
                'publish_minute' => $context->publishMinute,
                'seo_meta_description' => $context->seoMetaDescription,
                'focus_keyword' => $context->focusKeyword,
            ],
            ActionContext::fromArray([
                'origin' => 'rewrite_existing_wordpress_sync',
                'correlation_id' => Str::uuid()->toString(),
                'actor_id' => (int) $actor->id,
                'site_id' => (int) ($fresh->site_id ?? 0) ?: null,
            ]),
        );

        if (! $persist->success) {
            return [
                'success' => false,
                'status' => 'blocked',
                'queued' => false,
                'workspace_only' => false,
                'message' => (string) ($persist->error['message'] ?? 'Không lưu được bài trước khi đồng bộ WordPress.'),
            ];
        }

        $article = $fresh->fresh() ?? $fresh;
        $remotePostId = (int) ($eligibility['remote_post_id'] ?? $article->wordpressLink?->wp_post_id ?? 0);
        if ($remotePostId <= 0 || (int) ($article->wordpressLink?->wp_post_id ?? 0) !== $remotePostId) {
            return $this->blocked(
                'missing_remote_post',
                'Không tìm thấy bài WordPress gốc để đồng bộ.',
                [
                    'block_code' => 'missing_remote_post',
                    'post_publish_eligible' => false,
                    'create_post_called' => false,
                ],
            );
        }

        $operationId = (string) Str::uuid();
        $sideEffect = new ManualWordPressContext(
            userId: max(1, (int) $actor->id),
            requestId: $operationId,
            articleId: (int) $article->id,
            siteId: (int) ($article->site_id ?? 0),
            reason: 'rewrite_existing_sync:'.$operationId,
            correlationId: $operationId,
        );

        $syncResult = $this->articleSync->updatePublishedArticleOnly(
            $article,
            $sideEffect,
            $context->seoPayloadForWordPress(),
        );

        if (! ($syncResult['success'] ?? false)) {
            return [
                'success' => false,
                'status' => 'blocked',
                'queued' => false,
                'workspace_only' => false,
                'close_editor' => false,
                'message' => (string) ($syncResult['message'] ?? 'Không thể đồng bộ thay đổi lên WordPress.'),
                'data' => [
                    'article_id' => (int) $article->id,
                    'wp_post_id' => $remotePostId,
                    'mode' => ArticleWordPressSyncEligibility::MODE_REWRITE_UPDATE_EXISTING,
                    'operation_id' => $operationId,
                    'create_post_called' => false,
                    'publish_queue_status_unchanged' => true,
                    'error_code' => $syncResult['error_code'] ?? null,
                ],
                'notification' => [
                    'title' => __('seo-content-ai::filament.automation.wp_sync_blocked_title'),
                    'body' => (string) ($syncResult['message'] ?? 'Không thể đồng bộ thay đổi lên WordPress.'),
                    'status' => 'danger',
                ],
            ];
        }

        $reviewSideEffect = ManualSyncContext::make(
            initiatedBy: max(1, (int) $actor->id),
            source: $initiatedFrom !== '' ? $initiatedFrom : 'article_editor.rewrite_existing_sync',
            articleId: (int) $article->id,
            domainId: (int) ($article->site_id ?? 0),
            correlationId: $operationId,
            requestId: $operationId,
        )->toSideEffectContext('rewrite_existing_product_reviews');
        $productReview = $this->runProductReviewsAfterArticleSync(
            $article->fresh() ?? $article,
            $reviewSideEffect,
        );

        RuntimeLogger::info('rewrite_existing_wp.synced', [
            'article_id' => (int) $article->id,
            'wp_post_id' => $remotePostId,
            'task_id' => (int) (($eligibility['task'] ?? null)?->id ?? 0) ?: null,
            'item_type' => (string) ($eligibility['item_type'] ?? ''),
            'create_post_called' => false,
            'initiated_from' => $initiatedFrom,
            'operation_id' => $operationId,
            'product_review_create' => $productReview['product_review_create'] ?? null,
            'product_review_sync' => $productReview['product_review_sync'] ?? null,
        ]);

        return [
            'success' => true,
            'status' => 'rewrite_existing_synced',
            'queued' => false,
            'already_queued' => false,
            'workspace_only' => false,
            'close_editor' => false,
            'reload' => false,
            'message' => 'Đã đồng bộ bài viết lại lên WordPress.',
            'data' => [
                'article_id' => (int) $article->id,
                'wp_post_id' => $remotePostId,
                'mode' => ArticleWordPressSyncEligibility::MODE_REWRITE_UPDATE_EXISTING,
                'operation_id' => $operationId,
                'create_post_called' => false,
                'publish_queue_status_unchanged' => true,
                'product_review_create' => $productReview['product_review_create'] ?? null,
                'product_review_sync' => $productReview['product_review_sync'] ?? null,
            ],
            'notification' => [
                'title' => 'Đã đồng bộ bài viết lên WordPress.',
                'body' => 'Đã cập nhật đúng bài WordPress hiện có.',
                'status' => 'success',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPostPublishCommandResult(ContentProjectActionResult $result, SeoArticle $article): array
    {
        $meta = $result->metadata;
        $wpUrl = trim((string) ($meta['wordpress_url'] ?? ''));
        $actions = [];
        if ($wpUrl !== '') {
            $actions[] = [
                'label' => 'Mở bài WordPress',
                'url' => $wpUrl,
            ];
        }

        if ($result->success) {
            return [
                'success' => true,
                'status' => 'post_publish_synced',
                'queued' => false,
                'already_queued' => false,
                'workspace_only' => false,
                'close_editor' => false,
                'reload' => false,
                'message' => $result->message,
                'data' => [
                    'article_id' => (int) $article->id,
                    'wp_post_id' => $meta['wp_post_id'] ?? null,
                    'operation_id' => $meta['operation_id'] ?? null,
                    'publish_queue_status' => $meta['publish_queue_status'] ?? 'published',
                    'create_post_called' => false,
                ],
                'notification' => [
                    'title' => 'Đã đồng bộ bài viết lên WordPress.',
                    'body' => $result->message,
                    'status' => 'success',
                    'actions' => $actions,
                ],
            ];
        }

        return [
            'success' => false,
            'status' => 'blocked',
            'queued' => false,
            'workspace_only' => false,
            'close_editor' => false,
            'message' => $result->message,
            'data' => [
                'article_id' => (int) $article->id,
                'operation_id' => $meta['operation_id'] ?? null,
                'block_code' => $meta['block_code'] ?? $result->code,
                'create_post_called' => (bool) ($meta['create_post_called'] ?? false),
                'publish_queue_status_unchanged' => true,
            ],
            'notification' => [
                'title' => __('seo-content-ai::filament.automation.wp_sync_blocked_title'),
                'body' => $result->message,
                'status' => 'danger',
            ],
        ];
    }

    /**
     * @return array{product_review_create: array<string, mixed>, product_review_sync: array<string, mixed>}
     */
    private function runProductReviewsAfterArticleSync(
        SeoArticle $article,
        WordPressExecutionContext $sideEffect,
    ): array {
        $articleId = (int) $article->id;
        $siteId = (int) ($article->site_id ?? 0);
        $wpPostId = (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null;
        $syncSource = $sideEffect instanceof ManualWordPressContext
            ? $sideEffect->reason
            : $sideEffect->origin();

        // HTTP middleware usually binds context; re-bootstrap from article site so create never depends on stale request state.
        if ($siteId > 0) {
            try {
                app(SeoDatabaseConnectionService::class)->bootstrapSeoDatabaseConnection($siteId);
            } catch (Throwable $e) {
                Log::warning('[WP_SYNC_TRACE] review_create_bootstrap_failed', [
                    'article_id' => $articleId,
                    'site_id' => $siteId,
                    'wp_post_id' => $wpPostId,
                    'sync_source' => $syncSource,
                    'phase' => 'review_create_start',
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $connectionId = (int) (SeoConnectionContext::current()?->id ?? 0);
        Log::info('[WP_SYNC_TRACE]', [
            'article_id' => $articleId,
            'site_id' => $siteId,
            'wp_post_id' => $wpPostId,
            'connection_id' => $connectionId > 0 ? $connectionId : null,
            'sync_source' => $syncSource,
            'phase' => 'review_create_start',
            'domain' => (string) ($article->site?->domain ?? $article->site?->name ?? ''),
        ]);

        if ($connectionId <= 0) {
            $failed = [
                'article_id' => $articleId,
                'wp_post_id' => $wpPostId,
                'created_count' => 0,
                'pending_review_ids' => [],
                'status' => 'failed',
                'reason' => 'missing_seo_connection_context',
                'message' => 'Thiếu SEO connection context.',
            ];
            Log::error('[WP_SYNC_TRACE]', [
                'article_id' => $articleId,
                'site_id' => $siteId,
                'wp_post_id' => $wpPostId,
                'sync_source' => $syncSource,
                'phase' => 'review_create_failed',
                'exception' => 'RuntimeException',
                'message' => 'Thiếu SEO connection context.',
            ]);

            return [
                'product_review_create' => $failed,
                'product_review_sync' => [
                    'article_id' => $articleId,
                    'status' => 'skipped',
                    'reason' => 'missing_seo_connection_context',
                ],
            ];
        }

        $reviewSettings = $this->reviewSettingsResolver->resolve();

        try {
            $create = $this->businessSequence->runCreate($article, $reviewSettings);
        } catch (Throwable $e) {
            Log::error('[WP_SYNC_TRACE]', [
                'article_id' => $articleId,
                'site_id' => $siteId,
                'wp_post_id' => $wpPostId,
                'sync_source' => $syncSource,
                'phase' => 'review_create_failed',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $createStatus = (string) ($create['status'] ?? '');
        $createdCount = (int) ($create['created_count'] ?? 0);
        if ($createStatus === 'failed' || (($create['success'] ?? true) === false && $createdCount === 0 && $createStatus !== 'skipped')) {
            Log::warning('[WP_SYNC_TRACE]', [
                'article_id' => $articleId,
                'site_id' => $siteId,
                'wp_post_id' => $wpPostId,
                'sync_source' => $syncSource,
                'phase' => 'review_create_failed',
                'exception' => 'ProductReviewCreateFailed',
                'message' => (string) ($create['message'] ?? $create['reason'] ?? 'review create failed'),
                'create' => $create,
            ]);
        } else {
            Log::info('[WP_SYNC_TRACE]', [
                'article_id' => $articleId,
                'site_id' => $siteId,
                'wp_post_id' => $wpPostId,
                'sync_source' => $syncSource,
                'phase' => 'review_create_done',
                'created_count' => $createdCount,
                'status' => $createStatus !== '' ? $createStatus : null,
                'reason' => $create['reason'] ?? null,
            ]);
        }

        Log::info('[WP_SYNC_TRACE]', [
            'article_id' => $articleId,
            'site_id' => $siteId,
            'wp_post_id' => $wpPostId,
            'sync_source' => $syncSource,
            'phase' => 'review_sync_start',
        ]);

        try {
            $sync = SeoQueueContext::runWpSyncFromQueue(
                fn (): array => $this->businessSequence->runSync($article->fresh() ?? $article, $sideEffect, $reviewSettings),
            );
        } catch (Throwable $e) {
            Log::error('[WP_SYNC_TRACE]', [
                'article_id' => $articleId,
                'site_id' => $siteId,
                'wp_post_id' => $wpPostId,
                'sync_source' => $syncSource,
                'phase' => 'review_sync_failed',
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $failedSync = is_array($sync['failed'] ?? null) ? $sync['failed'] : [];
        if ($failedSync !== []) {
            Log::warning('[WP_SYNC_TRACE]', [
                'article_id' => $articleId,
                'site_id' => $siteId,
                'wp_post_id' => $wpPostId,
                'sync_source' => $syncSource,
                'phase' => 'review_sync_partial',
                'failed_count' => count($failedSync),
                'failed' => $failedSync,
            ]);
        } else {
            Log::info('[WP_SYNC_TRACE]', [
                'article_id' => $articleId,
                'site_id' => $siteId,
                'wp_post_id' => $wpPostId,
                'sync_source' => $syncSource,
                'phase' => 'review_sync_done',
                'created' => $sync['created'] ?? [],
                'status' => $sync['status'] ?? null,
            ]);
        }

        return [
            'product_review_create' => $create,
            'product_review_sync' => $sync,
        ];
    }

    /**
     * Standalone (không thuộc Content Project): bài chưa liên kết WP phải tạo post mới.
     * `Đăng ngay` chỉ đổi status local — không được map sang editor-sync (đòi wp_post_id).
     */
    private function standalonePipelineMode(SeoArticle $article): string
    {
        return (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0 ? 'sync' : 'publish';
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function blocked(string $code, string $message, array $extra = []): array
    {
        return array_merge([
            'success' => false,
            'status' => 'blocked',
            'queued' => false,
            'message' => $message,
            'data' => null,
            'error_code' => $code,
            'manual' => true,
            'notification' => [
                'title' => __('seo-content-ai::filament.automation.manual_sync_blocked_title'),
                'body' => $message,
                'status' => 'warning',
            ],
        ], $extra);
    }
}
