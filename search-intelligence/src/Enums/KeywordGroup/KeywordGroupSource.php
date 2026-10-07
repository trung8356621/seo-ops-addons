<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup;

final class KeywordGroupSource
{
    public const SEMANTIC = 'semantic';

    public const MANUAL = 'manual';

    public static function isManual(?string $source): bool
    {
        return (string) $source === self::MANUAL;
    }
}
