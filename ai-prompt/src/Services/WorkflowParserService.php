<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;


use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use Omnichannel\Addons\Seo\Services\SeoScoringCalculator;
use Omnichannel\Addons\Seo\Support\FaqHeadingMatcher;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use DOMDocument;
use DOMElement;
use DOMNode;

class WorkflowParserService
{
    private const SNIPPET_TIER_NONE = 'none';

    private const SNIPPET_TIER_AVERAGE = 'average';

    private const SNIPPET_TIER_GOOD = 'good';

    private const SNIPPET_TIER_EXCELLENT = 'excellent';

    public function __construct(
        private readonly SeoPromptSettingsService $promptSettings,
        private readonly SeoOverviewSettingsService $overviewSettings,
    ) {}

    /**
     * Parse Dàn ý: Chuyển đổi Markdown H2, H3 thành Cấu trúc cây JSON
     * Lưu vào meta bài viết dưới dạng json (seo_article_outlines)
     *
     * @return list<array<string, mixed>>
     */
    public function parseOutline(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $outlines = [];
        $currentH2Index = -1;
        $sortOrder = 1;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^##\s+(.*)/', $line, $matches)) {
                $text = trim(str_replace('**', '', $matches[1]));
                $outlines[] = [
                    'level' => 2,
                    'text' => $text,
                    'children' => [],
                    'sort_order' => $sortOrder++,
                ];
                $currentH2Index = count($outlines) - 1;
            } elseif (preg_match('/^###\s+(.*)/', $line, $matches)) {
                $text = trim(str_replace('**', '', $matches[1]));
                $h3Node = [
                    'level' => 3,
                    'text' => $text,
                    'sort_order' => $sortOrder++,
                ];

                if ($currentH2Index !== -1) {
                    $outlines[$currentH2Index]['children'][] = $h3Node;
                } else {
                    $outlines[] = [
                        'level' => 3,
                        'text' => $text,
                        'children' => [],
                        'sort_order' => $sortOrder++,
                    ];
                }
            }
        }

        return $outlines;
    }

    /**
     * Parse Từ khóa: Chuyển đổi Markdown danh mục (### Category) và gạch đầu dòng (-) thành JSON Ngữ nghĩa
     *
     * @return array<string, list<string>>
     */
    public function parseKeywords(string $markdown): array
    {
        $lines = explode("\n", $markdown);
        $result = [];
        $currentCategory = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^###\s+(.*)/', $line, $matches)) {
                $categoryName = trim($matches[1]);
                $categoryName = trim(str_replace(['**', '*'], '', $categoryName));
                $currentCategory = $categoryName;
                $result[$currentCategory] = [];
            } elseif ($currentCategory !== null && preg_match('/^[-*]\s+(.*)/', $line, $matches)) {
                $value = trim($matches[1]);
                $value = trim(str_replace(['**', '*', '_'], '', $value));
                $result[$currentCategory][] = $value;
            }
        }

        return $result;
    }

    /**
     * Bóc tách FAQ chuẩn dựa trên Sequential DOM Traversal + State Machine.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function parseFaqsFromHtml(string $html, bool $treatAllAsFaqSection = false): array
    {
        $html = $this->preprocessHtmlForFaqExtraction($html);
        if ($html === '') {
            return [];
        }

        $faqs = $this->parseFaqsFromStrongParagraphPairs($html, $treatAllAsFaqSection);
        if ($faqs !== []) {
            return $faqs;
        }

        return $this->parseFaqs($html, $treatAllAsFaqSection);
    }

    /**
     * Loại bỏ khối FAQ đã render trên WP (omi-faq-container) trước khi quét / cắt nội dung.
     */
    public function preprocessHtmlForFaqExtraction(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = (string) preg_replace(
            '/<div[^>]*\bomi-faq-container\b[^>]*>[\s\S]*?<\/div>\s*(?:<script[^>]*type=["\']application\/ld\+json["\'][^>]*>[\s\S]*?<\/script>)?/iu',
            '',
            $html,
        );

        return trim($html);
    }

    /**
     * Markdown hoặc HTML (đồng bộ WordPress / workflow).
     *
     * @return list<array{question: string, answer: string}>
     */
    public function parseFaqsFromContent(string $content, bool $treatAllAsFaqSection = false): array
    {
        $content = $this->preprocessHtmlForFaqExtraction($content);
        if ($content === '') {
            return [];
        }

        if (preg_match('/<[a-z][\s\S]*>/i', $content) === 1) {
            return $this->parseFaqsFromHtml($content, $treatAllAsFaqSection);
        }

        if (
            ! $treatAllAsFaqSection
            && $this->shouldParseMarkdownAsStandaloneFaqSection($content)
        ) {
            return $this->parseFaqs($content, true);
        }

        return $this->parseFaqs($content, $treatAllAsFaqSection);
    }

    /**
     * Tiêu đề khối FAQ (H2/H3) — ưu tiên đoạn chọn, sau đó toàn bài.
     *
     * @return array{level: int, text: string, html: string, heading_line: string, source: string}|null
     */
    public function findFaqSectionHeadingInContent(string $content, string $contextContent = ''): ?array
    {
        $content = trim($content);
        $contextContent = trim($contextContent);

        foreach ([['html' => $content, 'source' => 'selection'], ['html' => $contextContent, 'source' => 'article']] as $bucket) {
            if ($bucket['html'] === '') {
                continue;
            }

            $found = $this->findFaqSectionHeadingInSingleContent($bucket['html']);
            if ($found !== null) {
                $found['source'] = $bucket['source'];

                return $found;
            }
        }

        return null;
    }

    /**
     * Chẩn đoán tách FAQ thủ công (debug UI).
     *
     * @return array{
     *     heading: array<string, mixed>|null,
     *     parsed_total: int,
     *     valid_pairs: int,
     *     question_candidates: list<string>,
     *     skipped: list<array{question: string, reason: string}>,
     * }
     */
    public function diagnoseManualFaqExtract(string $fragment, string $articleHtml = ''): array
    {
        $fragment = trim($fragment);
        $parsed = $fragment !== '' ? $this->parseFaqsFromHtml($fragment, true) : [];

        $valid = 0;
        $skipped = [];

        foreach ($parsed as $row) {
            $question = trim((string) ($row['question'] ?? ''));
            $answer = trim((string) ($row['answer'] ?? ''));
            if ($question === '') {
                continue;
            }

            if ($answer !== '') {
                $valid++;

                continue;
            }

            $skipped[] = [
                'question' => $question,
                'reason' => 'empty_answer',
            ];
        }

        return [
            'heading' => $this->findFaqSectionHeadingInContent($fragment, $articleHtml),
            'parsed_total' => count($parsed),
            'valid_pairs' => $valid,
            'question_candidates' => $this->scanFaqQuestionCandidatesInHtml($fragment),
            'skipped' => $skipped,
        ];
    }

    /**
     * @return array{level: int, text: string, html: string, heading_line: string}|null
     */
    private function findFaqSectionHeadingInSingleContent(string $content): ?array
    {
        $content = trim($content);
        if ($content === '') {
            return null;
        }

        if (preg_match('/<[a-z][\s\S]*>/i', $content) === 1) {
            $root = $this->loadHtmlFaqRoot($content);
            if ($root === null) {
                return null;
            }

            foreach ($this->collectFaqBlockElements($root) as $block) {
                $tag = strtolower($block->tagName);
                $level = $this->htmlHeadingLevel($tag);
                if ($level === null) {
                    continue;
                }

                $headingLine = str_repeat('#', $level).' '.$this->elementText($block);
                if (! $this->isFaqSectionHeading($headingLine)) {
                    continue;
                }

                $dom = $block->ownerDocument;
                $html = $dom instanceof DOMDocument ? (string) $dom->saveHTML($block) : '';

                return [
                    'level' => $level,
                    'text' => $this->headingText($headingLine),
                    'html' => trim($html),
                    'heading_line' => $headingLine,
                ];
            }

            return null;
        }

        foreach (explode("\n", $content) as $line) {
            $trimmed = trim($line);
            $level = $this->lineHeadingLevel($trimmed);
            if ($level === null || ! $this->isFaqSectionHeading($trimmed)) {
                continue;
            }

            $text = $this->headingText($trimmed);

            return [
                'level' => $level,
                'text' => $text,
                'html' => '<h'.$level.'>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h'.$level.'>',
                'heading_line' => $trimmed,
            ];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function scanFaqQuestionCandidatesInHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '' || preg_match('/<[a-z][\s\S]*>/i', $html) !== 1) {
            return [];
        }

        $root = $this->loadHtmlFaqRoot($html);
        if ($root === null) {
            return [];
        }

        $candidates = [];

        foreach ($this->collectFaqBlockElements($root) as $block) {
            $tag = strtolower($block->tagName);
            $level = $this->htmlHeadingLevel($tag);

            if ($level !== null) {
                $headingLine = str_repeat('#', $level).' '.$this->elementText($block);
                if ($this->isFaqSectionHeading($headingLine)) {
                    continue;
                }

                if (in_array($tag, ['h3', 'h4', 'h5', 'h6'], true) && ! $this->isFaqSectionHeading($headingLine)) {
                    $question = $this->normalizeExtractedFaqQuestion($this->elementText($block));
                    if ($question !== '') {
                        $candidates[] = $question;
                    }
                }

                continue;
            }

            if ($this->isStrongQuestionParagraph($block)) {
                $question = $this->extractStrongQuestionText($block);
                if ($question !== '') {
                    $candidates[] = $question;
                }
            }
        }

        return $candidates;
    }

    /**
     * Bóc FAQ từ HTML: cặp <p><strong>câu hỏi</strong></p> + (các) <p> trả lời liền kề.
     *
     * @return list<array{question: string, answer: string, more?: string}>
     */
    public function parseFaqsFromStrongParagraphPairs(string $html, bool $treatAllAsFaqSection = false): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        $root = $this->loadHtmlFaqRoot($html);
        if ($root === null) {
            return [];
        }

        $blocks = $this->collectFaqBlockElements($root);
        $faqs = [];
        $inFaqSection = $treatAllAsFaqSection;
        $faqSectionLevel = null;
        $count = count($blocks);

        for ($index = 0; $index < $count; $index++) {
            $block = $blocks[$index];
            $tag = strtolower($block->tagName);
            $level = $this->htmlHeadingLevel($tag);

            if ($level !== null) {
                $headingLine = str_repeat('#', $level).' '.$this->elementText($block);

                if ($this->isFaqSectionHeading($headingLine)) {
                    $inFaqSection = true;
                    $faqSectionLevel = $level;

                    continue;
                }

                if ($inFaqSection && $faqSectionLevel !== null && $level <= $faqSectionLevel) {
                    $inFaqSection = false;
                    $faqSectionLevel = null;
                }

                $isFaqQuestionHeading = $inFaqSection
                    && in_array($tag, ['h3', 'h4', 'h5', 'h6'], true)
                    && ! $this->isFaqSectionHeading($headingLine);

                if (! $isFaqQuestionHeading) {
                    continue;
                }
            }

            if (! $inFaqSection) {
                continue;
            }

            if ($this->isStrongQuestionParagraph($block)) {
                $question = $this->extractStrongQuestionText($block);
                if ($question === '' || $this->isLikelyNonFaqQuestion($question)) {
                    continue;
                }

                [$answerHtml, $moreHtml, $next] = $this->collectFaqAnswerHtml($blocks, $index + 1);

                $answer = trim(implode("\n", array_filter($answerHtml, static fn (string $part): bool => trim($part) !== '')));
                if ($answer !== '') {
                    $more = trim(implode("\n", array_filter($moreHtml, static fn (string $part): bool => trim($part) !== '')));
                    $faqs[] = [
                        'question' => $question,
                        'answer' => $answer,
                        'more' => $more,
                    ];
                }

                $index = $next - 1;

                continue;
            }

            if (in_array($tag, ['h3', 'h4', 'h5', 'h6'], true)) {
                $headingLine = str_repeat('#', $level ?? 3).' '.$this->elementText($block);
                if ($this->isFaqSectionHeading($headingLine)) {
                    continue;
                }

                $question = $this->normalizeExtractedFaqQuestion($this->elementText($block));
                if ($question === '' || $this->isLikelyNonFaqQuestion($question)) {
                    continue;
                }

                [$answerHtml, $moreHtml, $next] = $this->collectFaqAnswerHtml($blocks, $index + 1);

                $answer = trim(implode("\n", array_filter($answerHtml, static fn (string $part): bool => trim($part) !== '')));
                if ($answer !== '') {
                    $more = trim(implode("\n", array_filter($moreHtml, static fn (string $part): bool => trim($part) !== '')));
                    $faqs[] = [
                        'question' => $question,
                        'answer' => $answer,
                        'more' => $more,
                    ];
                }

                $index = $next - 1;
            }
        }

        return $faqs;
    }

    /**
     * Bóc tách FAQ chuẩn chỉ dựa trên DOM Traversal và State Machine.
     * Xử lý trực tiếp HTML thay vì Markdown.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function parseFaqs(string $html, bool $treatAllAsFaqSection = false): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }

        if (preg_match('/<[a-z][\s\S]*>/i', $html) !== 1) {
            return $this->parseFaqsFromMarkdownLegacy($html, $treatAllAsFaqSection);
        }

        $root = $this->loadHtmlFaqRoot($html);
        if ($root === null) {
            return [];
        }

        $blocks = $this->collectFaqBlockElements($root);
        $faqs = [];
        $inFaqSection = $treatAllAsFaqSection;
        $faqSectionLevel = $treatAllAsFaqSection ? 2 : null;
        $currentQuestion = null;
        $currentAnswerHtml = [];

        foreach ($blocks as $node) {
            $tag = strtolower($node->tagName);
            $level = $this->htmlHeadingLevel($tag);
            $text = $this->elementText($node);
            $dom = $node->ownerDocument;

            if ($level !== null) {
                $headingLine = str_repeat('#', $level).' '.$text;

                if ($this->isFaqSectionHeading($headingLine)) {
                    if ($currentQuestion !== null && $this->isValidAnswer($currentAnswerHtml)) {
                        $faqs[] = [
                            'question' => $currentQuestion,
                            'answer' => trim(implode("\n", $currentAnswerHtml)),
                        ];
                    }

                    $inFaqSection = true;
                    $faqSectionLevel = $level;
                    $currentQuestion = null;
                    $currentAnswerHtml = [];

                    continue;
                }

                if ($inFaqSection && $faqSectionLevel !== null && $level <= $faqSectionLevel) {
                    if ($currentQuestion !== null && $this->isValidAnswer($currentAnswerHtml)) {
                        $faqs[] = [
                            'question' => $currentQuestion,
                            'answer' => trim(implode("\n", $currentAnswerHtml)),
                        ];
                    }

                    $inFaqSection = false;
                    $faqSectionLevel = null;
                    $currentQuestion = null;
                    $currentAnswerHtml = [];

                    continue;
                }

                if ($inFaqSection && $level > ($faqSectionLevel ?? 2) && ! $this->isFaqSectionHeading($headingLine)) {
                    if ($currentQuestion !== null && $this->isValidAnswer($currentAnswerHtml)) {
                        $faqs[] = [
                            'question' => $currentQuestion,
                            'answer' => trim(implode("\n", $currentAnswerHtml)),
                        ];
                    }

                    $question = $this->normalizeExtractedFaqQuestion($text);
                    $currentQuestion = ($question !== '' && ! $this->isLikelyNonFaqQuestion($question))
                        ? $question
                        : null;
                    $currentAnswerHtml = [];

                    continue;
                }
            }

            if (! $inFaqSection) {
                continue;
            }

            if ($this->isStrongQuestionParagraph($node) || $this->isHtmlFaqQuestionBlock($node)) {
                if ($currentQuestion !== null && $this->isValidAnswer($currentAnswerHtml)) {
                    $faqs[] = [
                        'question' => $currentQuestion,
                        'answer' => trim(implode("\n", $currentAnswerHtml)),
                    ];
                }

                $question = $this->extractStrongQuestionText($node);
                if ($question === '') {
                    $question = $this->normalizeExtractedFaqQuestion($text);
                }

                $currentQuestion = ($question !== '' && ! $this->isLikelyNonFaqQuestion($question))
                    ? $question
                    : null;
                $currentAnswerHtml = [];

                continue;
            }

            if ($currentQuestion === null) {
                continue;
            }

            if ($tag === 'hr' && $currentAnswerHtml === []) {
                continue;
            }

            $nodeHtml = $dom instanceof DOMDocument ? (string) $dom->saveHTML($node) : '';
            if ($nodeHtml === '') {
                continue;
            }

            if ($currentAnswerHtml === []) {
                $nodeHtml = $this->stripFaqAnswerLabelFromHtml($nodeHtml);
            }

            $currentAnswerHtml[] = $nodeHtml;
        }

        if ($currentQuestion !== null && $this->isValidAnswer($currentAnswerHtml)) {
            $faqs[] = [
                'question' => $currentQuestion,
                'answer' => trim(implode("\n", $currentAnswerHtml)),
            ];
        }

        return $faqs;
    }

    private function isHtmlFaqQuestionBlock(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if (! in_array($tag, ['p', 'li', 'div'], true)) {
            return false;
        }

        return $this->looksLikePrefixedFaqQuestionText($this->elementText($element));
    }

    private function elementLooksLikeFaqQuestionStart(DOMElement $element): bool
    {
        if ($this->isStrongQuestionParagraph($element) || $this->isHtmlFaqQuestionBlock($element)) {
            return true;
        }

        $tag = strtolower($element->tagName);
        if (! in_array($tag, ['ul', 'ol'], true)) {
            return false;
        }

        foreach ($element->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if (strtolower($child->tagName) !== 'li') {
                continue;
            }

            if ($this->isStrongQuestionParagraph($child) || $this->isHtmlFaqQuestionBlock($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback cho Markdown cũ (đảm bảo tương thích workflow hiện có).
     *
     * @return list<array{question: string, answer: string}>
     */
    private function parseFaqsFromMarkdownLegacy(string $markdown, bool $treatAllAsFaqSection = false): array
    {
        $lines = explode("\n", $markdown);
        $faqs = [];
        $currentQuestion = null;
        $answerLines = [];
        $inFaqSection = $treatAllAsFaqSection;
        $faqSectionLevel = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $headingLevel = $this->lineHeadingLevel($trimmed);

            if ($headingLevel !== null) {
                if ($this->isFaqSectionHeading($trimmed)) {
                    if ($currentQuestion !== null && $this->faqHasAnswer($answerLines)) {
                        $faqs[] = [
                            'question' => $currentQuestion,
                            'answer' => trim(implode("\n", $answerLines)),
                        ];
                    }

                    $inFaqSection = true;
                    $faqSectionLevel = $headingLevel;
                    $currentQuestion = null;
                    $answerLines = [];

                    continue;
                }

                if ($inFaqSection && ! $treatAllAsFaqSection && $faqSectionLevel !== null && $headingLevel <= $faqSectionLevel) {
                    if ($currentQuestion !== null && $this->faqHasAnswer($answerLines)) {
                        $faqs[] = [
                            'question' => $currentQuestion,
                            'answer' => trim(implode("\n", $answerLines)),
                        ];
                    }

                    $inFaqSection = false;
                    $faqSectionLevel = null;
                    $currentQuestion = null;
                    $answerLines = [];
                }
            }

            if (! $inFaqSection) {
                continue;
            }

            if ($this->isFaqMarkdownSectionTerminatorLine($trimmed)) {
                if ($currentQuestion !== null && $this->faqHasAnswer($answerLines)) {
                    $faqs[] = [
                        'question' => $currentQuestion,
                        'answer' => trim(implode("\n", $answerLines)),
                    ];
                }

                $inFaqSection = false;
                $faqSectionLevel = null;
                $currentQuestion = null;
                $answerLines = [];

                continue;
            }

            if ($this->isFaqMarkdownBulletQuestion($trimmed)) {
                if ($currentQuestion !== null && $this->faqHasAnswer($answerLines)) {
                    $faqs[] = [
                        'question' => $currentQuestion,
                        'answer' => trim(implode("\n", $answerLines)),
                    ];
                }

                $currentQuestion = $this->parseFaqMarkdownBulletQuestion($trimmed);
                $answerLines = [];

                continue;
            }

            if ($this->isFaqItemHeadingLine($trimmed)) {
                if ($currentQuestion !== null && $this->faqHasAnswer($answerLines)) {
                    $faqs[] = [
                        'question' => $currentQuestion,
                        'answer' => trim(implode("\n", $answerLines)),
                    ];
                }

                $currentQuestion = $this->normalizeExtractedFaqQuestion($this->faqItemHeadingText($trimmed));
                $answerLines = [];

                continue;
            }

            if ($this->isFaqQuestionLine($trimmed)) {
                if ($currentQuestion !== null && $this->faqHasAnswer($answerLines)) {
                    $faqs[] = [
                        'question' => $currentQuestion,
                        'answer' => trim(implode("\n", $answerLines)),
                    ];
                }

                $currentQuestion = $this->normalizeFaqQuestionLine($trimmed);
                $answerLines = [];

                continue;
            }

            if ($currentQuestion !== null && $trimmed !== '' && $this->lineHeadingLevel($trimmed) === null) {
                $answerLines[] = $this->normalizeFaqMarkdownAnswerLine($trimmed);
            }
        }

        if ($inFaqSection && $currentQuestion !== null && $this->faqHasAnswer($answerLines)) {
            $faqs[] = [
                'question' => $currentQuestion,
                'answer' => trim(implode("\n", $answerLines)),
            ];
        }

        return $faqs;
    }

    public const FAQ_SHORTCODE_PLACEHOLDER = '[omi_faq]';

    /**
     * Chuẩn hóa câu hỏi để so khớp panel FAQ / nội dung gốc WordPress.
     */
    public function normalizeFaqQuestionForMatch(string $text): string
    {
        $text = $this->normalizeExtractedFaqQuestion($text);
        $text = mb_strtolower(trim($text));

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
    }

    /**
     * Tiêu đề kiểu «Xem thêm:» — không phải câu hỏi FAQ thật.
     */
    public function isLikelyNonFaqQuestion(string $text): bool
    {
        $plain = mb_strtolower(trim($this->normalizeExtractedFaqQuestion($text)));
        $plain = rtrim($plain, ':：');

        return preg_match(
            '/^(xem\s*th[êe]m|see\s*more|related(?:\s*(?:links|articles|posts))?|đọc\s*thêm|doc\s*them|tìm\s*hiểu\s*thêm|tim\s*hieu\s*them|tham\s*khảo|tham\s*khao|bài\s*viết\s*liên\s*quan|bai\s*viet\s*lien\s*quan|link\s*liên\s*quan)/u',
            $plain,
        ) === 1;
    }

    /**
     * HTML hiển thị placeholder trong editor (khi lưu WordPress chuyển thành shortcode).
     */
    public function faqPlaceholderHtml(): string
    {
        return '<p class="omi-faq-placeholder" data-omi-faq="1">'.self::FAQ_SHORTCODE_PLACEHOLDER.'</p>';
    }

    /**
     * Gỡ shortcode / placeholder / khối FAQ render trước khi ghép lại nội dung gốc.
     */
    public function stripFaqShortcodeArtifacts(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $html = $this->preprocessHtmlForFaqExtraction($html);

        $html = (string) preg_replace(
            '/<p[^>]*class="[^"]*omi-faq-placeholder[^"]*"[^>]*>\s*'.preg_quote(self::FAQ_SHORTCODE_PLACEHOLDER, '/').'\s*<\/p>/iu',
            '',
            $html,
        );

        $html = (string) preg_replace(
            '/\s*'.preg_quote(self::FAQ_SHORTCODE_PLACEHOLDER, '/').'\s*/u',
            '',
            $html,
        );

        return trim($html);
    }

    /**
     * Dựng lại khối FAQ dạng H2/H3 cho editor (khôi phục từ WordPress).
     *
     * @param  list<array{question: string, answer: string, more?: string}>  $faqs
     */
    public function buildFaqSectionHtmlForEditor(array $faqs, string $heading = 'FAQ'): string
    {
        if ($faqs === []) {
            return '';
        }

        $parts = [];
        $heading = trim($heading);
        if ($heading !== '') {
            $parts[] = '<h2>'.htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h2>';
        }

        foreach ($faqs as $faq) {
            $question = trim((string) ($faq['question'] ?? ''));
            $answer = trim((string) ($faq['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $parts[] = '<h3>'.htmlspecialchars($question, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h3>';

            $more = trim((string) ($faq['more'] ?? ''));
            if ($more !== '') {
                $parts[] = preg_match('/<[a-z][\s\S]*>/i', $more) === 1
                    ? $more
                    : '<p>'.nl2br(htmlspecialchars($more, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false).'</p>';
            }

            $parts[] = preg_match('/<[a-z][\s\S]*>/i', $answer) === 1
                ? $answer
                : '<p>'.nl2br(htmlspecialchars($answer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false).'</p>';
        }

        return implode("\n", $parts);
    }

    /**
     * Giữ tiêu đề khối FAQ, xóa câu hỏi/trả lời, chèn placeholder [omi_faq].
     */
    public function stripFaqContentKeepHeadingHtml(string $html, bool $treatAllAsFaqSection = false): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="omi-faq-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('omi-faq-root');
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $outDom = new DOMDocument;
        libxml_use_internal_errors(true);
        $container = $outDom->createElement('div');
        $outDom->appendChild($container);

        $inFaqSection = $treatAllAsFaqSection;
        $faqSectionLevel = null;
        $placeholderAdded = false;
        $sawFaqQuestionInSection = false;

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                $level = $this->htmlHeadingLevel($tag);
                $headingLine = $level !== null ? str_repeat('#', $level).' '.$this->elementText($node) : '';

                if ($level !== null) {
                    if ($this->isFaqSectionHeading($headingLine)) {
                        $container->appendChild($outDom->importNode($node, true));
                        $inFaqSection = true;
                        $faqSectionLevel = $level;
                        $sawFaqQuestionInSection = false;

                        continue;
                    }

                    if ($inFaqSection && $faqSectionLevel !== null && $level <= $faqSectionLevel && ! $this->isFaqSectionHeading($headingLine)) {
                        $inFaqSection = false;
                        $faqSectionLevel = null;
                        $container->appendChild($outDom->importNode($node, true));

                        continue;
                    }

                    if ($inFaqSection) {
                        $sawFaqQuestionInSection = true;
                        if (! $placeholderAdded) {
                            $this->appendPlaceholderToDom($outDom, $container);
                            $placeholderAdded = true;
                        }

                        continue;
                    }

                    $container->appendChild($outDom->importNode($node, true));

                    continue;
                }

                if ($inFaqSection) {
                    if (! $sawFaqQuestionInSection && ! $this->elementLooksLikeFaqQuestionStart($node)) {
                        // Giữ nguyên phần giữa title FAQ và câu hỏi đầu tiên.
                        $container->appendChild($outDom->importNode($node, true));

                        continue;
                    }

                    $sawFaqQuestionInSection = true;

                    if (! $placeholderAdded) {
                        $this->appendPlaceholderToDom($outDom, $container);
                        $placeholderAdded = true;
                    }

                    continue;
                }

                $container->appendChild($outDom->importNode($node, true));

                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim((string) $node->textContent);
                if ($text === '') {
                    continue;
                }

                if ($inFaqSection) {
                    if (! $sawFaqQuestionInSection) {
                        // Text thô trước câu hỏi đầu tiên vẫn giữ lại.
                        $container->appendChild($outDom->importNode($node, true));

                        continue;
                    }

                    if (! $placeholderAdded) {
                        $this->appendPlaceholderToDom($outDom, $container);
                        $placeholderAdded = true;
                    }

                    continue;
                }

                $container->appendChild($outDom->importNode($node, true));
            }
        }

        if ($inFaqSection && ! $placeholderAdded) {
            $this->appendPlaceholderToDom($outDom, $container);
        }

        return $this->innerHtmlOfElement($container);
    }

    /**
     * Giữ tiêu đề FAQ; chỉ cắt các cặp Q/A còn trong panel, giữ block khác (vd. «Xem thêm:»).
     *
     * @param  list<string>  $panelQuestions  Câu hỏi FAQ còn lại trong panel (text gốc).
     */
    public function stripPanelFaqsFromContent(string $html, array $panelQuestions): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $panelSet = [];
        foreach ($panelQuestions as $question) {
            $key = $this->normalizeFaqQuestionForMatch((string) $question);
            if ($key !== '') {
                $panelSet[$key] = true;
            }
        }

        if ($panelSet === []) {
            return $html;
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="omi-faq-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('omi-faq-root');
        if (! $root instanceof DOMElement) {
            return $html;
        }

        $outDom = new DOMDocument;
        libxml_use_internal_errors(true);
        $container = $outDom->createElement('div');
        $outDom->appendChild($container);

        $inFaqSection = false;
        $faqSectionLevel = null;
        $placeholderAdded = false;
        $sawFaqQuestionInSection = false;
        $skippingPanelAnswer = false;

        foreach (iterator_to_array($root->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                $level = $this->htmlHeadingLevel($tag);
                $headingLine = $level !== null ? str_repeat('#', $level).' '.$this->elementText($node) : '';

                if ($level !== null && $this->isFaqSectionHeading($headingLine)) {
                    $container->appendChild($outDom->importNode($node, true));
                    $inFaqSection = true;
                    $faqSectionLevel = $level;
                    $sawFaqQuestionInSection = false;
                    $skippingPanelAnswer = false;

                    continue;
                }

                if ($inFaqSection && $faqSectionLevel !== null && $level !== null && $level <= $faqSectionLevel && ! $this->isFaqSectionHeading($headingLine)) {
                    $inFaqSection = false;
                    $faqSectionLevel = null;
                    $skippingPanelAnswer = false;
                    $container->appendChild($outDom->importNode($node, true));

                    continue;
                }

                if ($inFaqSection) {
                    $questionKey = $this->extractFaqQuestionMatchKeyFromElement($node, $inFaqSection);
                    if ($questionKey !== null) {
                        $sawFaqQuestionInSection = true;

                        if (isset($panelSet[$questionKey])) {
                            $skippingPanelAnswer = true;

                            if (! $placeholderAdded) {
                                $this->appendPlaceholderToDom($outDom, $container);
                                $placeholderAdded = true;
                            }

                            continue;
                        }

                        $skippingPanelAnswer = false;
                        $container->appendChild($outDom->importNode($node, true));

                        continue;
                    }

                    if ($skippingPanelAnswer) {
                        continue;
                    }

                    if (! $sawFaqQuestionInSection) {
                        $container->appendChild($outDom->importNode($node, true));

                        continue;
                    }

                    $container->appendChild($outDom->importNode($node, true));

                    continue;
                }

                $container->appendChild($outDom->importNode($node, true));

                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE) {
                $text = trim((string) $node->textContent);
                if ($text === '') {
                    continue;
                }

                if ($inFaqSection) {
                    if ($skippingPanelAnswer) {
                        continue;
                    }

                    if (! $sawFaqQuestionInSection) {
                        $container->appendChild($outDom->importNode($node, true));

                        continue;
                    }

                    $container->appendChild($outDom->importNode($node, true));

                    continue;
                }

                $container->appendChild($outDom->importNode($node, true));
            }
        }

        if ($inFaqSection && ! $placeholderAdded && $sawFaqQuestionInSection) {
            $this->appendPlaceholderToDom($outDom, $container);
        }

        return $this->innerHtmlOfElement($container);
    }

    /**
     * Cắt bỏ phần FAQ ra khỏi nội dung HTML và chèn shortcode thay thế.
     */
    public function removeFaqAndAppendShortcode(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (preg_match('/<[a-z][\s\S]*>/i', $html) !== 1) {
            return $this->removeFaqFromMarkdownAndAppendShortcode($html);
        }

        return $this->stripFaqContentKeepHeadingHtml($this->preprocessHtmlForFaqExtraction($html), false);
    }

    private function removeFaqFromMarkdownAndAppendShortcode(string $markdown): string
    {
        if ($this->shouldParseMarkdownAsStandaloneFaqSection($markdown)) {
            return $this->removeStandaloneFaqBlockFromMarkdown($markdown);
        }

        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $result = [];
        $inFaqSection = false;
        $faqSectionLevel = null;
        $placeholderAdded = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $headingLevel = $this->lineHeadingLevel($trimmed);

            if ($headingLevel !== null && $this->isFaqSectionHeading($trimmed)) {
                $result[] = $line;
                $inFaqSection = true;
                $faqSectionLevel = $headingLevel;

                if (! $placeholderAdded) {
                    $result[] = '';
                    $result[] = self::FAQ_SHORTCODE_PLACEHOLDER;
                    $placeholderAdded = true;
                }

                continue;
            }

            if ($inFaqSection && $headingLevel !== null && $faqSectionLevel !== null && $headingLevel <= $faqSectionLevel) {
                $inFaqSection = false;
                $faqSectionLevel = null;
                $result[] = $line;

                continue;
            }

            if ($inFaqSection) {
                continue;
            }

            $result[] = $line;
        }

        if ($inFaqSection && ! $placeholderAdded) {
            $result[] = '';
            $result[] = self::FAQ_SHORTCODE_PLACEHOLDER;
        }

        $cleaned = trim(implode("\n", $result));
        if ($cleaned !== '' && ! str_contains($cleaned, self::FAQ_SHORTCODE_PLACEHOLDER)) {
            $cleaned .= "\n\n".self::FAQ_SHORTCODE_PLACEHOLDER;
        }

        return $cleaned;
    }

    private function removeStandaloneFaqBlockFromMarkdown(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $result = [];
        $inFaqBlock = false;
        $placeholderAdded = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (! $inFaqBlock && ($this->isFaqItemHeadingLine($trimmed) || $this->isFaqQuestionLine($trimmed))) {
                $inFaqBlock = true;

                if (! $placeholderAdded) {
                    if ($result !== [] && trim((string) end($result)) !== '') {
                        $result[] = '';
                    }
                    $result[] = self::FAQ_SHORTCODE_PLACEHOLDER;
                    $placeholderAdded = true;
                }

                continue;
            }

            if ($inFaqBlock) {
                if ($this->isFaqMarkdownSectionTerminatorLine($trimmed)) {
                    break;
                }

                continue;
            }

            $result[] = $line;
        }

        if (! $placeholderAdded) {
            return trim($markdown)."\n\n".self::FAQ_SHORTCODE_PLACEHOLDER;
        }

        $cleaned = trim(implode("\n", $result));
        if ($cleaned === '') {
            return self::FAQ_SHORTCODE_PLACEHOLDER;
        }

        if (! str_contains($cleaned, self::FAQ_SHORTCODE_PLACEHOLDER)) {
            $cleaned .= "\n\n".self::FAQ_SHORTCODE_PLACEHOLDER;
        }

        return $cleaned;
    }

    public function removeFaqAndAppendShortcodeFromContent(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        if (str_contains($content, self::FAQ_SHORTCODE_PLACEHOLDER)) {
            return $content;
        }

        if (preg_match('/<[a-z][\s\S]*>/i', $content) === 1) {
            $content = $this->preprocessHtmlForFaqExtraction($content);
            $stripped = $this->stripFaqContentKeepHeadingHtml($content, false);
            if ($stripped !== '' && str_contains($stripped, self::FAQ_SHORTCODE_PLACEHOLDER)) {
                return $stripped;
            }

            if ($this->parseFaqsFromStrongParagraphPairs($content) !== []
                || $this->parseFaqs($content) !== []) {
                return $this->stripFaqContentKeepHeadingHtml($content, false);
            }

            return $content;
        }

        return $this->removeFaqAndAppendShortcode($content);
    }

    /**
     * Kiểm tra bài viết có bảng Featured Snippet mức rất tốt (đủ ngưỡng rows_max).
     */
    public function hasFeaturedSnippetTable(string $markdown): bool
    {
        $thresholds = $this->promptSettings->getFeaturedSnippetThresholds();
        $metrics = $this->bestMarkdownTableMetrics(
            $markdown,
            $thresholds['min_columns'],
            $thresholds['max_columns'],
        );

        return $metrics !== null && $metrics['data_rows'] >= $thresholds['rows_max'];
    }

    /**
     * Bảng HTML trong editor (TipTap) — không qua Markdown pipe.
     */
    public function hasFeaturedSnippetTableFromHtml(string $html): bool
    {
        $thresholds = $this->promptSettings->getFeaturedSnippetThresholds();
        $metrics = $this->bestHtmlTableMetrics(
            $html,
            $thresholds['min_columns'],
            $thresholds['max_columns'],
        );

        return $metrics !== null && $metrics['data_rows'] >= $thresholds['rows_max'];
    }

    /**
     * Chấm Featured Snippet theo 4 mức (không có / trung bình / tốt / rất tốt).
     *
     * @return array{tier: string, passed: bool, points: int, message: string, data_rows?: int}
     */
    public function resolveFeaturedSnippetTableScore(string $markdown, ?string $sourceContent = null): array
    {
        $thresholds = $this->promptSettings->getFeaturedSnippetThresholds();
        $html = $sourceContent;
        if ($html === null && preg_match('/<[a-z][\s\S]*>/i', $markdown) === 1) {
            $html = $markdown;
        }

        $metrics = $this->findBestFeaturedSnippetTableMetrics($markdown, $html);
        $columnLabel = sprintf('%d–%d cột', $thresholds['min_columns'], $thresholds['max_columns']);
        $tierThresholdLabel = sprintf(
            '%d / %d / %d dòng (trung bình / tốt / rất tốt)',
            $thresholds['rows_min'],
            $thresholds['rows_range'],
            $thresholds['rows_max'],
        );

        if ($metrics === null) {
            return [
                'tier' => self::SNIPPET_TIER_NONE,
                'passed' => false,
                'points' => 0,
                'message' => 'Không có bảng hoặc cột không hợp lệ ('.$columnLabel.'). Ngưỡng: '.$tierThresholdLabel,
            ];
        }

        $dataRows = $metrics['data_rows'];
        $tier = $this->featuredSnippetTierFromDataRows($dataRows, $thresholds);
        $tierLabel = $this->featuredSnippetTierLabel($tier);
        $points = $this->featuredSnippetPointsForTier($tier);

        if ($tier === self::SNIPPET_TIER_NONE) {
            return [
                'tier' => $tier,
                'passed' => false,
                'points' => 0,
                'data_rows' => $dataRows,
                'message' => sprintf(
                    'Không đạt — bảng có %d dòng dữ liệu (cần ≥ %d cho trung bình). %s, %s.',
                    $dataRows,
                    $thresholds['rows_min'],
                    $columnLabel,
                    $tierThresholdLabel,
                ),
            ];
        }

        return [
            'tier' => $tier,
            'passed' => $tier === self::SNIPPET_TIER_EXCELLENT,
            'points' => $points,
            'data_rows' => $dataRows,
            'message' => sprintf(
                '%s — %d dòng dữ liệu, %s (%s)',
                $tierLabel,
                $dataRows,
                $columnLabel,
                $tierThresholdLabel,
            ),
        ];
    }

    /**
     * @return array{data_rows: int, columns: int}|null
     */
    public function findBestFeaturedSnippetTableMetrics(string $markdown, ?string $html = null): ?array
    {
        $thresholds = $this->promptSettings->getFeaturedSnippetThresholds();
        $minCols = $thresholds['min_columns'];
        $maxCols = $thresholds['max_columns'];
        $candidates = [];

        if ($html !== null && trim($html) !== '') {
            foreach ($this->collectHtmlTableMetrics($html, $minCols, $maxCols) as $metrics) {
                $candidates[] = $metrics;
            }
        }

        $markdownSource = $markdown;
        if (preg_match('/<[a-z][\s\S]*>/i', $markdown) === 1) {
            $markdownSource = $this->htmlFragmentToMarkdown($markdown);
        }

        foreach ($this->collectMarkdownTableMetrics($markdownSource, $minCols, $maxCols) as $metrics) {
            $candidates[] = $metrics;
        }

        return $this->pickFeaturedSnippetMetricsByMaxPoints($candidates, $thresholds);
    }

    /**
     * @param  list<array{data_rows: int, columns: int}>  $candidates
     * @param  array{rows_min: int, rows_range: int, rows_max: int, min_columns: int, max_columns: int}  $thresholds
     * @return array{data_rows: int, columns: int}|null
     */
    private function pickFeaturedSnippetMetricsByMaxPoints(array $candidates, array $thresholds): ?array
    {
        $best = null;
        $bestPoints = -1;

        foreach ($candidates as $metrics) {
            $dataRows = (int) ($metrics['data_rows'] ?? 0);
            $tier = $this->featuredSnippetTierFromDataRows($dataRows, $thresholds);
            $points = $this->featuredSnippetPointsForTier($tier);

            if ($best === null || $points > $bestPoints || ($points === $bestPoints && $dataRows > $best['data_rows'])) {
                $best = $metrics;
                $bestPoints = $points;
            }
        }

        return $best;
    }

    /**
     * Markdown hoặc HTML editor.
     */
    public function contentHasFeaturedSnippetTable(string $content): bool
    {
        $content = trim($content);
        if ($content === '') {
            return false;
        }

        if ($this->hasFeaturedSnippetTableFromHtml($content)) {
            return true;
        }

        $markdown = preg_match('/<[a-z][\s\S]*>/i', $content) === 1
            ? $this->htmlFragmentToMarkdown($content)
            : $content;

        return $this->hasFeaturedSnippetTable($markdown);
    }

    /**
     * Tính violation keys bổ sung từ nội dung workflow (FAQ + Featured Snippet).
     *
     * @param  list<array{question: string, answer: string}>  $parsedFaqs
     * @return array{violations: list<string>, parsed_faq_count: int, total_score: int, checklist: array<string, array{passed: bool, points: int, message: string}>}
     */
    public function calculateSeoScore(string $markdown, array $parsedFaqs = [], ?string $sourceContent = null): array
    {
        $violations = [];
        $faqCount = count($parsedFaqs);
        $details = [];

        if ($faqCount > 0) {
            $details['faq'] = [
                'passed' => true,
                'points' => 0,
                'message' => 'Có chứa cấu trúc FAQ chuẩn ('.$faqCount.' câu hỏi)',
            ];
        } else {
            $violations[] = SeoScoringRulesRegistry::KEY_FAQ_MISSING;
            $details['faq'] = [
                'passed' => false,
                'points' => SeoScoringRulesRegistry::deductionFor(SeoScoringRulesRegistry::KEY_FAQ_MISSING),
                'message' => 'Thiếu phần FAQ chuẩn',
            ];
        }

        $tableScore = $this->resolveFeaturedSnippetTableScore($markdown, $sourceContent);
        $snippetViolation = $this->mapFeaturedSnippetTierToViolation((string) ($tableScore['tier'] ?? 'none'));
        if ($snippetViolation !== null) {
            $violations[] = $snippetViolation;
        }

        $details['table'] = [
            'passed' => $tableScore['passed'],
            'points' => $snippetViolation !== null
                ? SeoScoringRulesRegistry::deductionFor($snippetViolation)
                : 0,
            'message' => $tableScore['message'],
            'tier' => $tableScore['tier'],
        ];

        $violations = SeoScoringRulesRegistry::sanitizeViolations($violations);

        return [
            'violations' => $violations,
            'total_score' => SeoScoringCalculator::scoreFromViolations($violations),
            'parsed_faq_count' => $faqCount,
            'checklist' => $details,
        ];
    }

    /**
     * Chấm FAQ + Featured Snippet từ Markdown hoặc HTML (editor / đồng bộ).
     *
     * @param  list<array{question: string, answer: string}>  $parsedFaqs
     * @return array{violations: list<string>, parsed_faq_count: int, total_score: int, checklist: array<string, array{passed: bool, points: int, message: string}>}
     */
    public function calculateSeoScoreFromContent(string $content, array $parsedFaqs = []): array
    {
        $content = trim($content);
        if ($content === '') {
            return $this->calculateSeoScore('', $parsedFaqs);
        }

        if ($parsedFaqs === [] && preg_match('/<[a-z][\s\S]*>/i', $content) === 1) {
            $parsedFaqs = $this->parseFaqsFromContent($content);
        }

        $markdown = preg_match('/<[a-z][\s\S]*>/i', $content) === 1
            ? $this->htmlFragmentToMarkdown($content)
            : $content;

        return $this->calculateSeoScore($markdown, $parsedFaqs, $content);
    }

    /**
     * Chuẩn hóa tiêu đề / từ khóa setting để so khớp FAQ (không phân biệt hoa thường).
     */
    public function normalizeForFaqMatch(string $text): string
    {
        return $this->faqHeadingMatcher()->normalize($text);
    }

    private function faqHeadingMatcher(): FaqHeadingMatcher
    {
        return $this->overviewSettings->faqHeadingMatcher();
    }

    private function isFaqSectionHeading(string $headingLine): bool
    {
        $text = $this->headingText($headingLine);
        if ($text === '' || $this->looksLikeFaqItemHeading($text)) {
            return false;
        }

        return $this->faqHeadingMatcher()->matches($headingLine);
    }

    /** Tiêu đề H3/H4 kiểu «Câu hỏi 1: …?» — không phải tiêu đề vùng FAQ. */
    private function looksLikeFaqItemHeading(string $text): bool
    {
        $plain = trim(str_replace(['**', '*'], '', $text));
        if ($plain === '') {
            return false;
        }

        if ($this->isNumberedFaqQuestionText($plain)) {
            return true;
        }

        return preg_match('/\?\s*$/u', $plain) === 1
            && preg_match('/^(❓\s*)?(câu\s*hỏi|cau\s*hoi)\b/iu', $plain) === 1;
    }

    private function headingText(string $headingLine): string
    {
        $trimmed = trim($headingLine);

        if (preg_match('/^#{1,6}\s+(.*)$/u', $trimmed, $matches) === 1) {
            return $this->stripHeadingLabelPrefixes(trim(str_replace(['**', '*'], '', $matches[1])));
        }

        if (preg_match('/^Section\s+\d+:\s*H[1-6]:\s*(.+)$/iu', $trimmed, $matches) === 1) {
            return trim(str_replace(['**', '*'], '', $matches[1]));
        }

        if (preg_match('/^H[1-6]:\s*(.+)$/iu', $trimmed, $matches) === 1) {
            return trim(str_replace(['**', '*'], '', $matches[1]));
        }

        return '';
    }

    private function lineHeadingLevel(string $line): ?int
    {
        if (preg_match('/^(#{1,6})\s+/u', $line, $matches) === 1) {
            return strlen($matches[1]);
        }

        if (preg_match('/^Section\s+\d+:\s*H([1-6]):\s+/iu', $line, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^H([1-6]):\s+/iu', $line, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    private function isFaqItemHeadingLine(string $line): bool
    {
        if ($this->isFaqSectionHeading($line)) {
            return false;
        }

        $text = $this->faqItemHeadingText($line);
        if ($text === '' || $this->looksLikeNumberedOutlineItem($text)) {
            return false;
        }

        $plain = trim(str_replace(['**', '*'], '', $text));

        // «1. Câu hỏi có dấu ?» — FAQ standalone (không phải mục dàn ý 1. … không dấu ?)
        if (preg_match('/^\d+[\.\)]\s+.+\?/u', $plain) === 1) {
            return true;
        }

        if ($this->looksLikeFaqItemHeading($text)) {
            return true;
        }

        // H3/H4 chỉ khi giống câu hỏi (có ?) — KHÔNG nuốt mọi ### dàn ý bài viết
        // (bug cũ: mọi #{3,6} = FAQ → standalone strip chỉ còn intro).
        $level = $this->lineHeadingLevel($line);
        if ($level !== null && $level >= 3 && str_ends_with($plain, '?')) {
            return true;
        }

        return $this->isFaqQuestionLine($line) || $this->isFaqQuestionLine($plain);
    }

    private function faqItemHeadingText(string $line): string
    {
        if (preg_match('/^#{3,6}\s+(.*)$/u', trim($line), $matches) === 1) {
            return trim(str_replace('**', '', $matches[1]));
        }

        return $this->headingText($line);
    }

    private function isFaqQuestionLine(string $line): bool
    {
        if ($line === '' || $this->isFaqSectionHeading($line)) {
            return false;
        }

        $plain = trim(str_replace(['**', '*'], '', $line));

        return preg_match('/^(❓\s*)?(\d+[\.\)]\s*)?(câu\s*hỏi|cau\s*hoi)/iu', $plain) === 1
            || preg_match('/^(❓\s*)?\d+[\.\)]\s+.+\?/u', $plain) === 1
            || preg_match('/^(❓\s*)?Q\s*\d*\s*:\s*.+/iu', $plain) === 1
            || preg_match('/^(❓\s*)?(Hỏi|Hoi|Câu\s*hỏi|Cau\s*hoi)\s*:\s*.+/iu', $plain) === 1
            || (str_ends_with($plain, '?') && preg_match('/^(❓\s*)?\*\*.+\*\*$/u', trim($line)) === 1);
    }

    private function normalizeFaqQuestionLine(string $line): string
    {
        return $this->normalizeExtractedFaqQuestion($line);
    }

    private function isFaqMarkdownBulletQuestion(string $line): bool
    {
        if (preg_match('/^[-*]\s+(.+)$/u', trim($line), $matches) !== 1) {
            return false;
        }

        $inner = trim(str_replace(['**', '*'], '', $matches[1]));
        if ($inner === '' || $this->looksLikeNumberedOutlineItem($inner)) {
            return false;
        }

        if ($this->looksLikePrefixedFaqQuestionText($inner)) {
            return true;
        }

        return str_ends_with($inner, '?')
            && preg_match('/^[-*]\s+\*\*(.+?)\*\*\s*$/u', trim($line)) === 1;
    }

    private function parseFaqMarkdownBulletQuestion(string $line): string
    {
        if (preg_match('/^[-*]\s+(.+)$/u', trim($line), $matches) !== 1) {
            return '';
        }

        $inner = trim(str_replace(['**', '*'], '', $matches[1]));
        if ($inner === '' || $this->looksLikeNumberedOutlineItem($inner)) {
            return '';
        }

        return $this->normalizeExtractedFaqQuestion($inner);
    }

    /** Mục dàn ý / từ vựng kiểu «1. …» hoặc «2) …» không có dấu ? — không phải câu hỏi FAQ. */
    private function looksLikeNumberedOutlineItem(string $text): bool
    {
        $plain = trim(str_replace(['**', '*'], '', $text));
        if ($plain === '') {
            return false;
        }

        if (preg_match('/\?/u', $plain) === 1) {
            return false;
        }

        return preg_match('/^\d+[\.\)]\s+\S+/u', $plain) === 1;
    }

    private function normalizeFaqMarkdownAnswerLine(string $line): string
    {
        $content = trim($line);
        if (preg_match('/^[-*]\s+(.+)$/u', $content, $matches) === 1) {
            $content = trim($matches[1]);
        }

        $content = preg_replace(
            '/^\*{0,2}\s*(?:trả\s*lời|tra\s*loi|answer|đáp|dap|a)\s*\*{0,2}\s*:\s*/iu',
            '',
            $content,
        ) ?? $content;
        $content = preg_replace('/^\*(?:Trả lời bởi|Tra loi boi)[^*]*\*:\s*/iu', '', $content) ?? $content;
        $content = preg_replace('/^\*([^*]+?)\*:\s*/u', '', $content) ?? $content;
        $content = str_replace(['**', '*'], '', $content);

        return trim($content);
    }

    private function isFaqMarkdownSectionTerminatorLine(string $line): bool
    {
        $plain = mb_strtolower(trim(str_replace(['**', '*'], '', $line)));
        if ($plain === '' || $plain === '---') {
            return false;
        }

        return preg_match(
            '/^(thông\s*tin\s*liên\s*hệ|thong\s*tin\s*lien\s*he|liên\s*hệ|lien\s*he|contact|kết\s*luận|ket\s*luan)\b/iu',
            $plain,
        ) === 1;
    }

    private function stripHeadingLabelPrefixes(string $text): string
    {
        $text = preg_replace('/\bH([1-6]):\s*/iu', '', $text) ?? $text;

        return trim($text);
    }

    private function loadHtmlFaqRoot(string $html): ?DOMElement
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="omi-faq-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('omi-faq-root');

        return $root instanceof DOMElement ? $root : null;
    }

    /**
     * @return list<DOMElement>
     */
    private function collectFaqBlockElements(DOMElement $root): array
    {
        $blocks = [];
        $this->appendFaqBlockElements($root, $blocks);

        return $blocks;
    }

    /**
     * @param  list<DOMElement>  $blocks
     */
    private function appendFaqBlockElements(DOMElement $node, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li'], true)) {
                $blocks[] = $child;

                continue;
            }

            if (in_array($tag, ['div', 'section', 'article', 'blockquote', 'ul', 'ol', 'main'], true)) {
                $this->appendFaqBlockElements($child, $blocks);
            }
        }
    }

    /**
     * <p> mà nội dung chính nằm trong <strong> (câu hỏi), không cần chữ «câu hỏi» / «trả lời».
     */
    private function extractFaqQuestionMatchKeyFromElement(DOMElement $element, bool $inFaqSection): ?string
    {
        if (! $inFaqSection) {
            return null;
        }

        $tag = strtolower($element->tagName);
        $level = $this->htmlHeadingLevel($tag);

        if ($level !== null) {
            $headingLine = str_repeat('#', $level).' '.$this->elementText($element);
            if ($this->isFaqSectionHeading($headingLine)) {
                return null;
            }

            if (in_array($tag, ['h3', 'h4', 'h5', 'h6'], true)) {
                $question = $this->normalizeExtractedFaqQuestion($this->elementText($element));

                return $question !== '' ? $this->normalizeFaqQuestionForMatch($question) : null;
            }

            return null;
        }

        if ($this->isStrongQuestionParagraph($element)) {
            $question = $this->extractStrongQuestionText($element);

            return $question !== '' ? $this->normalizeFaqQuestionForMatch($question) : null;
        }

        return null;
    }

    private function isStrongQuestionParagraph(DOMElement $element): bool
    {
        $tag = strtolower($element->tagName);
        if (! in_array($tag, ['p', 'li'], true)) {
            return false;
        }

        if ($this->isFaqPromoParagraph($element)) {
            return false;
        }

        $fullText = $this->elementText($element);
        if ($fullText === '') {
            return false;
        }

        if ($this->looksLikeStandaloneFaqSectionTitle($fullText)) {
            return false;
        }

        if ($this->looksLikePrefixedFaqQuestionText($fullText)) {
            return true;
        }

        $strongText = $this->concatBoldText($element);
        if (mb_strlen($strongText) < 2) {
            return false;
        }

        if ($this->looksLikePrefixedFaqQuestionText($strongText)) {
            return true;
        }

        $normalizedFull = preg_replace('/\s+/u', '', $fullText) ?? '';
        $normalizedStrong = preg_replace('/\s+/u', '', $strongText) ?? '';

        if ($normalizedFull === $normalizedStrong) {
            return true;
        }

        if ($this->isNumberedFaqQuestionText($fullText) || $this->isNumberedFaqQuestionText($strongText)) {
            return true;
        }

        return $this->firstElementChildTag($element) !== null
            && in_array((string) $this->firstElementChildTag($element), ['strong', 'b'], true)
            && mb_strlen($strongText) >= (int) (mb_strlen($fullText) * 0.45)
            && (str_ends_with(trim($fullText), '?') || str_ends_with(trim($strongText), '?'));
    }

    private function looksLikePrefixedFaqQuestionText(string $text): bool
    {
        $plain = trim(str_replace(['**', '*'], '', $text));
        if ($plain === '') {
            return false;
        }

        return preg_match(
            '/^(❓\s*)?(?:\d+[\.\)]\s*)?(?:Q\s*\d*\s*:|Hỏi\s*:|Hoi\s*:|Câu\s*hỏi\s*:|Cau\s*hoi\s*:)/iu',
            $plain,
        ) === 1;
    }

    private function isFaqPromoParagraph(DOMElement $element): bool
    {
        $text = mb_strtolower($this->elementText($element));

        if ($text === '') {
            return false;
        }

        if (preg_match('/câu\s*hỏi\s+của\s+bạn|cau\s*hoi\s+cua\s+ban/u', $text) === 1) {
            return true;
        }

        return str_contains($text, 'chưa có trong faq')
            || (str_contains($text, 'gọi ngay') && str_contains($text, 'faq'));
    }

    private function isNumberedFaqQuestionText(string $text): bool
    {
        $plain = trim(str_replace(['**', '*'], '', $text));

        return preg_match(
            '/^(❓\s*)?(?:(?:câu\s*hỏi|cau\s*hoi)\s*(\d+)|(?:\d+[\.\)]\s*)?(?:câu\s*hỏi|cau\s*hoi)|Q\s*\d*)\s*:/iu',
            $plain,
        ) === 1;
    }

    private function isFaqAnswerParagraph(DOMElement $element): bool
    {
        if (strtolower($element->tagName) !== 'p') {
            return false;
        }

        if ($this->isStrongQuestionParagraph($element)) {
            return false;
        }

        $text = $this->elementText($element);

        return $text !== '' && ! $this->looksLikeStandaloneFaqSectionTitle($text);
    }

    /**
     * @param  list<DOMElement>  $blocks
     * @return array{0: list<string>, 1: list<string>, 2: int}
     */
    private function collectFaqAnswerHtml(array $blocks, int $startIndex): array
    {
        $count = count($blocks);
        $answerHtml = [];
        $candidateAnswerHtml = [];
        $candidateInsideBlockquote = [];
        $moreHtml = [];
        $next = $startIndex;

        while ($next < $count) {
            $current = $blocks[$next];
            $tag = strtolower($current->tagName);
            $level = $this->htmlHeadingLevel($tag);

            // Gặp heading mới => kết thúc câu trả lời hiện tại.
            if ($level !== null) {
                break;
            }

            // Gặp paragraph strong dạng câu hỏi kế tiếp => dừng.
            if ($this->isStrongQuestionParagraph($current)) {
                break;
            }

            $hasBlockquoteAhead = $this->hasFaqBlockquoteAhead($blocks, $next + 1);

            if (
                $tag === 'p'
                && $hasBlockquoteAhead
                && ! $this->isElementInsideTag($current, 'blockquote')
            ) {
                $moreHtml[] = $this->innerHtmlOfElement($current);
            } elseif ($this->isFaqAnswerParagraph($current)) {
                $answerPart = $this->innerHtmlOfElement($current);
                if ($candidateAnswerHtml === []) {
                    $answerPart = $this->stripFaqAnswerLabelFromHtml($answerPart);
                }

                $candidateAnswerHtml[] = $answerPart;
                $candidateInsideBlockquote[] = $this->isElementInsideTag($current, 'blockquote');
            } elseif ($tag === 'p') {
                $moreHtml[] = $this->innerHtmlOfElement($current);
            }

            $next++;
        }

        // Trả lời trong <blockquote> — gộp toàn bộ; phần «more» ghép vào answer khi không tách được blockquote.
        if (in_array(true, $candidateInsideBlockquote, true)) {
            $answerHtml = $candidateAnswerHtml;
        } else {
            $answerHtml = array_merge($candidateAnswerHtml, $moreHtml);
            $moreHtml = [];
        }

        return [$answerHtml, $moreHtml, $next];
    }

    private function isElementInsideTag(DOMElement $element, string $tagName): bool
    {
        $tagName = strtolower($tagName);
        $parent = $element->parentNode;

        while ($parent instanceof DOMElement) {
            if (strtolower($parent->tagName) === $tagName) {
                return true;
            }

            $parent = $parent->parentNode;
        }

        return false;
    }

    /**
     * @param  list<DOMElement>  $blocks
     */
    private function hasFaqBlockquoteAhead(array $blocks, int $startIndex): bool
    {
        $count = count($blocks);
        for ($i = $startIndex; $i < $count; $i++) {
            $block = $blocks[$i];
            $tag = strtolower($block->tagName);

            if ($tag === 'blockquote' || $this->isElementInsideTag($block, 'blockquote')) {
                return true;
            }

            if ($this->isStrongQuestionParagraph($block)) {
                return false;
            }

            $level = $this->htmlHeadingLevel($tag);
            if ($level !== null) {
                return false;
            }
        }

        return false;
    }

    /**
     * Tiêu đề khối FAQ trong thẻ &lt;p&gt; (hiếm) — không nhầm với «Câu hỏi này…» trong câu trả lời.
     */
    private function looksLikeStandaloneFaqSectionTitle(string $text): bool
    {
        $plain = trim($text);
        if ($plain === '' || mb_strlen($plain) > 140) {
            return false;
        }

        $lower = $this->normalizeForFaqMatch($plain);

        if (preg_match('/\bfaq\b/u', $lower) === 1) {
            return true;
        }

        if (preg_match('/câu\s*hỏi\s+(thường\s*gặp|thuc\s*gap|thực\s*tế)/u', $lower) === 1) {
            return true;
        }

        return preg_match('/^(hỏi\s*đáp|hoi\s*dap|giải\s*đáp|giai\s*dap)\b/u', $lower) === 1;
    }

    private function extractStrongQuestionText(DOMElement $paragraph): string
    {
        $fromStrong = $this->concatBoldText($paragraph);
        $text = $fromStrong !== '' ? $fromStrong : $this->elementText($paragraph);

        return $this->normalizeExtractedFaqQuestion($text);
    }

    private function normalizeExtractedFaqQuestion(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(str_replace(['**', '*'], '', $text));
        if ($text === '') {
            return '';
        }

        $normalized = preg_replace(
            '/^(❓\s*)?(?:(?:câu\s*hỏi|cau\s*hoi)\s*\d*|(?:\d+[\.\)]\s*)?(?:câu\s*hỏi|cau\s*hoi)|Q\s*\d*|Q|Hỏi|Hoi)\s*:\s*/iu',
            '',
            $text,
        );
        $normalized = is_string($normalized) ? $normalized : $text;
        $normalized = preg_replace('/^\?\s*/u', '', trim($normalized)) ?? trim($normalized);
        $normalized = preg_replace('/^\d+[\.\)]\s+/u', '', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * Markdown FAQ kiểu AI: nhiều H3/H4 dạng «1. Câu hỏi?» nhưng không có tiêu đề vùng FAQ.
     */
    public function shouldParseMarkdownAsStandaloneFaqSection(string $markdown): bool
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return false;
        }

        if ($this->findFaqSectionHeadingInContent($markdown) !== null) {
            return false;
        }

        $questionHeadings = 0;
        foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
            $trimmed = trim($line);
            if ($this->lineHeadingLevel($trimmed) === null) {
                continue;
            }

            if (! $this->isFaqItemHeadingLine($trimmed) && ! $this->isFaqQuestionLine($trimmed)) {
                continue;
            }

            $questionHeadings++;
        }

        return $questionHeadings >= 2;
    }

    private function stripFaqAnswerLabelFromHtml(string $html): string
    {
        $html = (string) preg_replace(
            '/<(?:strong|b)[^>]*>[^<]*(?:trả\s*lời|tra\s*loi|đáp|dap|answer|a)\s*:[^<]*<\/(?:strong|b)>\s*/iu',
            '',
            $html,
        );

        $html = (string) preg_replace(
            '/^(?:<p[^>]*>)?\s*(?:trả\s*lời|tra\s*loi|đáp|dap|answer|a)\s*:\s*/iu',
            '',
            $html,
        );

        return $html;
    }

    private function concatBoldText(DOMElement $element): string
    {
        $parts = [];

        foreach (['strong', 'b'] as $tagName) {
            foreach ($element->getElementsByTagName($tagName) as $bold) {
                if (! $bold instanceof DOMElement) {
                    continue;
                }

                $text = trim((string) $bold->textContent);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * @deprecated Use concatBoldText()
     */
    private function concatStrongText(DOMElement $element): string
    {
        return $this->concatBoldText($element);
    }

    private function firstElementChildTag(DOMElement $element): ?string
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                return strtolower($child->tagName);
            }
        }

        return null;
    }

    private function htmlHeadingLevel(string $tag): ?int
    {
        return match ($tag) {
            'h1' => 1,
            'h2' => 2,
            'h3' => 3,
            'h4' => 4,
            'h5' => 5,
            'h6' => 6,
            default => null,
        };
    }

    private function elementText(DOMElement $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $element->textContent) ?? '');
    }

    private function appendPlaceholderToDom(DOMDocument $dom, DOMElement $container): void
    {
        $placeholder = $dom->createElement('p', self::FAQ_SHORTCODE_PLACEHOLDER);
        $placeholder->setAttribute('class', 'omi-faq-placeholder');
        $placeholder->setAttribute('data-omi-faq', '1');
        $container->appendChild($placeholder);
    }

    private function innerHtmlOfElement(DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= $element->ownerDocument?->saveHTML($child) ?? '';
        }

        return trim($html);
    }

    public function convertHtmlFragmentToMarkdown(string $html): string
    {
        $html = $this->stripWordPressShortcodes($html);
        $html = $this->stripFaqShortcodeArtifacts($html);

        return $this->htmlFragmentToMarkdown($html);
    }

    /**
     * Gỡ toàn bộ shortcode WordPress (self-closing, enclosing, lồng nhau).
     * [[shortcode]] (escape WP) được giữ dạng plain text [shortcode].
     */
    public function stripWordPressShortcodes(string $content): string
    {
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        /** @var array<string, string> $escapedLiterals */
        $escapedLiterals = [];
        $content = (string) preg_replace_callback(
            '/\[\[([^\]]+)\]\]/',
            static function (array $matches) use (&$escapedLiterals): string {
                $token = '___WP_SC_ESC_'.count($escapedLiterals).'___';
                $escapedLiterals[$token] = '['.$matches[1].']';

                return $token;
            },
            $content,
        );

        $iterations = 0;
        do {
            $before = $content;

            $content = (string) preg_replace(
                '/\[(?!\/)([a-z0-9_-]+)(?:[^\]]*)?\](.*?)\[\/\1\]/is',
                '$2',
                $content,
            );

            $content = (string) preg_replace(
                '/\[(?:\/[a-z0-9_-]+|[a-z0-9_-]+)(?:[^\]]*)?\]/i',
                '',
                $content,
            );

            $iterations++;
        } while ($content !== $before && $iterations < 50);

        foreach ($escapedLiterals as $token => $literal) {
            $content = str_replace($token, $literal, $content);
        }

        $content = (string) preg_replace('/[ \t]+/u', ' ', $content);
        $content = (string) preg_replace("/\n{3,}/u", "\n\n", $content);

        return trim($content);
    }

    private function htmlFragmentToMarkdown(string $html): string
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $dom->getElementsByTagName('div')->item(0);
        if (! $root instanceof DOMElement) {
            return '';
        }

        $lines = [];
        $this->appendDomNodesAsMarkdown($root, $lines);

        return trim(implode("\n", $lines));
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendDomNodesAsMarkdown(DOMNode $node, array &$lines): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = trim((string) $child->textContent);
                if ($text !== '') {
                    $lines[] = $text;
                }

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            $text = trim(preg_replace('/\s+/u', ' ', (string) $child->textContent) ?? '');

            if ($text === '' && ! in_array($tag, ['ul', 'ol', 'li'], true)) {
                continue;
            }

            match ($tag) {
                'h1' => $lines[] = '# '.$text,
                'h2' => $lines[] = '## '.$text,
                'h3' => $lines[] = '### '.$text,
                'h4' => $lines[] = '#### '.$text,
                'h5' => $lines[] = '##### '.$text,
                'h6' => $lines[] = '###### '.$text,
                'p' => $lines[] = $text,
                'li' => $lines[] = '- '.$text,
                'ul', 'ol' => $this->appendDomNodesAsMarkdown($child, $lines),
                'br' => $lines[] = '',
                default => $this->appendDomNodesAsMarkdown($child, $lines),
            };
        }
    }

    private function faqHasAnswer(array $answerLines): bool
    {
        foreach ($answerLines as $line) {
            if (trim($line) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $answerHtml
     */
    private function isValidAnswer(array $answerHtml): bool
    {
        $text = trim(strip_tags(implode(' ', $answerHtml)));

        return $text !== '';
    }

    /**
     * @return array{data_rows: int, columns: int}|null
     */
    private function htmlTableFeaturedSnippetMetrics(
        DOMElement $table,
        int $minCols,
        int $maxCols,
    ): ?array {
        $rowColCounts = [];
        $headerRowCount = 0;
        $hasFirstColumnDescriptor = true;

        foreach ($table->getElementsByTagName('tr') as $row) {
            if (! $row instanceof DOMElement) {
                continue;
            }

            $cellCount = 0;
            $hasTh = false;
            $firstCellText = '';
            $colIndex = 0;
            foreach ($row->childNodes as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }

                $tag = strtolower($cell->tagName);
                if ($tag === 'td' || $tag === 'th') {
                    $cellCount++;
                    $hasTh = $hasTh || ($tag === 'th');
                    if ($colIndex === 0) {
                        $firstCellText = trim((string) $cell->textContent);
                    }
                    $colIndex++;
                }
            }

            if ($cellCount > 0) {
                $rowColCounts[] = $cellCount;
                if ($hasTh) {
                    $headerRowCount++;
                }
                if ($firstCellText === '') {
                    $hasFirstColumnDescriptor = false;
                }
            }
        }

        if ($rowColCounts === []) {
            return null;
        }

        $colCount = max($rowColCounts);
        if (! $this->featuredSnippetColumnCountPasses($colCount, $minCols, $maxCols, $hasFirstColumnDescriptor)) {
            return null;
        }

        $dataRowCount = count($rowColCounts) - ($headerRowCount > 0 ? 1 : 0);

        return [
            'data_rows' => max(0, $dataRowCount),
            'columns' => $colCount,
        ];
    }

    private function htmlTableMeetsFeaturedSnippetThresholds(
        DOMElement $table,
        int $minDataRows,
        int $minCols,
        int $maxCols,
    ): bool {
        $metrics = $this->htmlTableFeaturedSnippetMetrics($table, $minCols, $maxCols);

        return $metrics !== null && $metrics['data_rows'] >= $minDataRows;
    }

    /**
     * @return list<array{data_rows: int, columns: int}>
     */
    private function collectHtmlTableMetrics(string $html, int $minCols, int $maxCols): array
    {
        $html = trim($html);
        if ($html === '' || preg_match('/<table\b/i', $html) !== 1) {
            return [];
        }

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="omi-snippet-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();

        $root = $dom->getElementById('omi-snippet-root');
        if (! $root instanceof DOMElement) {
            return [];
        }

        $metricsList = [];
        foreach ($root->getElementsByTagName('table') as $table) {
            if (! $table instanceof DOMElement || ! $this->isTopLevelHtmlTable($table)) {
                continue;
            }

            $metrics = $this->htmlTableFeaturedSnippetMetrics($table, $minCols, $maxCols);
            if ($metrics !== null) {
                $metricsList[] = $metrics;
            }
        }

        return $metricsList;
    }

    private function isTopLevelHtmlTable(DOMElement $table): bool
    {
        $parent = $table->parentNode;
        while ($parent instanceof DOMElement) {
            if (strtolower($parent->tagName) === 'table') {
                return false;
            }

            $parent = $parent->parentNode;
        }

        return true;
    }

    /**
     * @return list<array{data_rows: int, columns: int}>
     */
    private function collectMarkdownTableMetrics(string $markdown, int $minCols, int $maxCols): array
    {
        $lines = explode("\n", $markdown);
        $inTable = false;
        $rowCount = 0;
        $colCount = 0;
        $metricsList = [];

        $flush = function () use (&$inTable, &$rowCount, &$colCount, &$metricsList, $minCols, $maxCols): void {
            if (! $inTable) {
                return;
            }

            $dataRows = max(0, $rowCount - 1);
            if ($colCount >= $minCols && $colCount <= $maxCols) {
                $metricsList[] = [
                    'data_rows' => $dataRows,
                    'columns' => $colCount,
                ];
            }

            $inTable = false;
        };

        foreach ($lines as $line) {
            $line = trim($line);

            if (preg_match('/\|.*\|/', $line)) {
                if (! $inTable) {
                    $inTable = true;
                    $rowCount = 0;
                    $cols = array_filter(explode('|', trim($line, '|')), static fn ($c): bool => trim((string) $c) !== '');
                    $colCount = count($cols);
                }

                if (! preg_match('/^\|?[\s\-\:]+\|/', $line)) {
                    $rowCount++;
                }
            } elseif ($inTable) {
                $flush();
            }
        }

        if ($inTable) {
            $flush();
        }

        return $metricsList;
    }

    /**
     * @return array{data_rows: int, columns: int}|null
     */
    private function bestHtmlTableMetrics(string $html, int $minCols, int $maxCols): ?array
    {
        $thresholds = $this->promptSettings->getFeaturedSnippetThresholds();

        return $this->pickFeaturedSnippetMetricsByMaxPoints(
            $this->collectHtmlTableMetrics($html, $minCols, $maxCols),
            $thresholds,
        );
    }

    /**
     * @return array{data_rows: int, columns: int}|null
     */
    private function bestMarkdownTableMetrics(string $markdown, int $minCols, int $maxCols): ?array
    {
        $thresholds = $this->promptSettings->getFeaturedSnippetThresholds();

        return $this->pickFeaturedSnippetMetricsByMaxPoints(
            $this->collectMarkdownTableMetrics($markdown, $minCols, $maxCols),
            $thresholds,
        );
    }

    /**
     * @param  array{rows_min: int, rows_range: int, rows_max: int}  $thresholds
     */
    private function featuredSnippetTierFromDataRows(int $dataRows, array $thresholds): string
    {
        if ($dataRows >= $thresholds['rows_max']) {
            return self::SNIPPET_TIER_EXCELLENT;
        }

        if ($dataRows >= $thresholds['rows_range']) {
            return self::SNIPPET_TIER_GOOD;
        }

        if ($dataRows >= $thresholds['rows_min']) {
            return self::SNIPPET_TIER_AVERAGE;
        }

        return self::SNIPPET_TIER_NONE;
    }

    private function featuredSnippetTierLabel(string $tier): string
    {
        return match ($tier) {
            self::SNIPPET_TIER_EXCELLENT => 'Rất tốt',
            self::SNIPPET_TIER_GOOD => 'Tốt',
            self::SNIPPET_TIER_AVERAGE => 'Trung bình',
            default => 'Không có',
        };
    }

    private function featuredSnippetPointsForTier(string $tier): int
    {
        return match ($tier) {
            self::SNIPPET_TIER_EXCELLENT => 10,
            self::SNIPPET_TIER_GOOD => 6,
            self::SNIPPET_TIER_AVERAGE => 3,
            default => 0,
        };
    }

    private function mapFeaturedSnippetTierToViolation(string $tier): ?string
    {
        return match ($tier) {
            self::SNIPPET_TIER_EXCELLENT => null,
            self::SNIPPET_TIER_GOOD => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_EXCELLENT,
            self::SNIPPET_TIER_AVERAGE => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_BELOW_GOOD,
            default => SeoScoringRulesRegistry::KEY_FEATURED_SNIPPET_MISSING,
        };
    }

    /**
     * Cho phép bảng so sánh có thêm 1 cột đầu làm tiêu chí.
     */
    private function featuredSnippetColumnCountPasses(
        int $colCount,
        int $minCols,
        int $maxCols,
        bool $hasFirstColumnDescriptor,
    ): bool {
        if ($colCount >= $minCols && $colCount <= $maxCols) {
            return true;
        }

        if ($hasFirstColumnDescriptor && $colCount > 1) {
            $effective = $colCount - 1;

            return $effective >= $minCols && $effective <= $maxCols;
        }

        return false;
    }
}
