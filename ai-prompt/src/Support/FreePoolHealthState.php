<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Canonical OpenRouter Free Pool circuit states — keyed by connection_id.
 */
enum FreePoolHealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case HardLocked = 'hard_locked';
    case Resyncing = 'resyncing';
    case WaitingProbe = 'waiting_probe';
    case DailyQuotaLocked = 'daily_quota_locked';
    case Unavailable = 'unavailable';

    public function blocksNormalFreeRouting(): bool
    {
        return match ($this) {
            self::HardLocked,
            self::DailyQuotaLocked,
            self::Resyncing,
            self::WaitingProbe,
            self::Unavailable => true,
            default => false,
        };
    }

    public function isLocked(): bool
    {
        return $this->blocksNormalFreeRouting();
    }
}
