<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Infrastructure stub. Review create owned by SyncArticleToWordPressPipeline.
 * Keep handler registered so legacy delayed jobs finish without duplicate WP publish.
 */
final class PublishWordPressCommentReviewHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        Log::info('product_review.legacy_publish.skipped', [
            'review_id' => (int) ($input['review_id'] ?? 0) ?: null,
            'article_id' => (int) ($input['article_id'] ?? 0) ?: null,
            'execution_id' => (int) ($context->execution->id ?? 0) ?: null,
            'reason' => 'owned_by_sync_pipeline',
        ]);

        return AutomationActionResult::success([
            'skipped' => true,
            'reason' => 'deprecated_owned_by_wordpress_article_sync_pipeline',
            'review_id' => (int) ($input['review_id'] ?? 0) ?: null,
        ], 'Skipped — product reviews owned by wordpress.article.sync pipeline.');
    }
}
