<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use BackedEnum;

/**
 * How hard the per-item model override binds:
 * preferred = try first then fall back, required = do not fall back.
 */
enum ItemModelOverrideMode: string
{
    case Preferred = 'preferred';

    case Required = 'required';

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
