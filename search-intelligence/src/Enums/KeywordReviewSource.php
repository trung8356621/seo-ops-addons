<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums;

enum KeywordReviewSource: string
{
    case ArticleSuggestion = 'article_suggestion';
    case KeywordsTable = 'keywords_table';
    case BulkAction = 'bulk_action';
    case Restore = 'restore';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
