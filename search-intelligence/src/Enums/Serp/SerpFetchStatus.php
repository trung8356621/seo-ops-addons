<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpFetchStatus: string
{
    case Pending = 'pending';
    case Fetched = 'fetched';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
