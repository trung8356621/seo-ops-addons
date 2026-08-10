<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Services\SeoArticleScoringQueueService;

final class ArticleRunSeoAnalysisHookAction implements AutomationActionHandler
{
    public function __construct(
        private readonly SeoArticleScoringQueueService $scoringQueue,
    ) {}

    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $articleId = (int) ($input['article_id'] ?? 0);
        if ($articleId <= 0) {
            return AutomationActionResult::failure('INVALID_ARTICLE_ID', 'article_id is required.');
        }

        $article = SeoArticle::query()->find($articleId);
        if (! $article instanceof SeoArticle) {
            return AutomationActionResult::failure('ARTICLE_NOT_FOUND', 'Article not found.');
        }

        $force = ! array_key_exists('force', $input)
            || filter_var($input['force'], FILTER_VALIDATE_BOOLEAN);

        $dispatched = $this->scoringQueue->dispatchForArticle($article, force: $force);
        if (! $dispatched) {
            return AutomationActionResult::success(
                output: [
                    'article_id' => $articleId,
                    'status' => 'skipped',
                    'reason' => 'already_pending_or_ineligible',
                ],
                message: 'SEO analysis skipped (pending or ineligible).',
            );
        }

        return AutomationActionResult::success(
            output: [
                'article_id' => $articleId,
                'status' => 'queued',
            ],
            message: 'SEO analysis queued.',
        );
    }
}
