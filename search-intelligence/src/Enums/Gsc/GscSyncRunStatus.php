<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscSyncRunStatus: string
{
    case Accepted = 'accepted';
    case Processing = 'processing';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
