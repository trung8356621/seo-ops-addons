<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleFaqEditorService;
use Omnichannel\Addons\Content\Services\ArticleFaqExtractDebugService;
use Omnichannel\Addons\Seo\Support\FaqRowNormalizer;

/**
 * Import FAQ khi đồng bộ / mở bài từ WordPress (meta WP hoặc quét post_content).
 */
final class ArticleFaqWordPressImportService
{
    public function __construct(
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleFaqExtractDebugService $extractDebug,
        private readonly ArticleContentFaqService $contentFaq,
    ) {
    }

    /**
     * Quét FAQ từ nội dung WP/HTML; ưu tiên parser HTML hơn meta wp_faqs (thường thiếu câu).
     *
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: ?string,
     *     extract_debug: array<string, mixed>|null,
     * }
     */
    public function importWhenPanelEmpty(SeoArticle $article, ?string $html = null): array
    {
        $article->loadMissing('faqs');
        $existingCount = $article->faqs->count();

        $wpFaqs = $this->resolveStoredWordPressFaqs($article);
        $htmlRows = $this->parseRowsFromHtmlCandidates($article, $html);
        $bestRows = $this->pickBestFaqRows($htmlRows, $wpFaqs);

        if ($bestRows === [] && (int) ($article->wordpressLink?->wp_post_id ?? 0) > 0) {
            $post = app(WordPressArticleContentService::class)->fetchFromWordPress($article);
            if ($post !== []) {
                $wpFaqs = FaqRowNormalizer::normalizeList($post['faqs'] ?? null);
                $bestRows = $this->pickBestFaqRows(
                    $this->parseRowsFromHtmlCandidates($article, $html, $post),
                    $wpFaqs,
                );
            }
        }

        if ($bestRows === []) {
            if ($existingCount > 0 || $this->extractDebug->isSuppressed($article)) {
                return [
                    'imported' => false,
                    'faq_count' => $existingCount,
                    'faqs' => $this->faqEditor->payloadForArticle($article),
                    'editor_html' => null,
                    'extract_debug' => null,
                ];
            }

            $sourceForDiagnosis = $this->resolveBestSourceHtml($article, $html);
            $diagnosis = $this->workflowParser->diagnoseManualFaqExtract($sourceForDiagnosis);
            $extractDebug = $this->extractDebug->recordFromContentDiagnosis(
                $article,
                $diagnosis,
                'wp_pull_no_pairs',
                'wp_pull',
            );

            return [
                'imported' => false,
                'faq_count' => $existingCount,
                'faqs' => $this->faqEditor->payloadForArticle($article),
                'editor_html' => null,
                'extract_debug' => $extractDebug,
            ];
        }

        if ($existingCount > 0) {
            if (count($bestRows) <= $existingCount) {
                return [
                    'imported' => false,
                    'faq_count' => $existingCount,
                    'faqs' => $this->faqEditor->payloadForArticle($article),
                    'editor_html' => null,
                    'extract_debug' => null,
                ];
            }

            $article->faqs()->delete();
            $existingCount = 0;
        }

        $sourceHtml = $this->resolveBestSourceHtml($article, $html);

        if ($sourceHtml === '') {
            $sourceHtml = WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER;
        }

        return $this->persistImportedFaqs($article, $sourceHtml, $bestRows);
    }

