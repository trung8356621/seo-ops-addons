<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum AccountIdentitySource: string
{
    case Declared = 'declared';
    case PlatformId = 'platform_id';
    case RunnerHint = 'runner_hint';
    case Unknown = 'unknown';
}
