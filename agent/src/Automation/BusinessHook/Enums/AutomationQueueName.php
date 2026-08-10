<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Enums;

enum AutomationQueueName: string
{
    case Automation = 'automation';
    case External = 'automation-external';
    case Critical = 'automation-critical';
    /** CP scheduled automation policies — not WP publish path. */
    case Policy = 'automation-policy';
}
