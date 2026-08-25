<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiRuntimeHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unavailable = 'unavailable';
    case BudgetLimited = 'budget_limited';
    case ConnectionLocked = 'connection_locked';
    case NoData = 'no_data';

    public function label(): string
    {
        return match ($this) {
            self::Healthy => 'Healthy',
            self::Degraded => 'Degraded',
            self::Unavailable => 'Unavailable',
            self::BudgetLimited => 'Budget limited',
            self::ConnectionLocked => 'Connection locked',
            self::NoData => 'No data',
        };
    }
}
