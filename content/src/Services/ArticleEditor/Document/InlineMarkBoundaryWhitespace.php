<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleEditor\Document;

/**
 * Detect / surgically repair missing spaces at inline mark boundaries.
 *
 * Does NOT invent spaces before punctuation (e.g. </strong>, stays intact).
 * Only inserts when a word character is glued directly to an open/close mark tag.
 */
final class InlineMarkBoundaryWhitespace
{
    private const MARK_OPEN = 'strong|b|em|i|a|u|s|code|mark|span|small|sup|sub';

    private const MARK_CLOSE = 'strong|b|em|i|a|u|s|code|mark|span|small|sup|sub';

    public function countGluedBoundaries(string $html): int
    {
        $open = preg_match_all(
            '/[\p{L}\p{N}]<(?:'.self::MARK_OPEN.')\b/ui',
            $html,
        ) ?: 0;
        $close = preg_match_all(
            '/<\/(?:'.self::MARK_CLOSE.')>[\p{L}\p{N}]/ui',
            $html,
        ) ?: 0;

        return $open + $close;
    }

    /**
     * Insert a single space only where word-char is glued to mark open/close.
     */
    public function repair(string $html): string
    {
        if ($html === '' || $this->countGluedBoundaries($html) === 0) {
            return $html;
        }

        $step1 = preg_replace(
            '/([\p{L}\p{N}])(<(?:'.self::MARK_OPEN.')\b)/ui',
            '$1 $2',
            $html,
        );
        $afterOpen = is_string($step1) ? $step1 : $html;

        $step2 = preg_replace(
            '/(<\/(?:'.self::MARK_CLOSE.')>)([\p{L}\p{N}])/ui',
            '$1 $2',
            $afterOpen,
        );

        return is_string($step2) ? $step2 : $afterOpen;
    }

    /**
     * @return array{html: string, repaired: bool, glued_before: int, glued_after: int}
     */
    public function repairWithReport(string $html): array
    {
        $before = $this->countGluedBoundaries($html);
        if ($before === 0) {
            return [
                'html' => $html,
                'repaired' => false,
                'glued_before' => 0,
                'glued_after' => 0,
            ];
        }

        $repairedHtml = $this->repair($html);
        $after = $this->countGluedBoundaries($repairedHtml);

        return [
            'html' => $repairedHtml,
            'repaired' => $repairedHtml !== $html,
            'glued_before' => $before,
            'glued_after' => $after,
        ];
    }
}
