<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Enums;

enum MatchResearchOrigin: string
{
    case System = 'system';
    case Industry = 'industry';
    case Custom = 'custom';
}
