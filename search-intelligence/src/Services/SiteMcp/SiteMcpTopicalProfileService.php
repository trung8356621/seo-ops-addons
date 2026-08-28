<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SiteMcp;

use App\Models\Site;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Snapshot + live rebuild of Site MCP topical profile from Keyword Clusters.
 *
 * Snapshot is a compression cache for prompts — rebuild always re-reads current clusters.
 */
final class SiteMcpTopicalProfileService
{
    public const META_KEY = 'site_mcp_topical_profile';

    public function __construct(
        private readonly SiteMcpClusterTopicalProfileBuilder $builder,
    ) {}

    /**
     * @return array{
     *     source: string,
     *     built_at: string,
     *     total_clustered_keywords: int,
     *     topics: list<array<string, mixed>>
     * }
     */
    public function get(Site|int $site, bool $forceRebuild = false): array
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);
        $siteId = (int) $site->id;

        if (! $forceRebuild && ! SiteMcpTopicalProfileStaleState::isStale($siteId)) {
            $cached = SiteSyncSiteMeta::getJson($site, self::META_KEY);
            if (is_array($cached) && is_array($cached['topics'] ?? null)) {
                return $cached;
            }
        }

        return $this->rebuild($site);
    }

    /**
     * @return array{
     *     source: string,
     *     built_at: string,
     *     total_clustered_keywords: int,
     *     topics: list<array<string, mixed>>
     * }
     */
    public function rebuild(Site|int $site): array
    {
        $site = $site instanceof Site ? $site : Site::query()->findOrFail((int) $site);
        $siteId = (int) $site->id;

        $profile = $this->builder->build($siteId);
        SiteSyncSiteMeta::putJson($site, self::META_KEY, $profile);
        SiteMcpTopicalProfileStaleState::clear($siteId);

        return $profile;
    }

    public function markStale(int $siteId, string $reason = 'clusters_changed'): void
    {
        SiteMcpTopicalProfileStaleState::mark($siteId, $reason);
    }
}
