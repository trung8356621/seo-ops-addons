<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Modules\WordPress;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\CreateProductReviewsHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\PublishWordPressCommentReviewHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\QueuePendingProductReviewsHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\ScheduleGeneratedProductReviewsHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncArticleToWordPressHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Actions\SyncProductReviewsToWordPressHookAction;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\BusinessEventDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationQueueName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\Contracts\AutomationModuleProvider;
use Omnichannel\Addons\Content\Models\SeoArticle;

final class WordPressAutomationModuleProvider implements AutomationModuleProvider
{
    public function id(): string
    {
        return 'wordpress';
    }

    public function register(AutomationModuleContext $context): void
    {
        foreach ([
            [BusinessEventName::WordpressSyncRequested, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressSyncStarted, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressSynced, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressSyncFailed, ['article_id' => true, 'site_id' => false]],
            [BusinessEventName::WordpressPostDeleted, ['article_id' => true, 'site_id' => false, 'wp_post_id' => false]],
            [BusinessEventName::WordpressCommentReviewPublished, ['review_id' => true, 'article_id' => true, 'wp_post_id' => false, 'wp_comment_id' => false]],
            [BusinessEventName::WordpressCommentReviewPublishFailed, ['review_id' => true, 'article_id' => true, 'error' => false]],
        ] as [$enum, $fields]) {
            $context->events->register($this->eventDef($enum, $fields));
        }

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::WordpressArticleSync->value,
            handlerClass: SyncArticleToWordPressHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [
                'mode' => ['type' => 'string', 'required' => false],
            ],
            description: 'Sync article/product content and media to WordPress. Does not create or publish product reviews.',
            isAsyncSafe: true,
            timeout: 120,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 30,
            supportsTest: false,
            fieldMeta: [
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'mode' => ['label' => 'Sync mode', 'type' => 'select', 'source' => 'settings', 'options' => ['sync', 'publish']],
            ],
            supportsManualTrigger: false,
            manualPermission: 'wordpress.sync',
            manualLabel: 'Đồng bộ WordPress',
            manualDescription: 'Automatic only. Manual UI uses WordPressManualSyncService + ManualSyncContext.',
            manualConfirmation: null,
            manualIdempotencyScope: 'subject',
            manualEnabled: false,
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::WordpressCommentReviewPublish->value,
            handlerClass: PublishWordPressCommentReviewHookAction::class,
            inputRules: [
                'site_id' => ['type' => 'integer', 'required' => true],
                'connection_id' => ['type' => 'integer', 'required' => true],
                'article_id' => ['type' => 'integer', 'required' => true],
                'review_id' => ['type' => 'integer', 'required' => true],
                'wp_post_id' => ['type' => 'integer', 'required' => false],
                'publish_intent' => ['type' => 'string', 'required' => true],
            ],
            settingsRules: [],
            description: 'DEPRECATED stub — use product-review.sync-wp. Safe no-op.',
            isAsyncSafe: true,
            timeout: 90,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 60,
            supportsTest: false,
            fieldMeta: [
                'review_id' => ['label' => 'Review ID', 'type' => 'integer', 'source' => 'input'],
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'publish_intent' => ['label' => 'Publish intent', 'type' => 'select', 'source' => 'input', 'options' => [
                    'generated_review', 'manual_publish', 'retry_failed', 'publish_after_article',
                ]],
            ],
            supportsManualTrigger: false,
            manualPermission: 'wordpress.sync',
            manualLabel: 'Publish product review',
            manualDescription: 'Deprecated.',
            manualConfirmation: null,
            manualIdempotencyScope: 'subject',
            manualEnabled: false,
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ProductReviewCreate->value,
            handlerClass: CreateProductReviewsHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [
                'enabled' => ['type' => 'boolean', 'required' => false],
                'target_count' => ['type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 50],
                'block_if_real_reviews_exist' => ['type' => 'boolean', 'required' => false],
            ],
            description: 'Evaluate ProductReviewCreationPolicy and create local pending product reviews (no WordPress write).',
            isAsyncSafe: true,
            timeout: 60,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 60,
            supportsTest: false,
            fieldMeta: [
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'enabled' => ['label' => 'Enabled', 'type' => 'boolean', 'source' => 'settings'],
                'target_count' => ['label' => 'Target review count', 'type' => 'integer', 'source' => 'settings'],
                'block_if_real_reviews_exist' => ['label' => 'Block if real WP reviews exist', 'type' => 'boolean', 'source' => 'settings'],
            ],
            supportsManualTrigger: false,
            manualEnabled: false,
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ProductReviewSyncWp->value,
            handlerClass: SyncProductReviewsToWordPressHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [
                'enabled' => ['type' => 'boolean', 'required' => false],
                'retry_failed' => ['type' => 'boolean', 'required' => false],
            ],
            description: 'Sync pending/failed local product reviews to WordPress product post (idempotent).',
            isAsyncSafe: true,
            timeout: 120,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 30,
            supportsTest: false,
            fieldMeta: [
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'enabled' => ['label' => 'Enabled', 'type' => 'boolean', 'source' => 'settings'],
                'retry_failed' => ['label' => 'Retry failed', 'type' => 'boolean', 'source' => 'settings'],
            ],
            supportsManualTrigger: false,
            manualPermission: 'wordpress.sync',
            manualLabel: 'Sync product reviews',
            manualDescription: 'Automatic linear action after product-review.create.',
            manualConfirmation: null,
            manualIdempotencyScope: 'subject',
            manualEnabled: false,
        ));

