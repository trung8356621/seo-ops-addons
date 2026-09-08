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
    ): string
    {
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
            $lines[] = 'Step hiện tại là FAQ — chỉ viết khối FAQ; không viết intro/body/kết luận.';
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
    ): string {
        $scope = self::forSection($sectionKind, $assemblerRendersHeading);
        $slice = trim($sliceMarkdown);

        return $scope."\n\n=== CURRENT OUTLINE SLICE ===\n"
            .($slice !== '' ? $slice : '(empty slice)')
            ."\n=== END CURRENT OUTLINE SLICE ===";
    }
}
