<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Trạng thái review thủ công của một keyword.
 */
enum KeywordReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
