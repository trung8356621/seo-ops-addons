<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Enums;

enum ArticleImproveScope: string
{
    case Article = 'article';
    case Section = 'section';
    case Selection = 'selection';

    public static function tryFromMixed(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        $raw = trim((string) $value);

        return $raw === '' ? null : self::tryFrom($raw);
    }
}
