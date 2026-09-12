<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiFailureScope: string
{
    case Connection = 'connection';
    case ConnectionPaid = 'connection_paid';
    case ConnectionFree = 'connection_free';
    case Model = 'model';
    case System = 'system';
}
