<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpContentGapStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Accepted = 'accepted';
    case Ignored = 'ignored';
    case Resolved = 'resolved';
    case Stale = 'stale';
}
