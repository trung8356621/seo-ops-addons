<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Support;

/**
 * Provenance labels for featured-image projection (write-through / backfill).
 */
final class ArticleFeaturedImageSource
{
    /** Editor / applyFeaturedLocal / media snapshot setFeatured */
    public const EDITOR_LOCAL = 'editor_local';

    /** Product album slot 0 */
    public const PRODUCT_ALBUM = 'product_album';

    /** WP sync / site sync stored meta URL (already in DB — no live HTTP) */
    public const WP_SNAPSHOT = 'wp_snapshot';

    /** SeoMedia resolved via featured attachment id (local PK) */
    public const SEO_MEDIA = 'seo_media';

    /** Explicit clear */
    public const CLEARED = 'cleared';

    /** Multiple sources disagreed; precedence applied */
    public const CONFLICT_RESOLVED = 'conflict_resolved';
}
