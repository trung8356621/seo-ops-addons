<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Trạng thái vòng đời của một cluster keyword.
 */
enum KeywordClusterStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Excluded = 'excluded';
    case Converted = 'converted';
}
