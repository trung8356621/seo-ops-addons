<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Enums;

enum KeywordGroupMetricType: string
{
    case Allintitle = 'allintitle';
    case SearchVolume = 'search_volume';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
