<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice;

enum ContextView: string
{
    case Summary = 'summary';
    case Standard = 'standard';
    case Detail = 'detail';

    public static function tryFromInput(mixed $value, self $default = self::Summary): self
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        return self::tryFrom($value) ?? $default;
    }
}
