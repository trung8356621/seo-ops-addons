<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Contracts;

/**
 * Site Sync V3 contract constants — must match wp-seo-ai producer when shipped.
 *
 * Content lifecycle: V3 MUST NOT write articles.body or wp_post_content* meta.
 * Pagination uses keyset cursors (never offset).
 */
final class SiteSyncV3Schema
{
    public const VERSION = 'site_sync.v3';

    public const PROTOCOL = 3;

    public const CAPABILITY = 'site_sync_v3';

    /** Site meta: ISO8601 when first successful V3 force-full+verify completed. */
    public const META_BASELINE_COMPLETED_AT = 'seo_site_sync_v3_baseline_completed_at';

    /** Site meta: sync generation / run id of that baseline. */
    public const META_BASELINE_GENERATION = 'seo_site_sync_v3_baseline_generation';

    public const MODE_FORCE_FULL = 'force_full';

    public const MODE_DELTA = 'delta';

    public const RESOURCE_CONTENT = 'content';

    public const RESOURCE_TERMS = 'terms';

    public const PHASE_DISCOVER = 'discover';

    public const PHASE_IMPORT = 'import';

    public const PHASE_RECONCILE_STALE = 'reconcile_stale';

    public const PHASE_CATCH_UP = 'catch_up';

    public const PHASE_VERIFY = 'verify';

    public const PHASE_COMPLETE = 'complete';

    public const PHASE_NEEDS_ATTENTION = 'needs_attention';

    /** @var list<string> */
    public const PHASES = [
        self::PHASE_DISCOVER,
        self::PHASE_IMPORT,
        self::PHASE_RECONCILE_STALE,
        self::PHASE_CATCH_UP,
        self::PHASE_VERIFY,
        self::PHASE_COMPLETE,
        self::PHASE_NEEDS_ATTENTION,
    ];

    /** Max records requested per /sync/v3/records call. */
    public const RECORDS_PER_JOB = 50;

    /** Body / HTML keys stripped before any V3 DB write. */
    public const FORBIDDEN_BODY_KEYS = [
        'body',
        'post_content',
        'wp_post_content',
        'content',
        'rendered_content',
        'html',
    ];
}
