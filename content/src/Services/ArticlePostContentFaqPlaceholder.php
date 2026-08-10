<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
/**
 * Chuẩn hóa placeholder [omi_faq] trong HTML trước khi đẩy lên WordPress.
 */
final class ArticlePostContentFaqPlaceholder
{
    public function normalizeForWordPress(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $placeholder = WorkflowParserService::FAQ_SHORTCODE_PLACEHOLDER;

        $html = (string) preg_replace(
            '/<p[^>]*\bdata-omi-faq=["\']1["\'][^>]*>\s*' . preg_quote($placeholder, '/') . '\s*<\/p>/iu',
            "\n\n{$placeholder}\n\n",
            $html,
        );

        $html = (string) preg_replace(
            '/<p[^>]*\bomi-faq-placeholder\b[^>]*>\s*' . preg_quote($placeholder, '/') . '\s*<\/p>/iu',
            "\n\n{$placeholder}\n\n",
            $html,
        );

        return trim($html);
    }
}