        $maxDelayMeta = [
            'max_delay_time' => [
                'label' => 'Thời gian trì hoãn tối đa',
                'type' => 'integer',
                'source' => 'settings',
                'description' => 'DEPRECATED.',
            ],
        ];

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleProductReviewsQueuePending->value,
            handlerClass: QueuePendingProductReviewsHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
            ],
            settingsRules: [
                'max_delay_time' => ['type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 1440],
            ],
            description: 'DEPRECATED no-op — use product-review.sync-wp.',
            isAsyncSafe: true,
            timeout: 60,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 60,
            supportsTest: false,
            fieldMeta: array_merge([
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
            ], $maxDelayMeta),
            supportsManualTrigger: false,
            manualEnabled: false,
        ));

        $context->actions->register(new AutomationActionDefinition(
            actionCode: AutomationActionCode::ArticleProductReviewsScheduleGenerated->value,
            handlerClass: ScheduleGeneratedProductReviewsHookAction::class,
            inputRules: [
                'article_id' => ['type' => 'integer', 'required' => true],
                'review_ids' => ['type' => 'array', 'required' => false],
            ],
            settingsRules: [
                'max_delay_time' => ['type' => 'integer', 'required' => false, 'minimum' => 0, 'maximum' => 1440],
            ],
            description: 'Schedule generated product reviews for WordPress publish (random delay per review).',
            isAsyncSafe: true,
            timeout: 60,
            module: 'wordpress',
            defaultQueue: AutomationQueueName::External->value,
            rateLimitKey: 'wordpress',
            maxAttemptsPerMinute: 60,
            supportsTest: false,
            fieldMeta: array_merge([
                'article_id' => ['label' => 'Article ID', 'type' => 'integer', 'source' => 'input'],
                'review_ids' => ['label' => 'Review IDs', 'type' => 'array', 'source' => 'input'],
            ], $maxDelayMeta),
            supportsManualTrigger: false,
            manualEnabled: false,
        ));
    }

    /**
     * @param  array<string, bool>  $fields
     */
    private function eventDef(BusinessEventName $enum, array $fields): BusinessEventDefinition
    {
        $schema = [];
        foreach ($fields as $field => $required) {
            $schema[$field] = ['type' => 'mixed', 'required' => $required];
        }

        return new BusinessEventDefinition(
            name: $enum->value,
            subject: SeoArticle::class,
            payloadSchema: $schema,
            description: $enum->value,
            module: 'wordpress',
        );
    }
}
