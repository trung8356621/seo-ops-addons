<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpFeatureType: string
{
    case FeaturedSnippet = 'featured_snippet';
    case PeopleAlsoAsk = 'people_also_ask';
    case RelatedSearch = 'related_search';
    case VideoCarousel = 'video_carousel';
    case ImagePack = 'image_pack';
    case LocalPack = 'local_pack';
    case Shopping = 'shopping';
    case News = 'news';
    case Forum = 'forum';
    case Discussion = 'discussion';
    case KnowledgePanel = 'knowledge_panel';
    case Other = 'other';
}
