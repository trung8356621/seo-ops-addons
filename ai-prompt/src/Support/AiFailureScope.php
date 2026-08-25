<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiFailureScope: string
{
    case Connection = 'connection';
    case ConnectionPaid = 'connection_paid';
    case Model = 'model';
    case System = 'system';
}
