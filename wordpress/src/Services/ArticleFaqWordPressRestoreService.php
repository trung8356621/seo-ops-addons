<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Content\Services\ArticleContentFaqService;
use Omnichannel\Addons\Content\Services\ArticleFaqExtractDebugService;
use Omnichannel\Addons\Seo\Support\FaqRowNormalizer;
use Omnichannel\Addons\Media\Services\ArticlePostImagesService;
use Omnichannel\Addons\WordPress\Services\WordPressArticleContentService;

/**
 * Khôi phục nội dung bài (kèm khối FAQ gốc) từ WordPress khi panel FAQ bị xóa sạch.
 */
final class ArticleFaqWordPressRestoreService
{
    public function __construct(
        private readonly WordPressArticleContentService $wordpressContent,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly ArticleFaqExtractDebugService $extractDebug,
        private readonly WorkflowParserService $workflowParser,
    ) {}

    /**
     * @return array{restored: bool, editor_html: ?string, message: string}
     */
    public function restoreWhenFaqsCleared(SeoArticle $article): array
    {
        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Bài chưa liên kết WordPress.',
            ];
        }

        $post = $this->wordpressContent->fetchFromWordPress($article, importFaqs: false);
        $html = $this->resolveRestoredEditorHtml($article, $post, preferFreshRemote: true);

        if ($html === '') {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Không lấy được nội dung gốc từ WordPress.',
            ];
        }

        $this->contentFaq->persistArticleBodyHtml($article, $html);
        $this->extractDebug->clear($article);

        return [
            'restored' => true,
            'editor_html' => $html,
            'message' => 'Đã khôi phục nội dung gốc từ WordPress (kèm khối FAQ trong editor).',
        ];
    }

    /**
     * Khi xóa một/một vài FAQ khỏi panel: so sánh với bản gốc WP, khôi phục block đã bỏ (vd. «Xem thêm:»),
     * chỉ giữ [omi_faq] cho các FAQ còn lại trong panel.
     *
     * @param  list<array{question?: string, answer?: string, more?: string|null}>  $remainingFaqs
     * @return array{restored: bool, editor_html: ?string, message: string}
     */
    public function restoreAfterFaqRemoved(SeoArticle $article, array $remainingFaqs): array
    {
        if ((int) ($article->wordpressLink?->wp_post_id ?? 0) <= 0) {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Bài chưa liên kết WordPress.',
            ];
        }

        $post = $this->wordpressContent->fetchFromWordPress($article, importFaqs: false);
        $sourceHtml = $this->resolveRestoredEditorHtml($article, $post, preferFreshRemote: true);

        if ($sourceHtml === '') {
            return [
                'restored' => false,
                'editor_html' => null,
                'message' => 'Không lấy được nội dung gốc từ WordPress.',
            ];
        }

        $panelQuestions = [];
        foreach ($remainingFaqs as $faq) {
            if (! is_array($faq)) {
                continue;
            }

            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $panelQuestions[] = $question;
            }
        }

        $sourceHtml = $this->workflowParser->stripFaqShortcodeArtifacts($sourceHtml);
        $sourceHtml = $this->workflowParser->preprocessHtmlForFaqExtraction($sourceHtml);

        $editorHtml = $this->workflowParser->stripPanelFaqsFromContent($sourceHtml, $panelQuestions);

        if ($editorHtml === '') {
            $editorHtml = $sourceHtml;
        } elseif ($panelQuestions !== [] && ! str_contains($editorHtml, WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER)) {
            $editorHtml = $sourceHtml;
        }

        $this->contentFaq->persistArticleBodyHtml($article, $editorHtml);
        $this->extractDebug->clear($article);

        return [
            'restored' => true,
            'editor_html' => $editorHtml,
            'message' => 'Đã khôi phục phần nội dung gốc từ WordPress. FAQ còn lại vẫn trong panel.',
        ];
    }

    /**
     * Legacy no-op: wp_post_content_source removed — canonical content is articles.body / WP fetch.
     */
    public function persistWordPressSourceSnapshot(SeoArticle $article, string $html): void
    {
        // Intentionally empty.
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function resolveRestoredEditorHtml(SeoArticle $article, array $post, bool $preferFreshRemote = false): string
    {
        $article->loadMissing('articleMetas');

        $content = $this->pickRestoredContentSource(
            trim((string) ($post['post_content'] ?? '')),
            trim((string) (
                is_array($post['scoring'] ?? null) ? ($post['scoring']['body'] ?? '') : ''
            )),
        );
        if ($content === '') {
            $scoring = is_array($post['scoring'] ?? null) ? $post['scoring'] : [];
            $content = trim((string) ($scoring['body'] ?? ''));
        }

        $localBody = trim((string) ($article->body ?? ''));

        if ($content === '') {
            return $localBody;
        }

        $wpFaqs = $this->normalizeWordPressFaqRows($post['faqs'] ?? null);
        $content = $this->workflowParser->stripFaqShortcodeArtifacts($content);

        if ($this->workflowParser->parseFaqsFromContent($content) !== []) {
            return $this->injectRestoredPostImages($article, $content, $post);
        }

        if ($wpFaqs !== []) {
            $headingSource = ($preferFreshRemote || $localBody === '') ? $content : $localBody;
            $foundHeading = $this->workflowParser->findFaqSectionHeadingInContent($headingSource);
            $heading = is_array($foundHeading) ? (string) ($foundHeading['text'] ?? 'FAQ') : 'FAQ';
            $faqBlock = $this->workflowParser->buildFaqSectionHtmlForEditor($wpFaqs, $heading);
            $rebuilt = trim($content).($content !== '' ? "\n\n" : '').$faqBlock;

            return $this->injectRestoredPostImages($article, $rebuilt, $post);
        }

        if (! $preferFreshRemote && $localBody !== '') {
            return $this->injectRestoredPostImages($article, $localBody, $post);
        }

        return $this->injectRestoredPostImages($article, $content, $post);
    }

    private function pickRestoredContentSource(string $rawContent, string $renderedBody): string
    {
        return app(ArticlePostImagesService::class)->preferImageRichHtml($rawContent, $renderedBody);
    }

    /**
     * @param  array<string, mixed>  $post
     */
    private function injectRestoredPostImages(SeoArticle $article, string $html, array $post): string
    {
        $postImages = $post['post_images'] ?? null;
        if (! is_array($postImages) || $postImages === []) {
            return $html;
        }

        return app(ArticlePostImagesService::class)
            ->injectIntoEmptySections($article, $html, $postImages);
    }

    /**
     * @return list<array{question: string, answer: string, more?: string}>
     */
    private function normalizeWordPressFaqRows(mixed $faqs): array
    {
        return FaqRowNormalizer::normalizeList($faqs);
    }
}
