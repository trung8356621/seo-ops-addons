<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

/**
 * Raw manual/automated check result (never "dropped" — that is derived).
 */
enum ArticleIndexCheckStatus: string
{
    case Indexed = 'indexed';
    case NotIndexed = 'not_indexed';
    case Unknown = 'unknown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
