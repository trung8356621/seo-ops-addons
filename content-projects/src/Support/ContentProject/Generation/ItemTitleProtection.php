<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation;

use BackedEnum;

/** Provenance of the item title — decides whether generation may rewrite it. */
enum ItemTitleProtection: string
{
    case User = 'user';

    case Generated = 'generated';

    case Reviewed = 'reviewed';

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

    /** User and reviewed titles are human-owned; generated titles are only kept stable across reruns. */
    public function isHumanOwned(): bool
    {
        return $this === self::User || $this === self::Reviewed;
    }
}
