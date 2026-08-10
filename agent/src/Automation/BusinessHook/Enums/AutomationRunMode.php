<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationRunMode: string
{
    case Queued = 'queued';
    case Sync = 'sync';
}
