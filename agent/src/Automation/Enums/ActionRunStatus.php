<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Enums;

enum ActionRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case DryRun = 'dry_run';
}
