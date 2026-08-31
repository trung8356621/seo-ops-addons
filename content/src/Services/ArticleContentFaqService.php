<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionService;
use App\Models\User;

/**
 * Cắt FAQ khỏi Markdown/HTML bài viết và gắn shortcode [omi_faq].
 */
final class ArticleContentFaqService
{
    public function __construct(
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleMarkdownToHtmlService $markdownHtml,
        private readonly ArticlePostContentFaqPlaceholder $postContentPlaceholder,
        private readonly ArticleFaqEditorService $faqEditor,
    ) {}

    /**
     * Thay đoạn FAQ đã chọn trong HTML bài viết bằng bản đã cắt (giữ tiêu đề + placeholder).
     */
    public function replaceFaqFragmentInArticleHtml(string $articleHtml, string $fragmentHtml, string $strippedFragmentHtml): string
    {
        $articleHtml = trim($articleHtml);
        $fragmentHtml = trim($fragmentHtml);
        $strippedFragmentHtml = trim($strippedFragmentHtml);

        if ($articleHtml === '' || $strippedFragmentHtml === '') {
            return $articleHtml;
        }

        if ($fragmentHtml !== '' && str_contains($articleHtml, $fragmentHtml)) {
            return substr_replace($articleHtml, $strippedFragmentHtml, strpos($articleHtml, $fragmentHtml), strlen($fragmentHtml));
        }

        return $this->workflowParser->stripFaqContentKeepHeadingHtml($articleHtml, false);
    }

    /**
     * Bóc FAQ, chèn shortcode, convert markdown → HTML (không ghi DB).
     *
     * @return array{
     *     html: string,
     *     meta_description: string|null,
     *     h1_title: string|null,
     *     faqs: list<array{question: string, answer: string}>,
     * }
     */
    public function convertMarkdownImport(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return [
                'html' => '',
                'meta_description' => null,
                'h1_title' => null,
                'faqs' => [],
            ];
        }

        $prepared = $this->markdownHtml->prepareImport($markdown);
        $markdown = $prepared['markdown'];
        if ($markdown === '') {
            return [
                'html' => '',
                'meta_description' => $prepared['meta_description'],
                'h1_title' => $prepared['h1_title'],
                'faqs' => [],
            ];
        }

        $faqs = $this->parseFaqsForMarkdownImport($markdown);
        $faqs = $this->normalizeFaqRowsForEditor($faqs);

        $cleaned = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($markdown);
        if (
            $faqs !== []
            && ! str_contains($cleaned, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)
            && ! str_contains($cleaned, 'omi-faq-placeholder')
        ) {
            $cleaned = rtrim($cleaned)."\n\n".WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER;
        }
        // CommonMark render as-is — không promote ###→## (legacy heading do Prompt Hook contract xử lý).
        $converted = $this->markdownHtml->convertWithMetadata($cleaned);
        $html = $this->ensureEditorPlaceholderMarkup($converted['html']);
        $h1FromHtml = $this->extractLeadingH1FromHtml($html);
        $html = $h1FromHtml['html'];

        $metaDescription = trim((string) ($converted['meta_description'] ?? ''));
        if ($metaDescription === '' && filled($prepared['meta_description'])) {
            $metaDescription = trim((string) $prepared['meta_description']);
        }

        $h1Title = filled($prepared['h1_title'])
            ? trim((string) $prepared['h1_title'])
            : $h1FromHtml['h1_title'];

        $strippedHtml = $this->markdownHtml->stripMetaDescriptionFromHtml($html);
        $html = $strippedHtml['html'];
        if ($metaDescription === '' && filled($strippedHtml['meta_description'])) {
            $metaDescription = trim((string) $strippedHtml['meta_description']);
        }

        return [
            'html' => $html,
            'meta_description' => $metaDescription !== '' ? $metaDescription : null,
            'h1_title' => filled($h1Title) ? trim((string) $h1Title) : null,
            'faqs' => $faqs,
        ];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function parseFaqsForMarkdownImport(string $markdown): array
    {
        $faqs = $this->workflowParser->parseFaqsFromContent($markdown);
        if ($faqs !== []) {
            return $faqs;
        }

        $htmlPreview = $this->markdownHtml->toHtml($markdown);
        if ($htmlPreview === '') {
            return [];
        }

        return $this->workflowParser->parseFaqsFromContent($htmlPreview);
    }

    /**
     * @param  list<array{question: string, answer: string}>  $faqs
     * @return list<array{question: string, answer: string}>
     */
    private function normalizeFaqRowsForEditor(array $faqs): array
    {
        $rows = [];

        foreach ($faqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            if (preg_match('/<[a-z][\s\S]*>/i', $answer) !== 1) {
                $answer = $this->markdownHtml->toHtml($answer);
            }

            $rows[] = [
                'question' => $question,
                'answer' => $answer,
            ];
        }

        return $rows;
    }

