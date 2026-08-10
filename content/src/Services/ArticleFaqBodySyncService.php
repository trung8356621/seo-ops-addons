<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Models\SeoArticle;

/**
 * Tách FAQ từ HTML body vào bảng seo_faqs khi panel FAQ còn trống.
 */
final class ArticleFaqBodySyncService
{
    public function __construct(
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleFaqExtractDebugService $extractDebug,
    ) {
    }

    /**
     * @return array{
     *     extracted: bool,
     *     faq_count: int,
     *     faqs: list<array<string, mixed>>,
     *     body_html: string,
     *     extract_debug: array<string, mixed>|null,
     * }
     */
    public function extractFromBodyWhenMissing(SeoArticle $article, string $html): array
    {
        $html = trim($html);
        $article->loadMissing('faqs');

        if ($article->faqs->isNotEmpty() || $html === '') {
            return [
                'extracted' => false,
                'faq_count' => $article->faqs->count(),
                'faqs' => $this->faqEditor->payloadForArticle($article),
                'body_html' => $html,
                'extract_debug' => null,
            ];
        }

        $parsed = $this->workflowParser->parseFaqsFromContent($html);
        if ($parsed === []) {
            $diagnosis = $this->workflowParser->diagnoseManualFaqExtract($html);
            $extractDebug = $this->extractDebug->recordFromContentDiagnosis(
                $article,
                $diagnosis,
                'body_sync_no_pairs',
                'article_body',
            );

            return [
                'extracted' => false,
                'faq_count' => 0,
                'faqs' => [],
                'body_html' => $html,
                'extract_debug' => $extractDebug,
            ];
        }

        $this->faqEditor->saveFromEditor($article, $parsed);
        $this->extractDebug->clear($article);

        app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $html);

        $strippedHtml = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($html);
        if (str_contains($strippedHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)
            && ! str_contains($strippedHtml, 'omi-faq-placeholder')
        ) {
            $strippedHtml = (string) preg_replace(
                '/\s*' . preg_quote(WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER, '/') . '\s*/u',
                $this->workflowParser->faqPlaceholderHtml(),
                $strippedHtml,
                1,
            );
        }

        $article = $article->fresh() ?? $article;

        return [
            'extracted' => true,
            'faq_count' => count($parsed),
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'body_html' => $strippedHtml,
            'extract_debug' => null,
        ];
    }
}
