<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Canonical featured-image projection statuses for Article List.
 */
final class ArticleFeaturedImageStatus
{
    public const AVAILABLE = 'available';

    public const ABSENT = 'absent';

    public const UNKNOWN = 'unknown';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::AVAILABLE, self::ABSENT, self::UNKNOWN];
    }
}
