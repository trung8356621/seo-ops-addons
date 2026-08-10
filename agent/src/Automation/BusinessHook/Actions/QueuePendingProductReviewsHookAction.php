<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Product reviews sync inside SyncArticleToWordPressPipeline after article sync.
 * Legacy queue executions skip safely — no WordPress side effect.
 */
final class QueuePendingProductReviewsHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        Log::info('product_review.legacy_queue_pending.skipped', [
            'article_id' => (int) ($input['article_id'] ?? 0) ?: null,
            'execution_id' => (int) ($context->execution->id ?? 0) ?: null,
            'reason' => 'owned_by_sync_pipeline',
        ]);

        return AutomationActionResult::success([
            'skipped' => true,
            'reason' => 'deprecated_owned_by_wordpress_article_sync_pipeline',
        ], 'Skipped — product reviews owned by wordpress.article.sync pipeline.');
    }
}
