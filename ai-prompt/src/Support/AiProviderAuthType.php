<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Support;

enum AiProviderAuthType: string
{
    case Bearer = 'bearer';
    case Header = 'header';
    case Query = 'query';
    case None = 'none';
}
