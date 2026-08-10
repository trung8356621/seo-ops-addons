<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscPeriodType: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Rolling7 = 'rolling_7';
    case Rolling28 = 'rolling_28';
    case Rolling90 = 'rolling_90';
    case Custom = 'custom';
}
