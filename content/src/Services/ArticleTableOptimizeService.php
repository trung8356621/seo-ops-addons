<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultFeaturedSnippetOptimizePromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptResultLinkService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SiteDomainPromptContextService;
use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;

/**
 * AI Optimize for one selected Featured Snippet table.
 * Uses article.featured_snippet.optimize Settings binding via PromptRunner —
 * same path as Featured Snippet generate (does not require legacy schema_version=1
 * PromptHookRegistry manifests). Preview only; Apply is client-side.
 */
final class ArticleTableOptimizeService
{
    public const HOOK_KEY = DefaultFeaturedSnippetOptimizePromptInstaller::HOOK_KEY;

    public function __construct(
        private readonly PromptBindingResolver $promptBindingResolver,
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly SeoAnalyzerService $seoAnalyzer,
        private readonly SiteDomainPromptContextService $sitePromptContext,
        private readonly WorkflowParserService $workflowParser,
        private readonly SimpleMarkdownHtmlConverter $markdownHtml,
        private readonly PromptResultLinkService $promptResultLinks,
        private readonly ArticleContentFaqService $contentFaq,
    ) {}

    /**
     * @return array{
     *   success: bool,
     *   message: string,
     *   original_html: string,
     *   optimized_html: string,
     *   thresholds: array<string, int>,
     *   original_score: array<string, mixed>,
     *   optimized_score: array<string, mixed>,
     *   prompt_result_id: int|null,
     * }
     */
    public function optimizePreview(SeoArticle $article, string $tableHtml): array
    {
        $original = $this->normalizeTableHtml($tableHtml);
        if ($original === '') {
            return $this->fail('Bảng nguồn trống hoặc không hợp lệ.', $tableHtml);
        }

        if (! $this->promptBindingResolver->isSettingsHookConfigured(self::HOOK_KEY)) {
            return $this->fail(
                'Chưa gắn Prompt Settings cho article.featured_snippet.optimize.',
                $original,
            );
        }

        $thresholds = $this->promptSettings->getFeaturedSnippetThresholds();
        $targetRows = max(1, (int) ($thresholds['rows_max'] ?? 10));
        $originalMetrics = $this->tableMetrics($original, $thresholds);
        $currentRows = (int) ($originalMetrics['data_rows'] ?? 0);

        $article->loadMissing(['site']);
        $focusKeyword = trim($this->seoAnalyzer->resolveFocusKeywordForArticle($article) ?? '');
        $locale = app()->getLocale() ?: 'vi';

        $instruction = sprintf(
            'Expand this comparison table to at least %d data rows (excellent Featured Snippet tier). '
            .'Preserve all existing cells. Add only useful, on-topic rows. Return one HTML table only.',
            $targetRows,
        );

        /** @var SeoPrompt $prompt */
        $prompt = $this->promptBindingResolver->resolveSettingsHook(self::HOOK_KEY);
        $variables = array_merge(
            [
                'table_html' => $original,
                'target_data_rows' => $targetRows,
                'rows_min' => (int) ($thresholds['rows_min'] ?? 3),
                'rows_range' => (int) ($thresholds['rows_range'] ?? 6),
                'rows_max' => $targetRows,
                'min_columns' => (int) ($thresholds['min_columns'] ?? 2),
                'max_columns' => (int) ($thresholds['max_columns'] ?? 5),
                'current_data_rows' => $currentRows,
                'post_title' => trim((string) ($article->title ?? '')),
                'focus_keyword' => $focusKeyword,
                'language' => $locale,
                'domain' => trim((string) ($article->site?->domain ?? '')),
                'optimize_instruction' => $instruction,
                'input' => $original,
            ],
            $this->sitePromptContext->promptVariablesForSite($article->site),
        );

        try {
            $result = $this->promptRunner->run($prompt, $variables);
        } catch (PromptRunException $exception) {
            return $this->fail($exception->getMessage(), $original, $thresholds);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), $original, $thresholds);
        }

        $raw = trim((string) ($result->output_text ?? ''));
        if ($raw === '') {
            return $this->fail('AI không trả về bảng tối ưu.', $original, $thresholds);
        }

        $optimized = $this->extractSingleTableHtml($raw);
        if ($optimized === '') {
            return $this->fail('Kết quả AI không chứa bảng HTML hợp lệ.', $original, $thresholds);
        }

        $validationError = $this->validateOptimizedPreservesContent($original, $optimized);
        if ($validationError !== null) {
            return $this->fail($validationError, $original, $thresholds, $optimized);
        }

        $this->linkPromptResult($article, $prompt, $result, $targetRows);

        $originalScore = $this->workflowParser->resolveFeaturedSnippetTableScore('', $original);
        $optimizedScore = $this->workflowParser->resolveFeaturedSnippetTableScore('', $optimized);

        return [
            'success' => true,
            'message' => 'Đã tạo bản xem trước bảng tối ưu.',
            'original_html' => $original,
            'optimized_html' => $optimized,
            'thresholds' => $thresholds,
            'original_score' => $originalScore,
            'optimized_score' => $optimizedScore,
            'prompt_result_id' => $result->id !== null ? (int) $result->id : null,
        ];
    }

    /**
     * @param  array<string, int>|null  $thresholds
     * @return array{
     *   success: bool,
     *   message: string,
     *   original_html: string,
     *   optimized_html: string,
     *   thresholds: array<string, int>,
     *   original_score: array<string, mixed>,
     *   optimized_score: array<string, mixed>,
     *   prompt_result_id: int|null,
     * }
     */
    private function fail(
        string $message,
        string $original,
        ?array $thresholds = null,
        string $optimized = '',
    ): array {
        $thresholds ??= $this->promptSettings->getFeaturedSnippetThresholds();

        return [
            'success' => false,
            'message' => $message,
            'original_html' => $original,
            'optimized_html' => $optimized,
            'thresholds' => $thresholds,
            'original_score' => $this->workflowParser->resolveFeaturedSnippetTableScore('', $original),
            'optimized_score' => $optimized !== ''
                ? $this->workflowParser->resolveFeaturedSnippetTableScore('', $optimized)
                : ['tier' => 'none', 'passed' => false, 'points' => 0, 'message' => ''],
            'prompt_result_id' => null,
        ];
    }

    public function normalizeTableHtml(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (! str_contains(strtolower($raw), '<table')) {
            $converted = trim($this->markdownHtml->toHtml($raw));
            $raw = $converted !== '' ? $converted : $raw;
        }

        return $this->extractSingleTableHtml($raw);
    }

    public function extractSingleTableHtml(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (! str_contains(strtolower($raw), '<table')) {
            $prepared = $this->contentFaq->prepareEditorHtmlFromAiOutput($raw);
            $raw = trim((string) ($prepared['html'] ?? ''));
        }

        if (preg_match('/<table\b[\s\S]*?<\/table>/i', $raw, $matches) !== 1) {
            return '';
        }

        return trim((string) $matches[0]);
    }

    /**
     * @param  array{rows_min?: int, rows_range?: int, rows_max?: int, min_columns?: int, max_columns?: int}  $thresholds
     * @return array{data_rows: int, columns: int}|null
     */
    private function tableMetrics(string $tableHtml, array $thresholds): ?array
    {
        return $this->workflowParser->findBestFeaturedSnippetTableMetrics('', $tableHtml);
    }

    private function validateOptimizedPreservesContent(string $original, string $optimized): ?string
    {
        $originalCells = $this->flatCellTexts($original);
        if ($originalCells === []) {
            return 'Không đọc được ô của bảng gốc.';
        }

        $optimizedCells = $this->flatCellTexts($optimized);
        if ($optimizedCells === []) {
            return 'Bảng tối ưu không có ô hợp lệ.';
        }

        foreach ($originalCells as $cell) {
            if ($cell === '') {
                continue;
            }
            if (! in_array($cell, $optimizedCells, true)) {
                return 'Bảng tối ưu làm mất nội dung ô hiện có — từ chối Apply.';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function flatCellTexts(string $tableHtml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="seo-tbl-root">'.$tableHtml.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('seo-tbl-root');
        if ($root === null) {
            return [];
        }

        $out = [];
        foreach (['th', 'td'] as $tag) {
            foreach ($root->getElementsByTagName($tag) as $cell) {
                $text = trim(preg_replace('/\s+/u', ' ', $cell->textContent ?? '') ?? '');
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    private function linkPromptResult(
        SeoArticle $article,
        SeoPrompt $prompt,
        PromptResult $result,
        int $targetRows,
    ): void {
        $promptResultId = $result->id !== null ? (int) $result->id : 0;
        if ($promptResultId <= 0) {
            return;
        }

        $this->promptResultLinks->linkPromptResult(
            promptResultId: $promptResultId,
            articleId: (int) $article->id,
            source: 'article_featured_snippet_optimize',
            workflowStepTitle: 'Optimize Featured Snippet Table (AI)',
            meta: [
                'prompt_id' => (int) $prompt->id,
                'hook_key' => self::HOOK_KEY,
                'target_data_rows' => $targetRows,
            ],
        );
    }
}
