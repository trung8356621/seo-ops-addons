<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Content\Filament\Resources\ArticleResource;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;
use App\Models\Site;

/**
 * Dịch nhanh bài viết ngôn ngữ mặc định sang bản dịch liên kết bằng prompt cấu hình tại Workflows.
 * Biến: {{input}} = markdown nội dung nguồn, {{language}} = tên ngôn ngữ đích (tiếng Anh).
 */
final class ArticleQuickTranslateService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly PromptRunnerService $promptRunner,
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleMarkdownToHtmlService $markdownHtml,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly SitePolylangService $polylang,
        private readonly PromptResultLinkService $promptResultLinks,
    ) {}

    /**
     * @return array{
     *     target_article_id: int,
     *     edit_url: string,
     *     target_lang: string,
     *     target_language: string,
     * }
     */
    public function translateLinkedArticle(
        SeoArticle $sourceArticle,
        SeoArticle $targetArticle,
        string $sourceHtml = '',
    ): array {
        $sourceArticle->loadMissing(['site']);
        $targetArticle->loadMissing(['site']);

        $this->assertDefaultLanguageSource($sourceArticle);
        $this->assertDistinctLinkedArticles($sourceArticle, $targetArticle);

        $prompt = $this->resolvePrompt();
        $markdown = $this->resolveSourceMarkdown($sourceArticle, $sourceHtml);
        if ($markdown === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_no_content'),
            );
        }

        $targetLang = trim((string) ($targetArticle->language ?? ''));
        if ($targetLang === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_invalid_target'),
            );
        }

        $targetLanguage = $this->polylang->languageEnglishName($targetLang);
        $postType = ArticlePostTypeResolver::resolve($sourceArticle);
        $promptVars = $this->promptSettings->promptVariables($postType);
        $site = $sourceArticle->site instanceof Site ? $sourceArticle->site : null;

        $variables = array_merge(
            [
                'input' => $markdown,
                'language' => $targetLanguage,
                'post_title' => trim((string) ($sourceArticle->title ?? '')),
                'post_content' => $markdown,
                'site_domain' => trim((string) ($sourceArticle->site?->domain ?? '')),
            ],
            $promptVars,
            $this->sitePromptContext->promptVariablesForSite($site),
        );
        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $site,
            $promptVars['tone'] ?? '',
        );

        try {
            $result = $this->promptRunner->run($prompt, $variables);
        } catch (PromptRunException $exception) {
            throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
        }

        $this->linkPromptResultToArticles($sourceArticle, $targetArticle, $prompt, $result);

        $output = trim((string) ($result->output_text ?? ''));
        if ($output === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_empty_output'),
            );
        }

        $prepared = $this->markdownHtml->prepareImport($output);
        $html = trim($this->markdownHtml->toHtml($prepared['markdown']));
        if ($html === '') {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_invalid_output'),
            );
        }

        $this->contentFaq->persistArticleBodyHtml($targetArticle, $html);

        return [
            'target_article_id' => (int) $targetArticle->id,
            'edit_url' => ArticleResource::getUrl(
                'edit',
                ['record' => $targetArticle->id],
                panel: ArticleResource::panelId(),
            ),
            'target_lang' => $targetLang,
            'target_language' => $targetLanguage,
        ];
    }

    private function resolvePrompt(): SeoPrompt
    {
        $promptId = $this->workflowSettings->getTranslateArticlePromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_no_prompt'),
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_prompt_missing'),
            );
        }

        return $prompt;
    }

    private function resolveSourceMarkdown(SeoArticle $sourceArticle, string $sourceHtml): string
    {
        $html = trim($sourceHtml);
        if ($html === '') {
            $html = trim((string) ($sourceArticle->body ?? ''));
        }

        if ($html === '') {
            return '';
        }

        return trim($this->workflowParser->convertHtmlFragmentToMarkdown($html));
    }

    private function assertDefaultLanguageSource(SeoArticle $sourceArticle): void
    {
        $site = $sourceArticle->site instanceof Site ? $sourceArticle->site : null;
        $currentLang = trim((string) ($sourceArticle->language ?? 'vi'));
        if (! $this->polylang->isDefaultLanguage($currentLang, $site)) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_not_default_language'),
            );
        }
    }

    private function assertDistinctLinkedArticles(SeoArticle $sourceArticle, SeoArticle $targetArticle): void
    {
        if ((int) $sourceArticle->id === (int) $targetArticle->id) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_invalid_target'),
            );
        }

        if ((int) ($sourceArticle->site_id ?? 0) !== (int) ($targetArticle->site_id ?? 0)) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_invalid_target'),
            );
        }

        $sourceGroup = (int) ($sourceArticle->translation_group_id ?? 0);
        $targetGroup = (int) ($targetArticle->translation_group_id ?? 0);
        if ($sourceGroup > 0 && $targetGroup > 0 && $sourceGroup !== $targetGroup) {
            throw new \InvalidArgumentException(
                __('seo-content-ai::filament.article_edit.translate_invalid_target'),
            );
        }
    }

    private function linkPromptResultToArticles(
        SeoArticle $sourceArticle,
        SeoArticle $targetArticle,
        SeoPrompt $prompt,
        PromptResult $result,
    ): void {
        $resultId = (int) $result->getKey();
        if ($resultId <= 0) {
            return;
        }

        $meta = [
            'prompt_id' => (int) $prompt->id,
            'prompt_name' => (string) ($prompt->name ?? ''),
            'status' => (string) ($result->status ?? ''),
            'source_article_id' => (int) $sourceArticle->id,
            'target_article_id' => (int) $targetArticle->id,
            'target_lang' => trim((string) ($targetArticle->language ?? '')),
        ];

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $resultId,
            articleId: (int) $sourceArticle->id,
            source: 'article_quick_translate',
            workflowStepTitle: 'Quick translate (AI)',
            meta: $meta,
        );

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $resultId,
            articleId: (int) $targetArticle->id,
            source: 'article_quick_translate',
            workflowStepTitle: 'Quick translate (AI)',
            meta: $meta,
        );
    }
}
