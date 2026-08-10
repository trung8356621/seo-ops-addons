<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationExecutionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
