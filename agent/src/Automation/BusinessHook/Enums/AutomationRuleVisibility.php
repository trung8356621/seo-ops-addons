<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationRuleVisibility: string
{
    case User = 'user';
    case Admin = 'admin';
    case Hidden = 'hidden';
}
