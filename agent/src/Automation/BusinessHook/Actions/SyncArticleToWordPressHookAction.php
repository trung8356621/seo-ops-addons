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
use Omnichannel\Addons\WordPress\Services\SideEffect\AutomationWordPressContext;
use Omnichannel\Addons\WordPress\Services\SyncArticleToWordPressPipeline;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use App\Support\RuntimeLogger;
use Illuminate\Support\Str;

/**
 * wordpress.article.sync — article/product + media only. No product review orchestration.
 */
final class SyncArticleToWordPressHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly SyncArticleToWordPressPipeline $pipeline,
        private readonly BusinessHookEmitter $emitter,
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

        // Ensure CP queue leaves waiting/queued_for_delivery after confirmed WP success.
        app(\Omnichannel\Addons\WordPress\Services\WordPressArticleSyncService::class)
            ->confirmContentProjectPublishDelivery(
                $article,
                $taskId > 0 ? $taskId : null,
                $attemptToken !== '' ? $attemptToken : null,
                $reconciledTokenMismatch,
            );

        $this->emitter->emitOutcomeSafely(BusinessEventName::WordpressSynced, $article, [
            'article_id' => $articleId,
            'site_id' => (int) ($article->site_id ?? 0) ?: null,
            'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
            'status' => 'synced',
            'origin' => (string) ($context->execution->trigger_type ?? 'event'),
            'automation_execution_id' => (int) $context->execution->id,
            'sync_operation_id' => $idempotencyKey,
            'task_id' => $taskId > 0 ? $taskId : null,
        ], [], $idempotencyKey);

        return AutomationActionResult::success(
            output: [
                'article_id' => $articleId,
                'post_type' => ArticlePostTypeResolver::resolve($article),
                'wp_post_id' => $wpPostId > 0 ? $wpPostId : null,
                'wordpress_connection_id' => (int) ($article->site_id ?? 0) ?: null,
                'sync_status' => 'completed',
                'message' => (string) ($result['message'] ?? 'synced'),
                'mode' => $mode,
                'idempotency_key' => $idempotencyKey,
                'wp_success' => true,
                'task_id' => $taskId > 0 ? $taskId : null,
                'reconciled_token_mismatch' => $reconciledTokenMismatch,
            ],
            message: 'WordPress article sync completed.',
        );
    }
}
