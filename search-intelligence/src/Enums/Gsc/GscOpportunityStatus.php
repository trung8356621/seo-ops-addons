<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscOpportunityStatus: string
{
    case Open = 'open';
    case Reviewed = 'reviewed';
    case Accepted = 'accepted';
    case Ignored = 'ignored';
    case Resolved = 'resolved';
    case Stale = 'stale';
}