    /**
     * Sau đồng bộ domain / webhook từ WordPress.
     *
     * @param  array<string, mixed>  $item
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     extract_debug: array<string, mixed>|null,
     * }
     */
    public function importFromWordPressSyncItem(SeoArticle $article, array $item): array
    {
        $this->extractDebug->clear($article);

        $content = trim((string) ($item['post_content'] ?? ''));
        if ($content === '') {
            $scoring = is_array($item['scoring'] ?? null) ? $item['scoring'] : [];
            $content = trim((string) ($scoring['body'] ?? ''));
        }

        $wpFaqs = $this->normalizeWordPressFaqRows($item['faqs'] ?? null);
        if ($wpFaqs !== []) {
            $this->persistWordPressFaqsMeta($article, $wpFaqs);
        }

        $htmlRows = $this->parseRowsFromHtmlCandidates($article, $content, $item);
        $bestRows = $this->pickBestFaqRows($htmlRows, $wpFaqs);

        if ($bestRows === []) {
            return [
                'imported' => false,
                'faq_count' => $article->faqs()->count(),
                'extract_debug' => null,
            ];
        }

        $article->faqs()->delete();

        $result = $this->persistImportedFaqs(
            $article,
            $this->resolvePreferredWordPressSourceHtml($article, $content, $item),
            $bestRows,
        );

        return [
            'imported' => $result['imported'],
            'faq_count' => $result['faq_count'],
            'extract_debug' => $result['extract_debug'],
        ];
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string}>  $rows
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: string,
     *     extract_debug: null,
     * }
     */
    private function persistImportedFaqs(SeoArticle $article, string $sourceHtml, array $rows): array
    {
        $sourceHtml = trim($sourceHtml);
        if ($sourceHtml === '' && $rows !== []) {
            $sourceHtml = WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER;
        }

        app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $sourceHtml);

        $this->faqEditor->saveFromEditor($article, $rows);
        $this->extractDebug->clear($article);

        $strippedHtml = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($sourceHtml);
        if (($strippedHtml === '' || ! str_contains($strippedHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)) && $rows !== []) {
            $strippedHtml = trim($sourceHtml);
            if (! str_contains($strippedHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)) {
                $strippedHtml = trim($strippedHtml . "\n\n" . $this->workflowParser->faqPlaceholderHtml());
            }
        }

        if (! str_contains($strippedHtml, 'omi-faq-placeholder')
            && str_contains($strippedHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)
        ) {
            $strippedHtml = (string) preg_replace(
                '/\s*' . preg_quote(WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER, '/') . '\s*/u',
                $this->workflowParser->faqPlaceholderHtml(),
                $strippedHtml,
                1,
            );
        }

        $this->contentFaq->persistArticleBodyHtml($article, $strippedHtml);
        $article->load('faqs');

        return [
            'imported' => true,
            'faq_count' => $article->faqs->count(),
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'editor_html' => $strippedHtml,
            'extract_debug' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $syncItem
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function parseRowsFromHtmlCandidates(SeoArticle $article, ?string $html = null, ?array $syncItem = null): array
    {
        $bestRows = [];

        foreach ($this->resolveHtmlCandidates($article, $html, $syncItem) as $candidate) {
            $rows = $this->parseRowsFromHtml($candidate);
            if (count($rows) > count($bestRows)) {
                $bestRows = $rows;
            }
        }

        return $bestRows;
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string}>  $htmlRows
     * @param  list<array{question: string, answer: string, more?: string}>  $wpFaqs
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function pickBestFaqRows(array $htmlRows, array $wpFaqs): array
    {
        if ($wpFaqs === []) {
            return $htmlRows;
        }

        if ($htmlRows === []) {
            return $wpFaqs;
        }

        return count($wpFaqs) > count($htmlRows) ? $wpFaqs : $htmlRows;
    }

    /**
     * @param  array<string, mixed>|null  $syncItem
     * @return list<string>
     */
    private function resolveHtmlCandidates(SeoArticle $article, ?string $html = null, ?array $syncItem = null): array
    {
        $candidates = [];

        $push = static function (string $value) use (&$candidates): void {
            $value = trim($value);
            if ($value !== '' && ! in_array($value, $candidates, true)) {
                $candidates[] = $value;
            }
        };

        $push(trim($html ?? ''));
        if (trim($html ?? '') === '') {
            $push($this->resolveContentHtml($article));
        }

        if (is_array($syncItem)) {
            $scoring = is_array($syncItem['scoring'] ?? null) ? $syncItem['scoring'] : [];
            $push((string) ($syncItem['post_content'] ?? ''));
            $push((string) ($scoring['body'] ?? ''));
        }

        $article->loadMissing('articleMetas');
        foreach (['wp_post_content_source', 'wp_post_content'] as $metaKey) {
            $push((string) ($article->articleMetas->firstWhere('meta_key', $metaKey)?->meta_value ?? ''));
        }

        return array_map(
            fn (string $candidate): string => $this->workflowParser->preprocessHtmlForFaqExtraction($candidate),
            $candidates,
        );
    }

    /**
     * @param  array<string, mixed>|null  $syncItem
     */
    private function resolveBestSourceHtml(SeoArticle $article, ?string $html = null, ?array $syncItem = null): string
    {
        foreach ($this->resolveHtmlCandidates($article, $html, $syncItem) as $candidate) {
            if ($this->parseRowsFromHtml($candidate) !== []) {
                return $candidate;
            }
        }

        $candidates = $this->resolveHtmlCandidates($article, $html, $syncItem);

        return $candidates[0] ?? '';
    }

    /**
     * Ưu tiên HTML mới nhất kéo về từ WordPress khi import/sync.
     * Chỉ fallback sang snapshot/meta cũ nếu payload WP trả rỗng hoàn toàn.
     *
     * @param  array<string, mixed>|null  $syncItem
     */
    private function resolvePreferredWordPressSourceHtml(SeoArticle $article, ?string $html = null, ?array $syncItem = null): string
    {
        $remote = trim((string) ($html ?? ''));
        if ($remote !== '') {
            return $this->workflowParser->preprocessHtmlForFaqExtraction($remote);
        }

        if (is_array($syncItem)) {
            $postContent = trim((string) ($syncItem['post_content'] ?? ''));
            if ($postContent !== '') {
                return $this->workflowParser->preprocessHtmlForFaqExtraction($postContent);
            }

            $scoring = is_array($syncItem['scoring'] ?? null) ? $syncItem['scoring'] : [];
            $scoringBody = trim((string) ($scoring['body'] ?? ''));
            if ($scoringBody !== '') {
                return $this->workflowParser->preprocessHtmlForFaqExtraction($scoringBody);
            }
        }

        return $this->resolveBestSourceHtml($article, $html, $syncItem);
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function parseRowsFromHtml(string $html): array
    {
        return $this->normalizeParsedRows($this->workflowParser->parseFaqsFromContent($html));
    }

    /**
     * @param  list<array<string, mixed>>  $parsed
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function normalizeParsedRows(array $parsed): array
    {
        return FaqRowNormalizer::normalizeList($parsed);
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function resolveStoredWordPressFaqs(SeoArticle $article): array
    {
        $article->loadMissing('articleMetas');
        $raw = $article->articleMetas->firstWhere('meta_key', 'wp_faqs')?->meta_value;
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return $this->normalizeWordPressFaqRows(is_array($decoded) ? $decoded : null);
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function normalizeWordPressFaqRows(mixed $faqs): array
    {
        return FaqRowNormalizer::normalizeList($faqs);
    }

    /**
     * @param  list<array{question: string, answer: string, more?: string}>  $faqs
     */
    private function persistWordPressFaqsMeta(SeoArticle $article, array $faqs): void
    {
        $article->articleMetas()->updateOrCreate(
            ['meta_key' => 'wp_faqs'],
            ['meta_value' => json_encode($faqs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        );
    }

    private function resolveContentHtml(SeoArticle $article): string
    {
        $body = trim((string) ($article->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        $article->loadMissing('articleMetas');

        return trim((string) ($article->articleMetas->firstWhere('meta_key', 'wp_post_content')?->meta_value ?? ''));
    }

    /**
     * @return array{
     *     imported: bool,
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     editor_html: null,
     *     extract_debug: null,
     * }
     */
    private function emptyResult(SeoArticle $article, int $existingCount): array
    {
        return [
            'imported' => false,
            'faq_count' => $existingCount,
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'editor_html' => null,
            'extract_debug' => null,
        ];
    }
}
