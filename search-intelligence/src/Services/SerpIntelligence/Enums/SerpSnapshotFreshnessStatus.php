<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums;

enum SerpSnapshotFreshnessStatus: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
