<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum JobStatus: string
{
    case Pending = 'pending';
    case Reserved = 'reserved';
    case Claimed = 'claimed';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case HumanRequired = 'human_required';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
    case Unknown = 'unknown';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled, self::Skipped => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match ($this) {
            self::Reserved, self::Claimed, self::Running, self::Unknown => true,
            default => false,
        };
    }
}
