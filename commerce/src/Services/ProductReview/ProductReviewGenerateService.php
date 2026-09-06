<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Commerce\Services\ProductReview;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultCommentPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\WordPress\Services\WordPressCommentReviewService;

/**
 * Canonical Quick Product Review path:
 * Policy → article.comment.generate Prompt → existing store/sync.
 */
final class ProductReviewGenerateService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly PromptRunnerService $promptRunner,
        private readonly WordPressCommentReviewService $commentReviewPublisher,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly PromptResultLinkService $promptResultLinks,
        private readonly ProductReviewCreationPolicy $policy,
        private readonly WordPressProductReviewStatusService $statusService,
        private readonly ProductReviewAutomationSettingsResolver $settingsResolver,
    ) {}

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     created_count?: int,
     *     review_ids?: list<int>,
     *     automation_enabled?: bool,
     *     has_wp_post_id?: bool
     * }
     */
    public function generateForArticle(SeoArticle $article): array
    {
        if (ArticlePostTypeResolver::resolve($article) !== 'product') {
            return [
                'success' => false,
                'message' => ProductReviewCreationPolicy::reasonLabel('not_product')
                    ?? 'Chỉ tạo review cho bài sản phẩm (product).',
            ];
        }

        $promptId = $this->settings->getBoundPromptId(DefaultCommentPromptInstaller::HOOK_KEY);
        if ($promptId === null) {
            return [
                'success' => false,
                'message' => 'Chưa cấu hình Prompt «Tạo bình luận bài viết» (article.comment.generate). Vào SEO → Settings → Workflows.',
            ];
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if (! $prompt instanceof SeoPrompt) {
            return [
                'success' => false,
                'message' => 'Không tìm thấy Prompt article.comment.generate (#'.$promptId.').',
            ];
        }

        $settings = $this->settingsResolver->resolve();
        $wpStatus = $this->statusService->statusForArticle($article, $settings, fresh: true);
        $local = $this->policy->localCounts($article);
        $connected = (bool) ($wpStatus['wordpress_connected'] ?? false);
        $decision = $this->policy->evaluate(
            $article,
            [
                'wordpress_connected' => $connected,
                'fetch_success' => $connected,
                'wordpress_real_review_count' => (int) ($wpStatus['wordpress_real_review_count'] ?? 0),
                'wordpress_generated_review_count' => (int) ($wpStatus['wordpress_generated_review_count'] ?? 0),
            ],
            $local,
            $settings,
        );

        if (! $decision->allowed) {
            return [
                'success' => false,
                'message' => ProductReviewCreationPolicy::reasonLabel($decision->reason)
                    ?? 'Không được tạo review theo policy hiện tại.',
            ];
        }

        $title = trim((string) ($article->title ?? ''));
        if ($title === '') {
            $title = 'Article #'.(int) $article->id;
        }

        $focusKeyword = $this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? $title;
        $commentCount = max(1, (int) $decision->missingCount);

        try {
            $result = $this->promptRunner->run($prompt, [
                'post_title' => $title,
                'comment_count' => (string) $commentCount,
                'focus_keyword' => $focusKeyword,
                'input' => $focusKeyword,
            ]);
        } catch (PromptRunException $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }

        $aiOutput = trim((string) ($result->output_text ?? ''));
        if ($aiOutput === '') {
            return [
                'success' => false,
                'message' => 'Prompt article.comment.generate không trả về nội dung review.',
            ];
        }

        $resultId = (int) $result->getKey();
        if ($resultId > 0) {
            $this->promptResultLinks->linkPromptResult(
                promptResultId: $resultId,
                articleId: (int) $article->id,
                source: 'quick_review_comment_prompt',
                workflowStepTitle: 'Generate product reviews (article.comment.generate)',
                meta: [
                    'prompt_id' => (int) $prompt->id,
                    'hook_key' => DefaultCommentPromptInstaller::HOOK_KEY,
                    'comment_count' => $commentCount,
                ],
            );
        }

        return $this->commentReviewPublisher->storeLocalFromAiOutput($article->fresh() ?? $article, $aiOutput);
    }
}
