<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\ArticleWordPressBusinessSequence;

/**
 * product-review.create — local pending only, shared ProductReviewCreationPolicy.
 */
final class CreateProductReviewsHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly ArticleWordPressBusinessSequence $sequence,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
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

        $result = $this->sequence->runCreate($article, $settings);

        return AutomationActionResult::success(
            $result,
            (string) ($result['status'] ?? 'completed') === 'skipped'
                ? 'Skipped: '.(string) ($result['reason'] ?? 'policy')
                : 'Product reviews created locally.',
        );
    }
}
