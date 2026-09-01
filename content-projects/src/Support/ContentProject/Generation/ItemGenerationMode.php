<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use BackedEnum;

/** Per-item routing intent for the generation engine. */
enum ItemGenerationMode: string
{
    case FastEconomy = 'fast_economy';

    case BestQuality = 'best_quality';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : self::tryFrom($normalized);
    }
}
