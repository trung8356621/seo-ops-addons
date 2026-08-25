<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiFailureRuntimeAction: string
{
    case Continue = 'continue';
    case Stop = 'stop';
}
