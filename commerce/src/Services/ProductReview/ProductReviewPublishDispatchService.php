<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationEventDispatchOutcome;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\BusinessEventDispatcher;
use Omnichannel\Addons\Commerce\Enums\ArticleProductReviewStatus;
use Omnichannel\Addons\Commerce\Models\ArticleProductReview;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Illuminate\Support\Facades\Log;

/**
 * After schedule delay: emit publish_requested and run matched executions in-process.
 * Avoid depending on a second queued hop (automation-critical) that can silently stall.
 */
final class ProductReviewPublishDispatchService
{
    public function __construct(
        private readonly BusinessEventDispatcher $dispatcher,
        private readonly AutomationExecutionService $executionService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function dispatchAndRun(
        ArticleProductReview $review,
        SeoArticle $article,
        array $payload,
        array $context = [],
    ): void {
        $result = $this->dispatcher->dispatchWithOutcome(
            BusinessEventName::ArticleProductReviewPublishRequested->value,
            $article,
            $payload,
            $context,
        );

        if ($result->outcome === AutomationEventDispatchOutcome::SkippedNoRule
            || $result->outcome === AutomationEventDispatchOutcome::SkippedRuleDisabled
        ) {
            Log::error('product_review.publish_dispatch.no_rule', [
                'review_id' => (int) $review->id,
                'article_id' => (int) $article->id,
                'outcome' => $result->outcome->value,
                'hint' => 'Seed/enable rule execute-wordpress-comment-review-publish (event article.product_review_publish_requested).',
            ]);

            $review->forceFill([
                'status' => ArticleProductReviewStatus::FailedDispatch->value,
                'last_error_code' => 'PUBLISH_RULE_MISSING',
                'last_error_message' => 'No enabled automation rule for article.product_review_publish_requested.',
                'scheduled_at' => null,
            ])->save();

            return;
        }

        $event = $result->event;
        if ($event === null) {
            Log::error('product_review.publish_dispatch.event_missing', [
                'review_id' => (int) $review->id,
                'article_id' => (int) $article->id,
                'outcome' => $result->outcome->value,
            ]);

            return;
        }

        $pending = AutomationExecution::query()
            ->where('business_event_id', $event->id)
            ->where('status', AutomationExecutionStatus::Pending->value)
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            // run_mode=sync already finished inside BusinessEventDispatcher.
            Log::info('product_review.publish_dispatch.completed_via_dispatcher', [
                'review_id' => (int) $review->id,
                'article_id' => (int) $article->id,
                'event_uuid' => $event->event_uuid,
                'matched_rules' => $result->matchedRules,
                'outcome' => $result->outcome->value,
            ]);

            return;
        }

        foreach ($pending as $execution) {
            Log::info('product_review.publish_dispatch.run_sync', [
                'review_id' => (int) $review->id,
                'article_id' => (int) $article->id,
                'automation_execution_id' => (int) $execution->id,
                'event_uuid' => $event->event_uuid,
            ]);

            $this->executionService->run((int) $execution->id);
        }
    }
}
