<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscQueryMappingType: string
{
    case ExactKeyword = 'exact_keyword';
    case NormalizedKeyword = 'normalized_keyword';
    case NearKeyword = 'near_keyword';
    case ClusterCandidate = 'cluster_candidate';
    case TopicCandidate = 'topic_candidate';
    case Manual = 'manual';
    case Unmapped = 'unmapped';
}
