<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Enums;

enum ActionSideEffect: string
{
    case Pure = 'pure';
    case Read = 'read';
    case InternalWrite = 'internal_write';
    case ExternalWrite = 'external_write';
}
