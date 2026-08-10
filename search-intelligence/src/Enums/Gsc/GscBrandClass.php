<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscBrandClass: string
{
    case Brand = 'brand';
    case NonBrand = 'non_brand';
    case Mixed = 'mixed';
    case Unknown = 'unknown';
}
