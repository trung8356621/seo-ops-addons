<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

/**
 * WordPress-observed post status. Never mix with Laravel publish-queue workflow.
 */
final class ObservedWordPressPostStatus
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const FUTURE = 'future';

    public const PUBLISH = 'publish';

    public const TRASH = 'trash';

    public const MISSING = 'missing';

    public const PRIVATE = 'private';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::FUTURE,
            self::PUBLISH,
            self::TRASH,
            self::MISSING,
            self::PRIVATE,
        ];
    }

    public static function normalize(?string $status): string
    {
        $value = strtolower(trim((string) $status));
        if ($value === 'private') {
            return self::PRIVATE;
        }

        return in_array($value, self::values(), true) ? $value : self::MISSING;
    }

    public static function isLiveOnSite(string $status): bool
    {
        return in_array($status, [self::PUBLISH, self::FUTURE], true);
    }
}
