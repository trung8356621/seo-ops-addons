<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Enums;

enum SessionReadyStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case NeedsAttention = 'needs_attention';
    case Invalid = 'invalid';
}
