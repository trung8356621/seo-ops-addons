<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\BusinessHookEmitter;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectPublishingQueueService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use Omnichannel\Addons\WordPress\Services\SideEffect\AutomationWordPressContext;
use Omnichannel\Addons\WordPress\Services\SyncArticleToWordPressPipeline;
use Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use App\Support\RuntimeLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * wordpress.article.sync — article/product + media, then product-review create/sync via shared business sequence.
 * Publishing Queue delivery (article.publish_requested) reuses the same core as Article Editor manual sync reviews.
 */
final class SyncArticleToWordPressHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly SyncArticleToWordPressPipeline $pipeline,
        private readonly BusinessHookEmitter $emitter,
        private readonly ArticleWordPressBusinessSequence $businessSequence,
        private readonly ?ContentProjectPublishingQueueService $publishingQueue = null,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        if ($context->execution->id <= 0) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::ExecutionClaimFailed->value,
                'wordpress.article.sync requires automation_execution_id.',
            );
        }

        $articleId = (int) ($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        if ($context->subject instanceof SeoArticle && (int) $context->subject->getKey() === $articleId) {
            $article = $context->subject;
        } else {
            $article = SeoArticle::query()->find($articleId);
        }

        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Article [{$articleId}] not found.",
            );
        }

        $taskId = (int) ($input['task_id'] ?? $context->execution->context['task_id'] ?? 0);
        if ($taskId <= 0) {
            $taskId = (int) ($context->businessEvent?->payload['task_id'] ?? 0);
        }
        $attemptToken = trim((string) (
            $input['publish_attempt_token']
            ?? $context->execution->context['publish_attempt_token']
            ?? $context->businessEvent?->payload['publish_attempt_token']
            ?? $input['attempt_ref']
            ?? ''
        ));
        $reconciledTokenMismatch = false;
        if ($taskId > 0) {
            $queue = $this->publishingQueue ?? app(ContentProjectPublishingQueueService::class);
            $task = SeoProjectTask::query()->find($taskId);
            if ($task instanceof SeoProjectTask) {
                $start = $queue->beginPublisherAttempt($task, $attemptToken !== '' ? $attemptToken : null);
                if ($start === 'superseded') {
                    // Do not silently discard: if WP already has this article's post, reconcile later after sync.
                    RuntimeLogger::info('publishing.wp_sync_token_mismatch_continue', [
                        'task_id' => $taskId,
                        'article_id' => $articleId,
                        'execution_id' => (int) $context->execution->id,
                    ]);
                    $reconciledTokenMismatch = true;
                }
            }
        }

        $idempotencyKey = hash(
            'sha256',
            ($context->execution->context['idempotency_key'] ?? $context->execution->idempotency_key)
            .'|wordpress.article.sync|'
            .$articleId
            .($attemptToken !== '' ? '|'.$attemptToken : ''),
        );

        $eventUuid = (string) ($context->businessEvent->event_uuid
            ?? $context->execution->context['event_uuid']
            ?? '');

        $sideEffect = new AutomationWordPressContext(
            automationExecutionId: (int) $context->execution->id,
            automationNodeExecutionId: $context->nodeExecutionId,
            businessEventUuid: $eventUuid !== '' ? $eventUuid : (string) Str::uuid(),
            idempotencyKey: $idempotencyKey,
            articleId: $articleId,
            siteId: (int) ($context->siteId ?? $article->site_id ?? 0),
            correlationId: (string) ($context->correlationId ?? $context->execution->execution_uuid ?? Str::uuid()),
        );

        $mode = (string) (
            $input['publish_mode']
            ?? $input['mode']
            ?? $context->execution->context['publish_mode']
            ?? $context->businessEvent?->payload['publish_mode']
            ?? $settings['mode']
            ?? 'sync'
        );
        /** @var array{seo_title?: string, meta_description?: string, focus_keyword?: string}|null $seoOverride */
        $seoOverride = is_array($settings['seo_override'] ?? null) ? $settings['seo_override'] : null;
        $slug = (string) ($settings['slug'] ?? $article->slug ?? '');

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncStarted, $article, [
            'article_id' => $articleId,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'status' => 'started',
        ]);

        try {
            $result = SeoQueueContext::runWpSyncFromQueue(function () use (
                $mode,
                $article,
                $sideEffect,
                $seoOverride,
                $slug,
            ): array {
                return $this->pipeline->run($article, $sideEffect, $mode, $seoOverride, $slug);
            });
        } catch (\Throwable $wordpressException) {
            $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncFailed, $article, [
                'article_id' => $articleId,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'error' => $wordpressException->getMessage(),
                'status' => 'failed',
            ]);

            return AutomationActionResult::failure(
                'WORDPRESS_SYNC_EXCEPTION',
                $wordpressException->getMessage(),
                [
                    'article_id' => $articleId,
                    'idempotency_key' => $idempotencyKey,
                    'mode' => $mode,
                    'wp_success' => false,
                    'failed_stage' => 'wordpress.operation',
                ],
            );
        }

        // Publishing Queue must never finalize on fingerprint/content skip — force real update.
        if ($taskId > 0 && ($result['skipped'] ?? false) === true) {
            Log::info('[PUBLISH_TRACE]', [
                'article_id' => $articleId,
                'project_item_id' => $taskId,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'wp_post_id' => (int) ($article->wordpressLink?->wp_post_id ?? 0) ?: null,
                'phase' => 'wp_sync_skipped_forced_update',
                'mode' => $mode,
            ]);
            try {
                $result = SeoQueueContext::runWpSyncFromQueue(function () use ($article, $sideEffect, $seoOverride): array {
                    return app(WordPressArticleSyncService::class)
                        ->updatePublishedArticleOnly($article, $sideEffect, $seoOverride);
                });
            } catch (\Throwable $wordpressException) {
                $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncFailed, $article, [
                    'article_id' => $articleId,
                    'site_id' => (int) ($article->site_id ?? 0) ?: null,
                    'error' => $wordpressException->getMessage(),
                    'status' => 'failed',
                ]);

                return AutomationActionResult::failure(
                    'WORDPRESS_SYNC_EXCEPTION',
                    $wordpressException->getMessage(),
                    [
                        'article_id' => $articleId,
                        'idempotency_key' => $idempotencyKey,
                        'mode' => 'update_existing',
                        'wp_success' => false,
                        'failed_stage' => 'wordpress.forced_update',
                    ],
                );
            }
        }

        if (! ($result['success'] ?? false)) {
            $errorCode = (string) ($result['error_code'] ?? 'WORDPRESS_SYNC_FAILED');
            $message = (string) ($result['message'] ?? 'WordPress sync failed.');

            $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncFailed, $article, [
                'article_id' => $articleId,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'error' => $message,
                'status' => 'failed',
                'error_code' => $errorCode,
            ]);

            Log::warning('[PUBLISH_TRACE]', [
                'article_id' => $articleId,
                'project_item_id' => $taskId > 0 ? $taskId : null,
                'site_id' => (int) ($article->site_id ?? 0) ?: null,
                'phase' => 'publish_failed',
                'message' => $message,
                'error_code' => $errorCode,
            ]);

            return AutomationActionResult::failure($errorCode, $message, [
                'article_id' => $articleId,
                'idempotency_key' => $idempotencyKey,
                'mode' => $mode,
                'wp_success' => false,
            ]);
        }

        $article = $article->fresh() ?? $article;
        $article->loadMissing('articleMetas');
        $wpPostId = (int) ($result['wp_post_id'] ?? $article->wordpressLink?->wp_post_id ?? 0);
        $siteId = (int) ($article->site_id ?? 0);
        $postType = ArticlePostTypeResolver::resolve($article);

        Log::info('[PUBLISH_TRACE]', [
            'article_id' => $articleId,
            'project_item_id' => $taskId > 0 ? $taskId : null,
            'site_id' => $siteId > 0 ? $siteId : null,
            'post_type' => $postType,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'phase' => 'wp_sync_done',
            'mode' => $mode,
        ]);

        $productReviewCreate = null;
        $productReviewSync = null;
        if (in_array($mode, ['sync', 'publish', 'update_existing'], true)) {
            Log::info('[PUBLISH_TRACE]', [
                'article_id' => $articleId,
                'project_item_id' => $taskId > 0 ? $taskId : null,
                'site_id' => $siteId > 0 ? $siteId : null,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'phase' => 'post_sync_start',
            ]);

            if ($siteId > 0) {
                try {
                    app(SeoDatabaseConnectionService::class)->bootstrapSeoDatabaseConnection($siteId);
                } catch (\Throwable $e) {
                    Log::warning('[PUBLISH_TRACE]', [
                        'article_id' => $articleId,
                        'site_id' => $siteId,
                        'phase' => 'review_bootstrap_failed',
                        'exception' => $e::class,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $productReviewCreate = $this->businessSequence->runCreate($article);
            $productReviewSync = SeoQueueContext::runWpSyncFromQueue(
                fn (): array => $this->businessSequence->runSync($article->fresh() ?? $article, $sideEffect),
            );

            $createFailed = ($productReviewCreate['status'] ?? '') === 'failed';
            $syncFailed = ($productReviewSync['status'] ?? '') === 'failed'
                || (($productReviewSync['status'] ?? '') === 'partial'
                    && is_array($productReviewSync['failed'] ?? null)
                    && $productReviewSync['failed'] !== []);

            Log::info('[PUBLISH_TRACE]', [
                'article_id' => $articleId,
                'project_item_id' => $taskId > 0 ? $taskId : null,
                'site_id' => $siteId > 0 ? $siteId : null,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'phase' => 'review_sync_done',
                'product_review_create' => $productReviewCreate,
                'product_review_sync' => $productReviewSync,
            ]);

            // Product reviews are part of publish completion — do not mark Published on critical failure.
            if ($postType === 'product' && ($createFailed || $syncFailed)) {
                $message = $createFailed
                    ? (string) ($productReviewCreate['message'] ?? $productReviewCreate['reason'] ?? 'Product review create failed.')
                    : 'Product review sync failed.';

                $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSyncFailed, $article, [
                    'article_id' => $articleId,
                    'site_id' => $siteId > 0 ? $siteId : null,
                    'error' => $message,
                    'status' => 'failed',
                    'error_code' => 'PRODUCT_REVIEW_POST_SYNC_FAILED',
                ]);

                Log::warning('[PUBLISH_TRACE]', [
                    'article_id' => $articleId,
                    'project_item_id' => $taskId > 0 ? $taskId : null,
                    'site_id' => $siteId > 0 ? $siteId : null,
                    'phase' => 'publish_failed',
                    'message' => $message,
                ]);

                return AutomationActionResult::failure(
                    'PRODUCT_REVIEW_POST_SYNC_FAILED',
                    $message,
                    [
                        'article_id' => $articleId,
                        'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                        'product_review_create' => $productReviewCreate,
                        'product_review_sync' => $productReviewSync,
                        'wp_success' => true,
                        'failed_stage' => 'product_review.post_sync',
                    ],
                );
            }
        }

        // Ensure CP queue leaves waiting/queued_for_delivery after confirmed WP (+ review) success.
        app(WordPressArticleSyncService::class)
            ->confirmContentProjectPublishDelivery(
                $article,
                $taskId > 0 ? $taskId : null,
                $attemptToken !== '' ? $attemptToken : null,
                $reconciledTokenMismatch,
            );

        Log::info('[PUBLISH_TRACE]', [
            'article_id' => $articleId,
            'project_item_id' => $taskId > 0 ? $taskId : null,
            'site_id' => $siteId > 0 ? $siteId : null,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'phase' => 'publish_finalize',
        ]);

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSynced, $article, [
            'article_id' => $articleId,
            'site_id' => $siteId > 0 ? $siteId : null,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'status' => 'synced',
            'origin' => (string) ($context->execution->trigger_type ?? 'event'),
            'automation_execution_id' => (int) $context->execution->id,
            'sync_operation_id' => $idempotencyKey,
            'task_id' => $taskId > 0 ? $taskId : null,
            'product_review_create' => $productReviewCreate,
            'product_review_sync' => $productReviewSync,
        ], [], $idempotencyKey);

        return AutomationActionResult::success(
            output: [
                'article_id' => $articleId,
                'post_type' => $postType,
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'wordpress_connection_id' => $siteId > 0 ? $siteId : null,
                'sync_status' => 'completed',
                'message' => (string) ($result['message'] ?? 'synced'),
                'mode' => $mode,
                'idempotency_key' => $idempotencyKey,
                'wp_success' => true,
                'task_id' => $taskId > 0 ? $taskId : null,
                'reconciled_token_mismatch' => $reconciledTokenMismatch,
                'product_review_create' => $productReviewCreate,
                'product_review_sync' => $productReviewSync,
            ],
            message: 'WordPress article sync completed.',
        );
    }
}