    /**
     * @return array{html: string, h1_title: string|null}
     */
    private function extractLeadingH1FromHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['html' => '', 'h1_title' => null];
        }

        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>\s*/is', $html, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return ['html' => $html, 'h1_title' => null];
        }

        $title = trim(html_entity_decode(strip_tags($matches[1][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $html = trim(substr_replace($html, '', $matches[0][1], strlen($matches[0][0])));

        return [
            'html' => $html,
            'h1_title' => $title !== '' ? $title : null,
        ];
    }

    private function persistMetaDescription(SeoArticle $article, ?string $metaDescription): void
    {
        $metaDescription = trim((string) $metaDescription);
        if ($metaDescription === '') {
            return;
        }

        foreach (['seo_meta_description', 'meta_description'] as $key) {
            $article->articleMetas()->updateOrCreate(
                ['meta_key' => $key],
                ['meta_value' => $metaDescription],
            );
        }
    }

    /**
     * Persist FAQ-stripped HTML into article body.
     * When an active editor session exists: require owning session identity (editor path)
     * or fail locked (external/system path without session).
     *
     * @throws \Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorSessionException
     */
    public function persistArticleBodyHtml(
        SeoArticle $article,
        string $html,
        ?User $user = null,
        ?string $editorSessionId = null,
        int|string|null $expectedDocumentVersion = null,
    ): void {
        $html = trim($html);
        if ($html === '') {
            return;
        }

        $sessions = app(ArticleEditorSessionService::class);
        $active = $sessions->findActiveSession($article);
        if ($active !== null) {
            if (! $user instanceof User || trim((string) $editorSessionId) === '') {
                $sessions->assertNoActiveEditorSession($article, 'faq_body_apply');
            } else {
                $sessions->assertOwningActiveSessionForWrite(
                    $article,
                    $user,
                    $editorSessionId,
                    $expectedDocumentVersion,
                );
            }
        }

        $article->update([
            'body' => $html,
        ]);

        try {
            $fresh = $article->fresh() ?? $article;
            $writer = app(\Omnichannel\Addons\Content\Services\ArticleEditor\Document\ArticleEditorDocumentWriter::class);
            $writer->invalidateForLegacyBodyWrite($fresh, 'faq_body_persist');
            if ($fresh->isDirty('editor_document_status')) {
                $fresh->save();
            }
        } catch (\Throwable) {
            // JSON invalidation best-effort.
        }
    }

    public function normalizeHtmlForWordPress(string $html): string
    {
        return $this->postContentPlaceholder->normalizeForWordPress($html);
    }

    public function stripFaqAndAppendShortcode(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        if (str_contains($markdown, '[omi_faq]')) {
            return $markdown;
        }

        return $this->workflowParser->removeFaqAndAppendShortcodeFromContent($markdown);
    }

    /**
     * Gỡ FAQ inline trong HTML editor, chèn shortcode/placeholder cho panel FAQ.
     */
    public function injectFaqPlaceholderInEditorHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return $this->workflowParser->faqPlaceholderHtml();
        }

        $stripped = $this->workflowParser->removeFaqAndAppendShortcodeFromContent($html);
        if (
            ! str_contains($stripped, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)
            && ! str_contains($stripped, 'omi-faq-placeholder')
        ) {
            $stripped = rtrim($stripped)."\n\n".WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER;
        }

        return $this->ensureEditorPlaceholderMarkup($stripped);
    }

    private function ensureEditorPlaceholderMarkup(string $html): string
    {
        $token = WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER;

        if (! str_contains($html, $token)) {
            return $html;
        }

        if (str_contains($html, 'omi-faq-placeholder')) {
            return $html;
        }

        return (string) preg_replace(
            '/\s*'.preg_quote($token, '/').'\s*/u',
            $this->workflowParser->faqPlaceholderHtml(),
            $html,
            1,
        );
    }

    /**
     * Lưu nội dung đã cắt FAQ vào articles.body (chỉ Laravel; đồng bộ WP qua nút «Đồng bộ»).
     */
    public function applyStrippedContentToArticle(SeoArticle $article, string $markdown): void
    {
        $import = $this->convertMarkdownImport($markdown);
        if ($import['html'] === '' && $import['faqs'] === []) {
            return;
        }

        $cta = app(ArticleCtaPlaceholderService::class)->applyForPublish(
            (int) $article->site_id > 0 ? (int) $article->site_id : null,
            $import['html'],
            $import['faqs'],
        );

        if ($cta['faqs'] !== []) {
            $this->faqEditor->saveFromEditor($article, $cta['faqs']);
        }

        $this->persistMetaDescription($article, $import['meta_description']);
        $this->persistArticleBodyHtml($article, $cta['html']);
    }
}
