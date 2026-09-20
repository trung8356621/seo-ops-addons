<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Support;

/**
 * Contextual ALT ownership for Image Assistant / WP media sync.
 *
 * Role is usage-context, never a permanent property of an attachment_id.
 */
final class MediaAltOwnership
{
    public const ROLE_ARTICLE_CONTENT = 'article_content';

    public const ROLE_FEATURED_IMAGE = 'featured_image';

    public const ROLE_PRODUCT_GALLERY = 'product_gallery';

    public const OWNER_ARTICLE_HTML = 'article_html';

    public const OWNER_WORDPRESS = 'wordpress';

    public const WP_ALT_SYNC_ALLOWED = 'allowed';

    public const WP_ALT_SYNC_FORBIDDEN = 'forbidden';

    /**
     * Roles that may mutate WordPress attachment ALT (_wp_attachment_image_alt).
     *
     * @var list<string>
     */
    public const WP_ALT_MUTATION_ROLES = [
        self::ROLE_FEATURED_IMAGE,
        self::ROLE_PRODUCT_GALLERY,
    ];

    public static function isWpAltMutationRole(string $mediaRole): bool
    {
        return in_array($mediaRole, self::WP_ALT_MUTATION_ROLES, true);
    }

    public static function altOwnerForRole(string $mediaRole): string
    {
        return match ($mediaRole) {
            self::ROLE_FEATURED_IMAGE, self::ROLE_PRODUCT_GALLERY => self::OWNER_WORDPRESS,
            default => self::OWNER_ARTICLE_HTML,
        };
    }

    public static function wpAltSyncPolicyForRole(string $mediaRole): string
    {
        return self::isWpAltMutationRole($mediaRole)
            ? self::WP_ALT_SYNC_ALLOWED
            : self::WP_ALT_SYNC_FORBIDDEN;
    }

    /**
     * Resolve contextual role from inventory-style flags.
     *
     * Dual-role rows (content + featured/gallery) keep article_content as the
     * HTML owner; WP mutation still requires an explicit featured/gallery role
     * via {@see resolveWpMutationRoleFromFlags()}.
     */
    public static function resolvePrimaryRoleFromFlags(
        bool $content = false,
        bool $featured = false,
        bool $gallery = false,
    ): string {
        if ($content) {
            return self::ROLE_ARTICLE_CONTENT;
        }

        if ($featured) {
            return self::ROLE_FEATURED_IMAGE;
        }

        if ($gallery) {
            return self::ROLE_PRODUCT_GALLERY;
        }

        return self::ROLE_ARTICLE_CONTENT;
    }

    /**
     * Role allowed for WordPress ALT mutation, or null when forbidden.
     */
    public static function resolveWpMutationRoleFromFlags(
        bool $content = false,
        bool $featured = false,
        bool $gallery = false,
    ): ?string {
        // Content context alone must never mutate WP ALT — even with attachment_id.
        // Featured/gallery flags on a dual-role row may still stage WP ALT.
        if ($featured) {
            return self::ROLE_FEATURED_IMAGE;
        }

        if ($gallery) {
            return self::ROLE_PRODUCT_GALLERY;
        }

        unset($content);

        return null;
    }

    /**
     * Normalize an incoming media_role string; empty → null.
     */
    public static function normalizeRole(mixed $value): ?string
    {
        $role = strtolower(trim((string) $value));
        if ($role === '') {
            return null;
        }

        return match ($role) {
            self::ROLE_ARTICLE_CONTENT,
            'content',
            'article',
            'body' => self::ROLE_ARTICLE_CONTENT,
            self::ROLE_FEATURED_IMAGE,
            'featured',
            'thumbnail' => self::ROLE_FEATURED_IMAGE,
            self::ROLE_PRODUCT_GALLERY,
            'gallery' => self::ROLE_PRODUCT_GALLERY,
            default => $role,
        };
    }
}
