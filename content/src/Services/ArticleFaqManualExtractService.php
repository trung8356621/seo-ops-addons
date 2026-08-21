<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\Seo\Exceptions\FaqManualExtractException;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\ArticleFaqWordPressRestoreService;

final class ArticleFaqManualExtractService
{
    public function __construct(
        private readonly WorkflowParserService $workflowParser,
        private readonly ArticleMarkdownToHtmlService $markdownHtml,
        private readonly ArticleFaqEditorService $faqEditor,
        private readonly ArticleContentFaqService $contentFaq,
        private readonly ArticleFaqExtractDebugService $extractDebug,
    ) {
    }

    /**
     * Bóc FAQ từ HTML đoạn đã chọn, gộp vào FAQ hiện có và lưu DB.
     *
     * @return array{
     *     faqs: list<array{
     *         id: int|null,
     *         question: string,
     *         answer: string,
     *         sort_order: int,
     *         duplicate: bool,
     *         duplicate_scope: ?string,
     *     }>,
     *     editor_html: string,
     * }
     */
    /**
     * @param  \App\Models\User|null  $editorUser  Owning editor session user when called from Article Editor
     */
    public function extractFromHtmlFragment(
        SeoArticle $article,
        string $html,
        string $articleHtml = '',
        ?\App\Models\User $editorUser = null,
        ?string $editorSessionId = null,
        int|string|null $expectedDocumentVersion = null,
    ): array
    {
        $fragment = trim($html);
        if ($fragment === '') {
            throw new FaqManualExtractException(
                'Chưa có nội dung để tách. Chọn đoạn FAQ trong editor hoặc gộp các block (Shift+Click).',
            );
        }

        $articleHtml = trim($articleHtml);
        $diagnosis = $this->workflowParser->diagnoseManualFaqExtract($fragment, $articleHtml);
        $parsed = $this->workflowParser->parseFaqsFromHtml($fragment, treatAllAsFaqSection: true);

        if ($parsed === []) {
            $this->failExtract($article, $fragment, $articleHtml, $diagnosis, 'no_pairs');
        }

        $incoming = [];
        foreach ($parsed as $faq) {
            $question = trim((string) ($faq['question'] ?? ''));
            $answerRaw = trim((string) ($faq['answer'] ?? ''));
            $moreRaw = trim((string) ($faq['more'] ?? ''));
            if ($moreRaw !== '') {
                $answerRaw = $answerRaw !== '' ? $answerRaw . "\n" . $moreRaw : $moreRaw;
            }
            if ($question === '' || $answerRaw === '') {
                continue;
            }

            $incoming[] = [
                'question' => $question,
                'answer' => $this->answerToEditorHtml($answerRaw),
                'more' => '',
            ];
        }

        if ($incoming === []) {
            $this->failExtract($article, $fragment, $articleHtml, $diagnosis, 'no_valid_pairs');
        }

        if ($articleHtml !== '') {
            app(ArticleFaqWordPressRestoreService::class)->persistWordPressSourceSnapshot($article, $articleHtml);
        }

        $merged = $this->mergeWithExisting($this->faqEditor->payloadForArticle($article), $incoming);
        $this->faqEditor->saveFromEditor($article, $merged);

        $strippedFragment = $this->workflowParser->stripFaqContentKeepHeadingHtml($fragment, true);
        if ($strippedFragment === '') {
            $strippedFragment = $this->workflowParser->faqPlaceholderHtml();
        }

        $baseHtml = $articleHtml !== ''
            ? $articleHtml
            : trim((string) ($article->body ?? ''));

        if ($baseHtml === '') {
            $baseHtml = trim((string) $article->articleMetas()
                ->where('meta_key', 'wp_post_content')
                ->value('meta_value'));
        }

        $newHtml = $baseHtml !== ''
            ? $this->contentFaq->replaceFaqFragmentInArticleHtml($baseHtml, $fragment, $strippedFragment)
            : $strippedFragment;

        $this->contentFaq->persistArticleBodyHtml(
            $article,
            $newHtml,
            $editorUser,
            $editorSessionId,
            $expectedDocumentVersion,
        );
        $this->extractDebug->clear($article);

        return [
            'faqs' => $this->faqEditor->payloadForArticle($article),
            'editor_html' => $newHtml,
        ];
    }

