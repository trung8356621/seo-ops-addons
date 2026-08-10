<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpSnapshotStatus: string
{
    case Pending = 'pending';
    case Collecting = 'collecting';
    case Normalizing = 'normalizing';
    case Analyzing = 'analyzing';
    case Completed = 'completed';
    case PartiallyCompleted = 'partially_completed';
    case Failed = 'failed';

    /** @return list<self> */
    public static function immutableStatuses(): array
    {
        return [self::Completed, self::PartiallyCompleted];
    }

    public function isImmutable(): bool
    {
        return in_array($this, self::immutableStatuses(), true);
    }
}
