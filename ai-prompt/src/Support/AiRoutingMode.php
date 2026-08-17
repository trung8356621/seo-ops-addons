<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiRoutingMode: string
{
    case Auto = 'auto';
    case Override = 'override';
}