    /**
     * @param  array<string, mixed>  $diagnosis
     */
    private function failExtract(
        SeoArticle $article,
        string $fragment,
        string $articleHtml,
        array $diagnosis,
        string $reason,
    ): never {
        /** @var array<string, mixed>|null $heading */
        $heading = $diagnosis['heading'] ?? null;

        $debug = [
            'reason' => $reason,
            'context' => 'manual_selection',
            'heading' => $heading,
            'parsed_total' => (int) ($diagnosis['parsed_total'] ?? 0),
            'valid_pairs' => (int) ($diagnosis['valid_pairs'] ?? 0),
            'question_candidates' => $diagnosis['question_candidates'] ?? [],
            'skipped' => $diagnosis['skipped'] ?? [],
            'fragment_preview' => mb_substr(strip_tags($fragment), 0, 280),
            'selection_has_faq_heading' => ($heading['source'] ?? null) === 'selection',
        ];

        $this->extractDebug->persist($article, $debug);

        $message = $this->buildFailureMessage($debug);

        throw new FaqManualExtractException($message, $debug, is_array($heading) ? $heading : null);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    private function buildFailureMessage(array $debug): string
    {
        $parts = ['Không tách được cặp câu hỏi/trả lời từ đoạn đã chọn.'];

        /** @var array<string, mixed>|null $heading */
        $heading = $debug['heading'] ?? null;
        if (is_array($heading) && trim((string) ($heading['text'] ?? '')) !== '') {
            $source = ($heading['source'] ?? '') === 'article' ? 'trong bài' : 'trong đoạn chọn';
            $parts[] = 'Tiêu đề FAQ đã nhận (' . $source . '): «' . trim((string) $heading['text']) . '».';
        } else {
            $parts[] = 'Không tìm thấy tiêu đề khối FAQ (H2/H3).';
        }

        $candidates = $debug['question_candidates'] ?? [];
        if (is_array($candidates) && $candidates !== []) {
            $parts[] = 'Nhận ' . count($candidates) . ' câu hỏi nhưng thiếu trả lời hoặc không ghép được cặp.';
        }

        $parts[] = 'Chi tiết debug hiển thị ngay khối FAQ bên dưới.';

        return implode(' ', $parts);
    }

    private function answerToEditorHtml(string $answer): string
    {
        if ($answer === '') {
            return '<p></p>';
        }

        if (preg_match('/<[a-z][\s\S]*>/i', $answer) === 1) {
            return $answer;
        }

        return $this->markdownHtml->toHtml($answer);
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<array{question: string, answer: string, more?: string}>  $incoming
     * @return list<array{id?: int|null, question: string, answer: string, more?: string, sort_order?: int}>
     */
    private function mergeWithExisting(array $existing, array $incoming): array
    {
        $rows = [];
        $seen = [];

        foreach ($existing as $row) {
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $key = $this->normalizeQuestion($question);
            $seen[$key] = true;
            $rows[] = [
                'id' => $row['id'] ?? null,
                'question' => $question,
                'answer' => $answer,
                'more' => trim((string) ($row['more'] ?? '')),
                'sort_order' => (int) ($row['sort_order'] ?? count($rows) + 1),
            ];
        }

        foreach ($incoming as $faq) {
            $key = $this->normalizeQuestion($faq['question']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $rows[] = [
                'question' => $faq['question'],
                'answer' => $faq['answer'],
                'more' => trim((string) ($faq['more'] ?? '')),
                'sort_order' => count($rows) + 1,
            ];
        }

        return $rows;
    }

    private function normalizeQuestion(string $question): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($question)) ?? '');
    }
}
