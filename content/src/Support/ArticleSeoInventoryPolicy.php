<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Which Article rows count toward SEO Data health / orphan SEO inventory.
 *
 * System / structural WP types and taxonomy terms are technical inventory only.
 */
final class ArticleSeoInventoryPolicy
{
    /**
     * Non-SEO WordPress post types (site editor, media, internal WP objects).
     *
     * @var list<string>
     */
    public const SYSTEM_WP_POST_TYPES = [
        'blocks',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_navigation',
        'wp_global_styles',
        'wp_font_family',
        'wp_font_face',
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_dedicated_network',
    ];

    /** @var list<string> */
    public const PUBLIC_INDEXABLE_STATUSES = [
        'publish',
        'private',
        'future',
        'scheduled',
    ];

    /** @var list<string> */
    public const DRAFTISH_STATUSES = [
        'draft',
        'pending',
        'auto-draft',
    ];

    public static function isSystemWpPostType(?string $wpPostType): bool
    {
        $type = strtolower(trim((string) $wpPostType));
        if ($type === '') {
            return false;
        }

        if (in_array($type, self::SYSTEM_WP_POST_TYPES, true)) {
            return true;
        }

        // Future WP internal types: wp_* except known public CPTs.
        if (str_starts_with($type, 'wp_')) {
            return true;
        }

        return false;
    }

    public static function isTermMeta(?string $wpIsTerm): bool
    {
        return in_array(strtolower(trim((string) $wpIsTerm)), ['1', 'true', 'yes'], true);
    }

    /**
     * SEO health / orphan denominator candidate (not yet filtered by field applicability).
     */
    public static function isSeoInventoryCandidate(
        ?string $wpPostType,
        ?string $wpIsTerm,
    ): bool {
        if (self::isTermMeta($wpIsTerm)) {
            return false;
        }

        if (self::isSystemWpPostType($wpPostType)) {
            return false;
        }

        return true;
    }

    public static function isWpBacked(?int $wpPostId): bool
    {
        return $wpPostId !== null && $wpPostId > 0;
    }

    public static function isPublicIndexable(?string $status): bool
    {
        $s = strtolower(trim((string) $status));

        return in_array($s, self::PUBLIC_INDEXABLE_STATUSES, true);
    }

    public static function isDraftish(?string $status): bool
    {
        $s = strtolower(trim((string) $status));

        return in_array($s, self::DRAFTISH_STATUSES, true);
    }
}
