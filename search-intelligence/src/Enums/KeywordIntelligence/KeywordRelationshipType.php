<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

/**
 * Loại quan hệ giữa hai keyword.
 */
enum KeywordRelationshipType: string
{
    case Duplicate = 'duplicate';
    case NearDuplicate = 'near_duplicate';
    case SameIntent = 'same_intent';
    case SameSerpIntent = 'same_serp_intent';
    case ParentChild = 'parent_child';
    case Related = 'related';
    case CannibalizationRisk = 'cannibalization_risk';
}
