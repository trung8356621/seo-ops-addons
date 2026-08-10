<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscPageMappingType: string
{
    case ExactCanonicalUrl = 'exact_canonical_url';
    case ExactWpUrl = 'exact_wp_url';
    case SlugMatch = 'slug_match';
    case RedirectMatch = 'redirect_match';
    case Manual = 'manual';
    case ExternalPage = 'external_page';
    case Unmapped = 'unmapped';
}
