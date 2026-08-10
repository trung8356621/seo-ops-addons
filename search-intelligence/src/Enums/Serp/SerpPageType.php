<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpPageType: string
{
    case Article = 'article';
    case LandingPage = 'landing_page';
    case Service = 'service';
    case Category = 'category';
    case Product = 'product';
    case ProductListing = 'product_listing';
    case Comparison = 'comparison';
    case Review = 'review';
    case Forum = 'forum';
    case Discussion = 'discussion';
    case Video = 'video';
    case News = 'news';
    case Homepage = 'homepage';
    case Tool = 'tool';
    case Documentation = 'documentation';
    case LocalLanding = 'local_landing';
    case Unknown = 'unknown';
}
