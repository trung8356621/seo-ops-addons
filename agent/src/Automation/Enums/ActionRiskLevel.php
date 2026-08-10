<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Enums;

enum ActionRiskLevel: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';
}
