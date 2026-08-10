<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Trạng thái phân tích của một keyword.
 */
enum KeywordAnalysisStatus: string
{
    case Pending = 'pending';
    case Analyzed = 'analyzed';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
}
