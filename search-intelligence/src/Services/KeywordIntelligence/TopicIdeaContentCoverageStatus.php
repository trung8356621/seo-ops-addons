<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

/**
 * Content coverage only — not SEO opportunity / traffic / ranking.
 */
final class TopicIdeaContentCoverageStatus
{
    public const UNCOVERED = 'uncovered';

    public const LIGHT = 'light';

    public const COVERED = 'covered';

    public const DENSE = 'dense';

    /** article_count == this → light */
    public const LIGHT_ARTICLE_MAX = 1;

    /** article_count <= this (and > light) → covered; above → dense */
    public const COVERED_ARTICLE_MAX = 3;

    public static function fromArticleCount(int $articleCount): string
    {
        if ($articleCount <= 0) {
            return self::UNCOVERED;
        }
        if ($articleCount <= self::LIGHT_ARTICLE_MAX) {
            return self::LIGHT;
        }
        if ($articleCount <= self::COVERED_ARTICLE_MAX) {
            return self::COVERED;
        }

        return self::DENSE;
    }
}
