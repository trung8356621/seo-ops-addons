<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

use Omnichannel\Addons\Content\Support\ArticleGenerationLengthValidator;

/**
 * Text metrics for prompt-hook validation — word count khớp editor JS
 * ({@see resources/js/utils/articleEditorMetrics.js} countWordsFromText).
 */
final class PromptTextMetrics
{
    public static function wordCount(string $text): int
    {
        $normalized = preg_replace('/\s+/u', ' ', $text) ?? '';
        $normalized = trim($normalized);
        if ($normalized === '') {
            return 0;
        }

        $parts = explode(' ', $normalized);

        return count(array_values(array_filter($parts, static fn (string $part): bool => $part !== '')));
    }

    public static function charCount(string $text): int
    {
        return mb_strlen($text);
    }

    /**
     * @param  'words'|'chars'|string  $unit
     */
    public static function measure(string $text, string $unit): int
    {
        return strtolower(trim($unit)) === 'words'
            ? self::wordCount($text)
            : self::charCount($text);
    }

    /**
     * Usability floor for full article generation.
     * hard_floor = max(absolute minimum, target × ratio). Target itself is not a hard fail.
     */
    public static function minWordsFromArticleLength(int $articleLength, int $hardFloor = 300): int
    {
        $articleLength = max(0, $articleLength);
        if ($articleLength <= 0) {
            return max(1, $hardFloor);
        }

        return (new ArticleGenerationLengthValidator)->minimumForTarget($articleLength);
    }
}
