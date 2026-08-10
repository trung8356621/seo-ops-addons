<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\Gsc;

enum GscPropertyStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';
    case Archived = 'archived';
}
