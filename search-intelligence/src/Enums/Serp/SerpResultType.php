<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpResultType: string
{
    case Organic = 'organic';
    case FeaturedSnippet = 'featured_snippet';
    case PeopleAlsoAsk = 'people_also_ask';
    case Video = 'video';
    case Image = 'image';
    case News = 'news';
    case LocalPack = 'local_pack';
    case Shopping = 'shopping';
    case Forum = 'forum';
    case Discussion = 'discussion';
    case KnowledgePanel = 'knowledge_panel';
    case Sitelink = 'sitelink';
    case Other = 'other';
}
