<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

/**
 * Canonical OpenRouter Free Pool circuit states — keyed by connection_id (+ FREE lane).
 *
 * Behavioral model (storage names kept for compatibility):
 * - Healthy / Degraded → HEALTHY
 * - HardLocked / DailyQuotaLocked → TEMPORARY_LOCKED (always has/gets lock_until; never permanent from quota)
 * - WaitingProbe → PROBE_READY (controlled single probe after lock_until)
 * - Resyncing / Unavailable → non-quota operational holds
 */
enum FreePoolHealthState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    /** @deprecated Name only — behaves as TEMPORARY_LOCKED with lock_until + auto probe. */
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

    /** TEMPORARY_LOCKED / DailyQuota — expires into PROBE_READY automatically. */
    public function isTemporaryLock(): bool
    {
        return $this === self::HardLocked || $this === self::DailyQuotaLocked;
    }

    public function isLocked(): bool
    {
        return $this->blocksNormalFreeRouting();
    }
}
