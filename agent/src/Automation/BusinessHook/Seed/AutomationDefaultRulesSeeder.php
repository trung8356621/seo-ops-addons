<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Seed;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationActionCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEdgeBranch;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleClassification;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationRuleVisibility;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationTriggerType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationWorkflowMode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationGraphRuleService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationRuleService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationVersionService;

final class AutomationDefaultRulesSeeder
{
    public function __construct(
        private readonly AutomationRuleService $ruleService,
        private readonly AutomationGraphRuleService $graphRuleService,
        private readonly AutomationVersionService $versionService,
    ) {}

    public function seed(): void
    {
        $this->seedIfMissing(
            code: 'sync-article-to-wordpress',
            data: [
                'code' => 'sync-article-to-wordpress',
                'name' => 'article > wordpress',
                'description' => 'When article completed and has site, Linear: wordpress.article.sync → product-review.create → product-review.sync-wp. Business.',
                'classification' => AutomationRuleClassification::Business->value,
                'visibility' => AutomationRuleVisibility::User->value,
                'event_name' => BusinessEventName::ArticleCompleted->value,
                'is_enabled' => true,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => [
                    'all' => [
                        [
                            'field' => 'event.site_id',
                            'operator' => 'exists',
                        ],
                    ],
                ],
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::WordpressArticleSync->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => false,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                    ],
                    'settings' => ['mode' => 'sync'],
                ],
                [
                    'action_code' => AutomationActionCode::ProductReviewCreate->value,
                    'position' => 1,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                    ],
                    'settings' => [
                        'enabled' => true,
                        'target_count' => 10,
                        'block_if_real_reviews_exist' => true,
                    ],
                ],
                [
                    'action_code' => AutomationActionCode::ProductReviewSyncWp->value,
                    'position' => 2,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                    ],
                    'settings' => [
                        'enabled' => true,
                        'retry_failed' => true,
                    ],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'notify-workflow-failure',
            data: [
                'code' => 'notify-workflow-failure',
                'name' => 'content_project.task.failed > notification',
                'description' => 'Notify when content project task fails. Business.',
                'classification' => AutomationRuleClassification::Business->value,
                'event_name' => BusinessEventName::ContentProjectTaskFailed->value,
                'is_enabled' => true,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::NotificationSend->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'input_mapping' => [
                        'message' => 'Task {{ payload.task_id }} failed',
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'dispatch-publish-request',
            data: [
                'code' => 'dispatch-publish-request',
                'name' => 'article.publish.request > wordpress',
                'description' => 'article.publish_requested → wordpress.article.sync (scheduled/due linked posts). Business.',
                'classification' => AutomationRuleClassification::Business->value,
                'event_name' => BusinessEventName::ArticlePublishRequested->value,
                'is_enabled' => true,
                'priority' => 200,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => [
                    'all' => [
                        [
                            'field' => 'event.site_id',
                            'operator' => 'exists',
                        ],
                    ],
                ],
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::WordpressArticleSync->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => false,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                        'task_id' => '{{ payload.task_id }}',
                        'publish_attempt_token' => '{{ payload.publish_attempt_token }}',
                    ],
                    'settings' => ['mode' => 'publish'],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'seo-analysis-on-content-updated',
            data: [
                'code' => 'seo-analysis-on-content-updated',
                'name' => 'article.content > seo.analysis',
                'description' => 'When article content updates, run SEO analysis. Business.',
                'classification' => AutomationRuleClassification::Business->value,
                'event_name' => BusinessEventName::ArticleContentUpdated->value,
                'is_enabled' => true,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::ArticleRunSeoAnalysis->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => false,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                        'force' => true,
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'sync-keyword-domain-link-list-on-saved',
            data: [
                'code' => 'sync-keyword-domain-link-list-on-saved',
                'name' => 'keyword.saved > domain_link_list.sync',
                'description' => 'When keyword saved, sync domain link list. Business.',
                'classification' => AutomationRuleClassification::Business->value,
                'event_name' => BusinessEventName::KeywordSaved->value,
                'is_enabled' => true,
                'priority' => 100,
                'stop_on_failure' => false,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::KeywordDomainLinkListSync->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => false,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'keyword_id' => '{{ payload.keyword_id }}',
                        'site_id' => '{{ payload.site_id }}',
                        'phrase' => '{{ payload.phrase }}',
                        'target_url' => '{{ payload.target_url }}',
                        'previous_phrase' => '{{ payload.previous_phrase }}',
                        'operation' => '{{ payload.operation }}',
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'publish-generated-product-reviews-to-wordpress',
            data: [
                'code' => 'publish-generated-product-reviews-to-wordpress',
                'name' => 'article.product.reviews > schedule',
                'description' => 'Deprecated: product review scheduling now runs inside SyncArticleToWordPressPipeline (see "article > wordpress").',
                'classification' => AutomationRuleClassification::Deprecated->value,
                'visibility' => AutomationRuleVisibility::Hidden->value,
                'event_name' => BusinessEventName::ArticleProductReviewsGenerated->value,
                'is_enabled' => false,
                'priority' => 100,
                'stop_on_failure' => false,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::ArticleProductReviewsScheduleGenerated->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                        'review_ids' => '{{ payload.review_ids }}',
                    ],
                    'settings' => [
                        'max_delay_time' => 5,
                    ],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'publish-pending-product-reviews-after-article-sync',
            data: [
                'code' => 'publish-pending-product-reviews-after-article-sync',
                'name' => 'wordpress.article.synced > article.product.reviews',
                'description' => 'Deprecated: pending product review sync now runs inside SyncArticleToWordPressPipeline (see "article > wordpress").',
                'classification' => AutomationRuleClassification::Deprecated->value,
                'visibility' => AutomationRuleVisibility::Hidden->value,
                'event_name' => BusinessEventName::WordpressSynced->value,
                'is_enabled' => false,
                'priority' => 120,
                'stop_on_failure' => false,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::ArticleProductReviewsQueuePending->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'article_id' => '{{ payload.article_id }}',
                    ],
                    'settings' => [
                        'max_delay_time' => 5,
                    ],
                ],
            ],
        );

        // Deprecated: SyncArticleToWordPressPipeline now owns comment-review publish end to end.
        $this->seedIfMissing(
            code: 'execute-wordpress-comment-review-publish',
            data: [
                'code' => 'execute-wordpress-comment-review-publish',
                'name' => 'article.product.reviews > wordpress.publish',
                'description' => 'Deprecated: WordPress comment-review publish now runs inside SyncArticleToWordPressPipeline (see "article > wordpress").',
                'classification' => AutomationRuleClassification::Deprecated->value,
                'visibility' => AutomationRuleVisibility::Hidden->value,
                'event_name' => BusinessEventName::ArticleProductReviewPublishRequested->value,
                'is_enabled' => false,
                'priority' => 50,
                'stop_on_failure' => false,
                'run_mode' => 'sync',
                'trigger_type' => AutomationTriggerType::Event->value,
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::WordpressCommentReviewPublish->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => [
                        'site_id' => '{{ payload.site_id }}',
                        'connection_id' => '{{ payload.connection_id }}',
                        'article_id' => '{{ payload.article_id }}',
                        'review_id' => '{{ payload.review_id }}',
                        'wp_post_id' => '{{ payload.wp_post_id }}',
                        'publish_intent' => '{{ payload.publish_intent }}',
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->seedIfMissing(
            code: 'notify-on-notification-requested',
            data: [
                'code' => 'notify-on-notification-requested',
                'name' => 'notification.requested > notification',
                'description' => 'Deliver in-app notification when notification.requested emitted. System until producers stabilize.',
                'classification' => AutomationRuleClassification::System->value,
                'visibility' => AutomationRuleVisibility::Admin->value,
                'event_name' => BusinessEventName::NotificationRequested->value,
                'is_enabled' => false,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'conditions' => null,
            ],
            actions: [
                [
                    'action_code' => AutomationActionCode::NotificationSend->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'input_mapping' => [
                        'message' => '{{ payload.message }}',
                        'title' => '{{ payload.title }}',
                        'user_id' => '{{ payload.user_id }}',
                        'project_id' => '{{ payload.project_id }}',
                    ],
                    'settings' => [],
                ],
            ],
        );

        $this->migrateProductReviewAutomationRules();
        $this->promoteArticleOwnershipRules();
        $this->seedArticleCompletePipelineGraph();
        $this->repairEnabledUnpublishedRules();
    }

    /**
     * enabled + unpublished = invalid runtime. Publish business rules; keep
     * system/sample/deprecated disabled until explicitly promoted.
     */
    private function repairEnabledUnpublishedRules(): void
    {
        $rows = AutomationRule::query()
            ->where('is_enabled', true)
            ->whereNull('published_version_id')
            ->get();

        foreach ($rows as $rule) {
            if (! $rule instanceof AutomationRule) {
                continue;
            }

            $code = (string) $rule->code;
            $classification = (string) ($rule->classification ?? AutomationRuleClassification::Business->value);

            if (in_array($classification, [
                AutomationRuleClassification::System->value,
                AutomationRuleClassification::Experimental->value,
                AutomationRuleClassification::Sample->value,
                AutomationRuleClassification::Deprecated->value,
            ], true) || in_array($code, [
                'notify-on-notification-requested',
                'publish-generated-product-reviews-to-wordpress',
                'publish-pending-product-reviews-after-article-sync',
                'execute-wordpress-comment-review-publish',
            ], true)) {
                $rule->forceFill([
                    'is_enabled' => false,
                    'classification' => $code === 'notify-on-notification-requested'
                        ? AutomationRuleClassification::System->value
                        : (in_array($code, [
                            'publish-generated-product-reviews-to-wordpress',
                            'publish-pending-product-reviews-after-article-sync',
                            'execute-wordpress-comment-review-publish',
                        ], true)
                            ? AutomationRuleClassification::Deprecated->value
                            : $classification),
                    'visibility' => in_array($code, [
                        'publish-generated-product-reviews-to-wordpress',
                        'publish-pending-product-reviews-after-article-sync',
                        'execute-wordpress-comment-review-publish',
                    ], true)
                        ? AutomationRuleVisibility::Hidden->value
                        : ($code === 'notify-on-notification-requested'
                            ? AutomationRuleVisibility::Admin->value
                            : ($rule->visibility ?? AutomationRuleVisibility::User->value)),
                ])->save();

                continue;
            }

            $rule->loadMissing('actions');
            if ($rule->actions->isEmpty() && ! $rule->isGraphMode()) {
                $rule->forceFill(['is_enabled' => false])->save();

                continue;
            }

            $this->versionService->publish($rule);
        }

        $notify = AutomationRule::query()
            ->where('code', 'notify-on-notification-requested')
            ->first();
        if ($notify instanceof AutomationRule) {
            $notify->forceFill([
                'is_enabled' => false,
                'classification' => AutomationRuleClassification::System->value,
                'visibility' => AutomationRuleVisibility::Admin->value,
                'name' => 'notification.requested > notification',
            ])->save();
        }

        // Deprecated product-review rules: force disabled + hidden even over stale legacy data.
        foreach ([
            'publish-generated-product-reviews-to-wordpress',
            'publish-pending-product-reviews-after-article-sync',
            'execute-wordpress-comment-review-publish',
        ] as $deprecatedCode) {
            $deprecatedRule = AutomationRule::query()->where('code', $deprecatedCode)->first();
            if ($deprecatedRule instanceof AutomationRule) {
                $deprecatedRule->forceFill([
                    'is_enabled' => false,
                    'classification' => AutomationRuleClassification::Deprecated->value,
                    'visibility' => AutomationRuleVisibility::Hidden->value,
                ])->save();
            }
        }
    }

    /**
     * Production ownership: article.completed → WP sync; publish_requested → WP publish.
     * Graph sample stays disabled.
     */
    private function promoteArticleOwnershipRules(): void
    {
        $sync = AutomationRule::query()->where('code', 'sync-article-to-wordpress')->first();
        if ($sync instanceof AutomationRule) {
            $sync->forceFill([
                'name' => 'article > wordpress',
                'description' => 'When article completed and has site, Linear: wordpress.article.sync → product-review.create → product-review.sync-wp. Business.',
                'classification' => AutomationRuleClassification::Business->value,
                'visibility' => AutomationRuleVisibility::User->value,
                'event_name' => BusinessEventName::ArticleCompleted->value,
                'is_enabled' => true,
                'priority' => 100,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'workflow_mode' => AutomationWorkflowMode::Linear->value,
                'conditions' => [
                    'all' => [
                        [
                            'field' => 'event.site_id',
                            'operator' => 'exists',
                        ],
                    ],
                ],
            ])->save();

            $desiredActions = [
                [
                    'action_code' => AutomationActionCode::WordpressArticleSync->value,
                    'position' => 0,
                    'is_enabled' => true,
                    'continue_on_failure' => false,
                    'delay_seconds' => 0,
                    'input_mapping' => ['article_id' => '{{ payload.article_id }}'],
                    'settings' => ['mode' => 'sync'],
                ],
                [
                    'action_code' => AutomationActionCode::ProductReviewCreate->value,
                    'position' => 1,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => ['article_id' => '{{ payload.article_id }}'],
                    'settings' => [
                        'enabled' => true,
                        'target_count' => 10,
                        'block_if_real_reviews_exist' => true,
                    ],
                ],
                [
                    'action_code' => AutomationActionCode::ProductReviewSyncWp->value,
                    'position' => 2,
                    'is_enabled' => true,
                    'continue_on_failure' => true,
                    'delay_seconds' => 0,
                    'input_mapping' => ['article_id' => '{{ payload.article_id }}'],
                    'settings' => [
                        'enabled' => true,
                        'retry_failed' => true,
                    ],
                ],
            ];

            $sync->loadMissing('actions');
            $currentCodes = $sync->actions->sortBy('position')->pluck('action_code')->map(static fn ($c) => (string) $c)->values()->all();
            $desiredCodes = array_map(static fn (array $a): string => (string) $a['action_code'], $desiredActions);
            $needsReplace = $currentCodes !== $desiredCodes;

            if ($needsReplace) {
                foreach ($sync->actions as $old) {
                    $old->delete();
                }
                foreach ($desiredActions as $row) {
                    $sync->actions()->create($row);
                }
            }

            $sync = $sync->fresh(['actions']) ?? $sync;
            if ($needsReplace || $sync->published_version_id === null) {
                $this->versionService->publish($sync);
            }
        }

        $publishReq = AutomationRule::query()->where('code', 'dispatch-publish-request')->first();
        if ($publishReq instanceof AutomationRule) {
            $publishReq->forceFill([
                'name' => 'article.publish.request > wordpress',
                'description' => 'article.publish_requested → wordpress.article.sync (scheduled/due linked posts). Business.',
                'classification' => AutomationRuleClassification::Business->value,
                'event_name' => BusinessEventName::ArticlePublishRequested->value,
                'is_enabled' => true,
                'priority' => 200,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'workflow_mode' => AutomationWorkflowMode::Linear->value,
                'conditions' => [
                    'all' => [
                        [
                            'field' => 'event.site_id',
                            'operator' => 'exists',
                        ],
                    ],
                ],
            ])->save();

            $publishReq->loadMissing('actions');
            foreach ($publishReq->actions as $old) {
                $old->delete();
            }
            $publishReq->actions()->create([
                'action_code' => AutomationActionCode::WordpressArticleSync->value,
                'position' => 0,
                'is_enabled' => true,
                'continue_on_failure' => false,
                'delay_seconds' => 0,
                'input_mapping' => [
                    'article_id' => '{{ payload.article_id }}',
                    'task_id' => '{{ payload.task_id }}',
                    'publish_attempt_token' => '{{ payload.publish_attempt_token }}',
                ],
                'settings' => ['mode' => 'publish'],
            ]);

            $publishReq = $publishReq->fresh(['actions']) ?? $publishReq;
            $this->versionService->publish($publishReq);
        }

        $graph = AutomationRule::query()->where('code', 'article-complete-pipeline-graph')->first();
        if ($graph instanceof AutomationRule) {
            $graph->forceFill([
                'name' => 'sample: article complete pipeline (graph)',
                'description' => 'SAMPLE only — disabled. Production ownership is sync-article-to-wordpress linear rule.',
                'classification' => AutomationRuleClassification::Sample->value,
                'is_enabled' => false,
                'event_name' => BusinessEventName::ArticleCompleted->value,
            ])->save();
        }

        $this->promoteLinearRule(
            code: 'seo-analysis-on-content-updated',
            name: 'article.content > seo.analysis',
            description: 'When article content updates, run SEO analysis. Business.',
            event: BusinessEventName::ArticleContentUpdated->value,
            actionCode: AutomationActionCode::ArticleRunSeoAnalysis->value,
            inputMapping: [
                'article_id' => '{{ payload.article_id }}',
                'force' => true,
            ],
            settings: [],
            priority: 100,
            stopOnFailure: true,
        );

        $this->promoteLinearRule(
            code: 'sync-keyword-domain-link-list-on-saved',
            name: 'keyword.saved > domain_link_list.sync',
            description: 'When keyword saved, sync domain link list. Business.',
            event: BusinessEventName::KeywordSaved->value,
            actionCode: AutomationActionCode::KeywordDomainLinkListSync->value,
            inputMapping: [
                'keyword_id' => '{{ payload.keyword_id }}',
                'site_id' => '{{ payload.site_id }}',
                'phrase' => '{{ payload.phrase }}',
                'target_url' => '{{ payload.target_url }}',
                'previous_phrase' => '{{ payload.previous_phrase }}',
                'operation' => '{{ payload.operation }}',
            ],
            settings: [],
            priority: 100,
            stopOnFailure: false,
        );

        $this->promoteLinearRule(
            code: 'notify-workflow-failure',
            name: 'content_project.task.failed > notification',
            description: 'Notify when content project task fails. Business.',
            event: BusinessEventName::ContentProjectTaskFailed->value,
            actionCode: AutomationActionCode::NotificationSend->value,
            inputMapping: [
                'message' => 'Task {{ payload.task_id }} failed',
            ],
            settings: [],
            priority: 100,
            stopOnFailure: true,
        );
    }

    /**
     * @param  array<string, mixed>  $inputMapping
     * @param  array<string, mixed>  $settings
     */
    private function promoteLinearRule(
        string $code,
        string $name,
        string $description,
        string $event,
        string $actionCode,
        array $inputMapping,
        array $settings,
        int $priority,
        bool $stopOnFailure,
        bool $continueOnFailure = false,
    ): void {
        $rule = AutomationRule::query()->where('code', $code)->first();
        if (! $rule instanceof AutomationRule) {
            return;
        }

        $rule->forceFill([
            'name' => $name,
            'description' => $description,
            'classification' => AutomationRuleClassification::Business->value,
            'event_name' => $event,
            'is_enabled' => true,
            'priority' => $priority,
            'stop_on_failure' => $stopOnFailure,
            'run_mode' => 'queued',
            'workflow_mode' => AutomationWorkflowMode::Linear->value,
        ])->save();

        $rule->loadMissing('actions');
        foreach ($rule->actions as $old) {
            $old->delete();
        }
        $rule->actions()->create([
            'action_code' => $actionCode,
            'position' => 0,
            'is_enabled' => true,
            'continue_on_failure' => $continueOnFailure,
            'delay_seconds' => 0,
            'input_mapping' => $inputMapping,
            'settings' => $settings,
        ]);

        $rule = $rule->fresh(['actions']) ?? $rule;
        if ($rule->published_version_id === null) {
            $this->versionService->publish($rule);
        } else {
            $this->versionService->publish($rule);
        }
    }

    private function seedArticleCompletePipelineGraph(): void
    {
        $code = 'article-complete-pipeline-graph';
        $existing = AutomationRule::query()->where('code', $code)->first();
        if ($existing instanceof AutomationRule && $existing->nodes()->exists()) {
            $existing->forceFill([
                'name' => 'sample: article complete pipeline (graph)',
                'classification' => AutomationRuleClassification::Sample->value,
                'is_enabled' => false,
            ])->save();

            return;
        }

        $rule = $existing instanceof AutomationRule
            ? $existing
            : $this->ruleService->createRule([
                'code' => $code,
                'name' => 'sample: article complete pipeline (graph)',
                'description' => 'SAMPLE graph: condition → delay → WP sync → branches. Always disabled.',
                'classification' => AutomationRuleClassification::Sample->value,
                'event_name' => BusinessEventName::ArticleCompleted->value,
                'is_enabled' => false,
                'priority' => 150,
                'stop_on_failure' => true,
                'run_mode' => 'queued',
                'workflow_mode' => AutomationWorkflowMode::Graph->value,
                'trigger_type' => AutomationTriggerType::Event->value,
                'conditions' => null,
            ], []);

        if (! $rule->isGraphMode()) {
            $rule->forceFill(['workflow_mode' => AutomationWorkflowMode::Graph->value])->save();
        }

        $this->graphRuleService->syncGraph($rule, [
            ['node_key' => 'trigger', 'node_type' => AutomationNodeType::Trigger->value, 'name' => 'Trigger', 'position' => 0, 'is_enabled' => true],
            ['node_key' => 'check_post_type', 'node_type' => AutomationNodeType::Condition->value, 'name' => 'post_type == post', 'position' => 1, 'is_enabled' => true, 'config' => ['conditions' => ['all' => [['field' => 'subject.post_type', 'operator' => 'equals', 'value' => 'post']]]]],
            ['node_key' => 'delay_10s', 'node_type' => AutomationNodeType::Delay->value, 'name' => 'Delay 10s', 'position' => 2, 'is_enabled' => true, 'config' => ['seconds' => 10]],
            ['node_key' => 'wp_sync', 'node_type' => AutomationNodeType::Action->value, 'name' => 'WordPress sync', 'action_code' => AutomationActionCode::WordpressArticleSync->value, 'position' => 3, 'is_enabled' => true, 'input_mapping' => ['article_id' => '{{ payload.article_id }}'], 'settings' => ['mode' => 'sync'], 'config' => ['retry' => ['max_attempts' => 3, 'backoff_seconds' => [60, 300, 900]]]],
            ['node_key' => 'notify_fail', 'node_type' => AutomationNodeType::Action->value, 'name' => 'Notify failure', 'action_code' => AutomationActionCode::NotificationSend->value, 'position' => 4, 'is_enabled' => true, 'input_mapping' => ['message' => 'WP sync failed for article {{ payload.article_id }}']],
            ['node_key' => 'dispatch_synced', 'node_type' => AutomationNodeType::DispatchEvent->value, 'name' => 'Dispatch synced', 'position' => 5, 'is_enabled' => true, 'settings' => ['event_name' => BusinessEventName::WordpressSynced->value]],
            ['node_key' => 'end_ok', 'node_type' => AutomationNodeType::End->value, 'name' => 'End OK', 'position' => 6, 'is_enabled' => true],
            ['node_key' => 'end_fail', 'node_type' => AutomationNodeType::End->value, 'name' => 'End fail', 'position' => 7, 'is_enabled' => true],
            ['node_key' => 'end_skip', 'node_type' => AutomationNodeType::End->value, 'name' => 'End skip', 'position' => 8, 'is_enabled' => true],
        ], [
            ['from_node_key' => 'trigger', 'to_node_key' => 'check_post_type', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
            ['from_node_key' => 'check_post_type', 'to_node_key' => 'delay_10s', 'branch' => AutomationEdgeBranch::True->value, 'priority' => 100],
            ['from_node_key' => 'check_post_type', 'to_node_key' => 'end_skip', 'branch' => AutomationEdgeBranch::False->value, 'priority' => 100],
            ['from_node_key' => 'delay_10s', 'to_node_key' => 'wp_sync', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
            ['from_node_key' => 'wp_sync', 'to_node_key' => 'dispatch_synced', 'branch' => AutomationEdgeBranch::Success->value, 'priority' => 100],
            ['from_node_key' => 'wp_sync', 'to_node_key' => 'notify_fail', 'branch' => AutomationEdgeBranch::Failure->value, 'priority' => 100],
            ['from_node_key' => 'dispatch_synced', 'to_node_key' => 'end_ok', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
            ['from_node_key' => 'notify_fail', 'to_node_key' => 'end_fail', 'branch' => AutomationEdgeBranch::Always->value, 'priority' => 100],
        ]);

        if ($rule->published_version_id === null) {
            $this->versionService->publish($rule);
        }
    }

    /**
     * Deprecate legacy product-review business rules — ownership is SyncArticleToWordPressPipeline.
     */
    private function migrateProductReviewAutomationRules(): void
    {
        $deprecated = [
            'publish-generated-product-reviews-to-wordpress' => 'Deprecated: scheduling owned by SyncArticleToWordPressPipeline (article > wordpress).',
            'publish-pending-product-reviews-after-article-sync' => 'Deprecated: pending review sync owned by SyncArticleToWordPressPipeline (article > wordpress).',
            'execute-wordpress-comment-review-publish' => 'Deprecated infrastructure stub — WordPress review create owned by SyncArticleToWordPressPipeline.',
        ];

        foreach ($deprecated as $code => $description) {
            $rule = AutomationRule::query()->where('code', $code)->first();
            if (! $rule instanceof AutomationRule) {
                continue;
            }

            $rule->forceFill([
                'description' => $description,
                'classification' => AutomationRuleClassification::Deprecated->value,
                'visibility' => AutomationRuleVisibility::Hidden->value,
                'is_enabled' => false,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $actions
     */
    private function seedIfMissing(string $code, array $data, array $actions): void
    {
        if (AutomationRule::query()->where('code', $code)->exists()) {
            return;
        }

        $rule = $this->ruleService->createRule($data, $actions);

        if ((bool) ($data['is_enabled'] ?? false) && $rule->published_version_id === null) {
            $this->versionService->publish($rule);
        }
    }
}
