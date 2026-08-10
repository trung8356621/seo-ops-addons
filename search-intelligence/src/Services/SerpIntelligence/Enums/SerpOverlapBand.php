<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Enums;

enum SerpOverlapBand: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
    case VeryHigh = 'very_high';
}
