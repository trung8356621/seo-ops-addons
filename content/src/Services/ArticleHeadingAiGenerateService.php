<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Models\SeoArticleHeading;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\ArticleFaqPromptVariablesService;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\Content\Support\ArticlePostTypeResolver;

/**
 * Regenerate a single Outline heading via dedicated outline_heading_regenerator_prompt_id.
 * Does not use the legacy combined article.outline.generate operator Settings binding.
 */
final class ArticleHeadingAiGenerateService
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly ArticleFaqPromptVariablesService $articlePromptVariables,
        private readonly PromptResultLinkService $promptResultLinks,
    ) {
    }

    /**
     * @throws RuntimeException
     */
    public function generateHeadingText(SeoArticle $article, SeoArticleHeading $heading): string
    {
        $prompt = $this->resolvePrompt();
        $article->loadMissing(['site', 'headings']);

        $focusKeyword = trim($this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '');
        $postType = ArticlePostTypeResolver::resolve($article);
        $promptVars = $this->promptSettings->promptVariables($postType);
        $outlineMarkdown = $this->buildOutlineMarkdown($article->headings);

        $variables = array_merge(
            $this->articlePromptVariables->buildForArticle($article),
            $promptVars,
            [
                'focus_keyword' => $focusKeyword,
                'heading_text' => trim((string) $heading->heading_text),
                'heading_level' => (string) ((int) $heading->level),
                'outline' => $outlineMarkdown,
                'outline_markdown' => $outlineMarkdown,
            ],
        );

        if ($focusKeyword !== '') {
            $variables['input'] = $focusKeyword;
        }

        $variables['tone'] = $this->sitePromptContext->resolveToneForSite(
            $article->site,
            $promptVars['tone'] ?? '',
        );

        try {
            $result = $this->promptRunner->run($prompt, $variables);
        } catch (PromptRunException $exception) {
            throw new RuntimeException($exception->getMessage(), 0, $exception);
        }

        $this->linkPromptResultToArticle($article, $prompt, $result, $heading);

        $text = $this->parseHeadingOutput((string) ($result->output_text ?? ''));
        if ($text === '') {
            throw new RuntimeException(
                'AI không trả về heading hợp lệ. Kiểm tra prompt — đầu ra nên là một dòng tiêu đề (plain text hoặc Markdown H2–H4).',
            );
        }

        return $text;
    }

    private function resolvePrompt(): SeoPrompt
    {
        $promptId = $this->workflowSettings->getOutlineHeadingRegeneratorPromptId();
        if ($promptId === null) {
            throw new RuntimeException(
                __('seo-content-ai::filament.article_edit.outline_heading_generate_no_prompt'),
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new RuntimeException(
                __('seo-content-ai::filament.article_edit.outline_heading_generate_prompt_missing'),
            );
        }

        return $prompt;
    }

    /**
     * @param  Collection<int, SeoArticleHeading>  $headings
     */
    private function buildOutlineMarkdown(Collection $headings): string
    {
        $lines = [];
        foreach ($headings->sortBy('sort_order') as $row) {
            $level = max(2, min(6, (int) $row->level));
            $text = trim(preg_replace('/\s+/u', ' ', (string) $row->heading_text) ?? (string) $row->heading_text);
            if ($text === '') {
                continue;
            }

            $lines[] = str_repeat('#', $level).' '.$text;
        }

        return implode("\n", $lines);
    }

    private function parseHeadingOutput(string $raw): string
    {
        $text = trim($raw);
        if ($text === '') {
            return '';
        }

        if (preg_match('/```(?:markdown|md|text)?\s*([\s\S]*?)```/iu', $text, $match) === 1) {
            $text = trim((string) ($match[1] ?? ''));
        }

        foreach (preg_split('/\r\n|\r|\n/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/^#{1,6}\s+/u', '', $line) ?? $line;
            $line = trim($line, " \t\"'“”‘’");

            return Str::limit($line, 255, '');
        }

        return '';
    }

    private function linkPromptResultToArticle(
        SeoArticle $article,
        SeoPrompt $prompt,
        PromptResult $result,
        SeoArticleHeading $heading,
    ): void {
        $resultId = (int) $result->getKey();
        if ($resultId <= 0) {
            return;
        }

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $resultId,
            articleId: (int) $article->id,
            source: 'outline_heading_regenerate',
            workflowStepTitle: 'Regenerate outline heading (AI)',
            meta: [
                'prompt_id' => (int) $prompt->id,
                'prompt_name' => (string) ($prompt->name ?? ''),
                'heading_id' => (int) $heading->id,
                'heading_level' => (int) $heading->level,
                'status' => (string) ($result->status ?? ''),
            ],
        );
    }
}
