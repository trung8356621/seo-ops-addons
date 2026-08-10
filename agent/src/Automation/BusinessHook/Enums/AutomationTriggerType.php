<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationTriggerType: string
{
    case Event = 'event';
    case Schedule = 'schedule';
    case Manual = 'manual';
}
