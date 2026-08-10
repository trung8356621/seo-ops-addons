<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Trạng thái vòng đời của một topic trong topical map.
 */
enum KeywordTopicStatus: string
{
    case Draft = 'draft';
    case Reviewed = 'reviewed';
    case Approved = 'approved';
    case Archived = 'archived';
}
