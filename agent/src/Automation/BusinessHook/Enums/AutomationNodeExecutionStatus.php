<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationNodeExecutionStatus: string
{
    case Pending = 'pending';
    case Scheduled = 'scheduled';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
    case Waiting = 'waiting';
}
