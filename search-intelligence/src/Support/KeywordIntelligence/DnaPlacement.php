<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence;

/**
 * Shared DNA positional guidance relative to base topic/keyword.
 * Keywords SSOT + Project Planner snapshots must use these values only.
 */
final class DnaPlacement
{
    public const BEFORE = 'before';

    public const AFTER = 'after';

    public const DEFAULT = self::AFTER;

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::BEFORE, self::AFTER];
    }

    public static function normalize(mixed $raw): string
    {
        $value = strtolower(trim((string) ($raw ?? '')));
        if ($value === self::BEFORE || $value === self::AFTER) {
            return $value;
        }

        return self::DEFAULT;
    }

    public static function isValid(mixed $raw): bool
    {
        $value = strtolower(trim((string) ($raw ?? '')));

        return $value === self::BEFORE || $value === self::AFTER;
    }
}
