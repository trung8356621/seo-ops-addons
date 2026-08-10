<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscSearchType: string
{
    case Web = 'web';
    case Image = 'image';
    case Video = 'video';
    case News = 'news';
    case Discover = 'discover';
    case GoogleNews = 'google_news';
}
