<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiCapabilitySource: string
{
    case BuiltIn = 'built_in';
    case Detected = 'detected';
    case Manual = 'manual';
}
