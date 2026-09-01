<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use BackedEnum;

/** Per-item length preset. Absence of a value means "inherit the prompt settings target". */
enum ItemContentLengthMode: string
{
    case Short = 'short';

    case Standard = 'standard';

    case Long = 'long';

    case Custom = 'custom';

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
