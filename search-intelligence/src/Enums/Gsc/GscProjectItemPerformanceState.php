<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscProjectItemPerformanceState: string
{
    case NotPublished = 'not_published';
    case AwaitingData = 'awaiting_data';
    case New = 'new';
    case Growing = 'growing';
    case Stable = 'stable';
    case Winning = 'winning';
    case Underperforming = 'underperforming';
    case Decaying = 'decaying';
    case NeedsReview = 'needs_review';
    case Unknown = 'unknown';
}
