<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use Illuminate\Support\Str;

/**
 * Sinh Featured Snippet bằng prompt cấu hình tại SEO → Tùy chỉnh → Quy trình.
 * Biến {{input}} = từ khóa chính của bài.
 */
final class ArticleFeaturedSnippetGeneratorService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly PromptRunnerService $promptRunner,
        private readonly ArticleMarkdownToHtmlService $markdownHtml,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly PromptResultLinkService $promptResultLinks,
    ) {}

    public function generate(SeoArticle $article): string
    {
        $prompt = $this->resolvePrompt();
        $article->loadMissing(['site']);

        $focusKeyword = trim($this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '');
        if ($focusKeyword === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.featured_snippet_generate_no_keyword'),
            );
        }

        $postType = ArticlePostTypeResolver::resolve($article);
        $promptVars = $this->promptSettings->promptVariables($postType);
        $bodyPlain = trim(strip_tags((string) ($article->body ?? '')));

        $variables = array_merge(
            [
                'input' => $focusKeyword,
                'focus_keyword' => $focusKeyword,
                'post_title' => trim((string) ($article->title ?? '')),
                'post_content' => Str::limit($bodyPlain, 8000),
                'site_domain' => trim((string) ($article->site?->domain ?? '')),
            ],
            $promptVars,
            $this->sitePromptContext->promptVariablesForSite($article->site),
        );
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $article->site,
            $promptVars['tone'] ?? '',
        );

        try {
            $result = $this->promptRunner->run($prompt, $variables);
        } catch (PromptRunException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $this->linkPromptResultToArticle($article, $prompt, $result);

        $output = trim((string) ($result->output_text ?? ''));
        if ($output === '') {
            throw new \InvalidArgumentException(
                'AI không trả về nội dung Featured Snippet. Kết quả prompt đã lưu — xem tại trang Prompts của bài.',
            );
        }

        $html = trim($this->markdownHtml->toHtml($output));
        if ($html === '') {
            throw new \InvalidArgumentException(
                'Không chuyển được kết quả Featured Snippet sang HTML cho editor.',
            );
        }

        return $html;
    }

    private function resolvePrompt(): SeoPrompt
    {
        $promptId = $this->workflowSettings->getFeaturedSnippetPromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.featured_snippet_generate_no_prompt'),
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException(
                'Prompt Featured Snippet không tồn tại hoặc đã tắt.',
            );
        }

        return $prompt;
    }

    private function linkPromptResultToArticle(SeoArticle $article, SeoPrompt $prompt, PromptResult $result): void
    {
        $resultId = (int) $result->getKey();
        if ($resultId <= 0) {
            return;
        }

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $resultId,
            articleId: (int) $article->id,
            source: 'article_featured_snippet_generate',
            workflowStepTitle: 'Generate Featured Snippet (AI)',
            meta: [
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'status' => (string) ($result->status ?? ''),
            ],
        );
    }
}
