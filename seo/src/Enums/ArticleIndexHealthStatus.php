<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

/**
 * Current Index Health projection (includes derived Dropped).
 */
enum ArticleIndexHealthStatus: string
{
    case Indexed = 'indexed';
    case NotIndexed = 'not_indexed';
    case Dropped = 'dropped';
    case Unknown = 'unknown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public function needsAttention(): bool
    {
        return match ($this) {
            self::Dropped, self::NotIndexed, self::Unknown => true,
            self::Indexed => false,
        };
    }
}
