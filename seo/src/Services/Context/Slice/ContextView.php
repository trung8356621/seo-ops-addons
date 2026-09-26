<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice;

enum ContextView: string
{
    case Summary = 'summary';
    case Standard = 'standard';
    case Detail = 'detail';

    /**
     * Absent / empty input → definition default. Explicit invalid → null (caller rejects).
     */
    public static function tryParse(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return self::tryFrom(trim($value));
    }
}
