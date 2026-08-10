<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscSyncStage: string
{
    case Preparing = 'preparing';
    case Fetching = 'fetching';
    case Normalizing = 'normalizing';
    case Persisting = 'persisting';
    case Mapping = 'mapping';
    case Aggregating = 'aggregating';
    case Detecting = 'detecting';
    case Finalizing = 'finalizing';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
