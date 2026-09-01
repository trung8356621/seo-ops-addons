<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support\ContentProject;

use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

/**
 * Default Content length (words) by post type for planning items.
 * Custom values are never overwritten when the user (or DB) already set them.
 */
final class ContentProjectItemContentLengthDefaults
{
    public const POST_WORDS = 2000;

    public const PRODUCT_WORDS = 1000;

    public const MIN_WORDS = 100;

    public const MAX_WORDS = 20000;

    public static function forPostType(mixed $postType): int
    {
        return SeoProjectTask::normalizePostType($postType) === SeoProjectTask::POST_TYPE_PRODUCT
            ? self::PRODUCT_WORDS
            : self::POST_WORDS;
    }

    public static function isDefaultValue(?int $words, mixed $postType): bool
    {
        if ($words === null || $words <= 0) {
            return true;
        }

        return $words === self::forPostType($postType);
    }

    public static function clamp(int $words): int
    {
        return max(self::MIN_WORDS, min(self::MAX_WORDS, $words));
    }
}
