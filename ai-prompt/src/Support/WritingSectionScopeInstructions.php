<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\AiPrompt\SectionedFree\SectionedFreeSectionUnit;
use Omnichannel\Addons\Content\Services\OutlineStructuredRowsNormalizer;

/**
 * Runtime section-scope block injected into compiled Writing prompt (MULTIPLE_PASS).
 * Not a Prompt UI setting / not a new template.
 */
final class WritingSectionScopeInstructions
{
    public const SCOPE_ARTICLE = 'article';

    public const SCOPE_SECTION = 'section';

    /**
     * Deterministic instruction block that MUST appear in the actual compiled provider prompt.
     */
    public static function forSection(
        string $sectionKind,
        bool $assemblerRendersHeading = true,
        ?string $articleTitle = null,
    ): string {
        $kind = strtolower(trim($sectionKind));
        $lines = [
            '=== WRITING SCOPE: SECTION ===',
            'Đây chỉ là một section của bài viết.',
            'Chỉ viết nội dung thuộc current outline slice được giao.',
            'Không viết section khác.',
            'Không tạo lại SEO title hoặc meta description.',
            'Không dùng previous generated output.',
            'Không dùng full Outline.',
            'Không tự mở rộng scope ngoài parent H2 / current H3 được giao.',
        ];

        $title = trim((string) $articleTitle);
        if ($title !== '') {
            $lines[] = 'ARTICLE CONTEXT (input only — không output H1/title): Article title: '.$title;
        }

        if ($kind === OutlineStructuredRowsNormalizer::KIND_INTRO
            || $kind === SectionedFreeSectionUnit::ROLE_INTRO
        ) {
            $lines[] = 'Step hiện tại là INTRO — chỉ viết phần mở bài; không viết body/FAQ/kết luận.';
        } elseif ($kind === OutlineStructuredRowsNormalizer::KIND_CONCLUSION
            || $kind === SectionedFreeSectionUnit::ROLE_CONCLUSION
        ) {
            $lines[] = 'Step hiện tại là CONCLUSION — chỉ viết kết luận; không viết intro/body/FAQ.';
        } elseif ($kind === OutlineStructuredRowsNormalizer::KIND_FAQ
            || $kind === SectionedFreeSectionUnit::ROLE_FAQ
        ) {
            $lines[] = 'Step hiện tại là FAQ SLOT — không viết Q&A FAQ.';
            $lines[] = 'FAQ content authority = prompt article.faq.generate (micro task sau Assemble).';
            $lines[] = 'Chỉ output placeholder [omi_faq] nếu cần giữ vị trí; không sinh cặp câu hỏi/trả lời.';
        } else {
            $lines[] = 'Không tạo intro/conclusion trừ khi current step có đúng kind đó.';
        }

        if ($assemblerRendersHeading) {
            $lines[] = 'Heading H2/H3 do assembler render — không lặp lại heading ngoài phần được yêu cầu.';
        }

        $lines[] = '=== END WRITING SCOPE ===';

        return implode("\n", $lines);
    }

    /**
     * Wrap slice so scope instructions always survive compile even if SeoPrompt
     * does not reference {{writing_scope_instructions}}.
     */
    public static function wrapSlice(
        string $sliceMarkdown,
        string $sectionKind,
        bool $assemblerRendersHeading = true,
        ?string $articleTitle = null,
    ): string {
        $scope = self::forSection($sectionKind, $assemblerRendersHeading, $articleTitle);
        $slice = trim($sliceMarkdown);
        $title = trim((string) $articleTitle);
        $context = $title !== ''
            ? "=== ARTICLE CONTEXT ===\nArticle title: {$title}\n(Input only — do not output as H1 / SEO title / Meta description)\n=== END ARTICLE CONTEXT ===\n\n"
            : '';

        return $scope."\n\n".$context."=== CURRENT OUTLINE SLICE ===\n"
            .($slice !== '' ? $slice : '(empty slice)')
            ."\n=== END CURRENT OUTLINE SLICE ===";
    }
}
