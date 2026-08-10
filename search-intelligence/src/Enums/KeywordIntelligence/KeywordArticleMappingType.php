<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Loại ánh xạ giữa keyword và bài viết.
 */
enum KeywordArticleMappingType: string
{
    case ExistingRankTarget = 'existing_rank_target';
    case CurrentContent = 'current_content';
    case PlannedTarget = 'planned_target';
    case CannibalizationCandidate = 'cannibalization_candidate';
}
