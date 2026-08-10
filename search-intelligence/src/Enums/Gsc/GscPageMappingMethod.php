<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscPageMappingMethod: string
{
    case Manual = 'manual';
    case ExactCanonical = 'exact_canonical';
    case ExactWp = 'exact_wp';
    case Slug = 'slug';
    case Unmapped = 'unmapped';
}
