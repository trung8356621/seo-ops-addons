<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;
use Omnichannel\Addons\WordPress\Services\SideEffect\AutomationWordPressContext;
use Omnichannel\Addons\ContentProjects\Support\SeoQueueContext;
use Illuminate\Support\Str;

/**
 * product-review.sync-wp — sync pending local reviews to WordPress product post.
 */
final class SyncProductReviewsToWordPressHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly ArticleWordPressBusinessSequence $sequence,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        if ($context->execution->id <= 0) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::ExecutionClaimFailed->value,
                'product-review.sync-wp requires automation_execution_id.',
            );
        }

        $articleId = (int) ($input['article_id'] ?? $context->subject?->getKey() ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure(
                BusinessHookErrorCode::SubjectNotFound->value,
                "Article [{$articleId}] not found.",
            );
        }

        $eventUuid = (string) ($context->businessEvent->event_uuid
            ?? $context->execution->context['event_uuid']
            ?? '');

        $sideEffect = new AutomationWordPressContext(
            automationExecutionId: (int) $context->execution->id,
            automationNodeExecutionId: $context->nodeExecutionId,
            businessEventUuid: $eventUuid !== '' ? $eventUuid : (string) Str::uuid(),
            idempotencyKey: hash(
                'sha256',
                ($context->execution->context['idempotency_key'] ?? $context->execution->idempotency_key)
                .'|product-review.sync-wp|'
                .$articleId,
            ),
            articleId: $articleId,
            siteId: (int) ($context->siteId ?? $article->site_id ?? 0),
            correlationId: (string) ($context->correlationId ?? $context->execution->execution_uuid ?? Str::uuid()),
        );

        $result = SeoQueueContext::runWpSyncFromQueue(
            fn (): array => $this->sequence->runSync($article, $sideEffect, $settings),
        );

        if (($result['status'] ?? '') === 'partial' && ($settings['stop_on_partial'] ?? false) === true) {
            return AutomationActionResult::failure(
                'PRODUCT_REVIEW_SYNC_PARTIAL',
                'Some product reviews failed to sync.',
                $result,
            );
        }

        return AutomationActionResult::success(
            $result,
            (string) ($result['status'] ?? 'completed') === 'skipped'
                ? 'Skipped: '.(string) ($result['reason'] ?? 'policy')
                : 'Product reviews synced to WordPress.',
        );
    }
}
