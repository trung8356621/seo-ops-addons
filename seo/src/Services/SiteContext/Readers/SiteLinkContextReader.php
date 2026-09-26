<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SiteContext\Readers;

use App\Models\Site;
use Omnichannel\Addons\Seo\Services\SiteContext\Aggregators\SiteInternalLinkingAggregator;
use Omnichannel\Addons\SiteSync\Services\Support\SiteSyncSiteMeta;

/**
 * Link analysis snapshot + internal linking for Site Intelligence Context.
 */
final class SiteLinkContextReader
{
    public function __construct(
        private readonly SiteInternalLinkingAggregator $linkingAggregator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function analysisSnapshot(Site $site): array
    {
        $decoded = SiteSyncSiteMeta::getJson($site, 'seo_link_analysis_snapshot');

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function internalLinking(Site $site): array
    {
        return $this->linkingAggregator->aggregate($site);
    }
}
