<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Contracts;

/**
 * Shared site_sync.v1 contract constants — must match wp-seo-ai producer.
 */
final class SiteSyncSchema
{
    public const VERSION = 'site_sync.v1';

    public const MIN_BRIDGE_VERSION = '1.0.64';

    public const MODE_SNAPSHOT = 'snapshot';

    public const MODE_DELTA = 'delta';

    /** Full re-fetch/reconcile entire site; hashes only for unchanged stats, never skip fetch. */
    public const MODE_FORCE_FULL = 'force_full';

    public const SOURCE_WORDPRESS = 'wordpress';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PROVIDER = 'provider';

    public const SOURCE_WORKSPACE = 'workspace';

    public const SOURCE_LEGACY_UNKNOWN = 'legacy_unknown';

    public const SOURCE_LEGACY_WORKSPACE = 'legacy_workspace';

    public const META_BOOTSTRAPPED_AT = 'seo_site_sync_v2_bootstrapped_at';

    public const META_HANDSHAKE = 'seo_site_sync_v2_handshake';

    public const META_BACKFILL_REPORT = 'seo_site_sync_v2_backfill_report';

    public const META_PROFILE_SUGGESTIONS = 'seo_site_profile_suggestions';

    public const KEYWORD_PRIORITY = [
        self::SOURCE_MANUAL,
        self::SOURCE_PROVIDER,
        self::SOURCE_WORKSPACE,
    ];

    /** @var list<string> */
    public const CAPABILITY_KEYS = [
        'seo_metadata',
        'focus_keyword',
        'seo_score',
        'internal_link',
        'redirect',
        'http_404',
        'schema',
        'contact_discovery',
        'taxonomy',
        'permalink',
    ];

    /** @var list<string> */
    public const ORCHESTRATOR_STEPS = [
        'detect_capability',
        'request_snapshot_delta',
        'sync_site_profile',
        'sync_url_catalog',
        'sync_provider_keywords',
        'missing_capability_fallback',
        'validate_changed_links',
        'score_missing_articles',
        'finalize',
    ];

    public static function isSupportedSchema(?string $schema): bool
    {
        return $schema === self::VERSION;
    }
}
