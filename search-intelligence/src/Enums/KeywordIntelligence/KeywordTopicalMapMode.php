<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence;

enum KeywordTopicalMapMode: string
{
    case Conservative = 'conservative';
    case Balanced = 'balanced';
    case Expansive = 'expansive';
}
