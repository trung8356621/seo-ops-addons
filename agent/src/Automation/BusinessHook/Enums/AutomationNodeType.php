<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationNodeType: string
{
    case Trigger = 'trigger';
    case Action = 'action';
    case Condition = 'condition';
    case Delay = 'delay';
    case DispatchEvent = 'dispatch_event';
    case End = 'end';
}
