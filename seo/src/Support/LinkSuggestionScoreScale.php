<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Thang điểm link suggestion — 0..100 integer (không dùng 0–1).
 */
final class LinkSuggestionScoreScale
{
    public const MAX = 100;

    public const TITLE_EXACT = 100;

    public const FOCUS_KEYWORD = 95;

    public const TITLE_CONTAINS = 80;

    public const SLUG_MATCH = 75;

    public const KEYWORD_MATCH = 70;

    public const HEADING_MATCH = 65;

    public const META_MATCH = 35;

    public static function primaryMinAccept(): int
    {
        return max(1, (int) config('seo-content-ai.link_suggestions.min_accept_score', 40));
    }

    public static function fallbackMinAccept(): int
    {
        return max(1, (int) config('seo-content-ai.link_suggestions.fallback_min_score', 55));
    }

    public static function clamp(int $score): int
    {
        return max(0, min(self::MAX, $score));
    }
}
