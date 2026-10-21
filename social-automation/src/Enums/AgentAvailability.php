<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum AgentAvailability: string
{
    case Available = 'available';
    case Busy = 'busy';
    case Paused = 'paused';
    case Error = 'error';
}
