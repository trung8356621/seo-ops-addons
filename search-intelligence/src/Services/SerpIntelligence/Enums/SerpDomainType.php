<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums;

enum SerpDomainType: string
{
    case Own = 'own';
    case DirectCompetitor = 'direct_competitor';
    case Marketplace = 'marketplace';
    case Publisher = 'publisher';
    case Forum = 'forum';
    case Social = 'social';
    case VideoPlatform = 'video_platform';
    case Government = 'government';
    case Education = 'education';
    case Other = 'other';
}
