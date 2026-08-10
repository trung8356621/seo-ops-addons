<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationWorkflowMode: string
{
    case Linear = 'linear';
    case Graph = 'graph';
}
