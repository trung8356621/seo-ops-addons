<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

enum SeoProviderCategory: string
{
    case SearchConsole = 'search_console';
    case Serp = 'serp';
    case KeywordMetrics = 'keyword_metrics';
    case RankPlatform = 'rank_platform';
    case SeoSuite = 'seo_suite';
}
