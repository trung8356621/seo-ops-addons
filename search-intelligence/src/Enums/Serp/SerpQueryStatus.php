<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Serp;

enum SerpQueryStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
