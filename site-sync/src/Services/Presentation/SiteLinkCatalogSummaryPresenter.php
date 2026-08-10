<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Presentation;

use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkExclusion;
use Omnichannel\Addons\SiteSync\Models\SeoSiteManualLink;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;
use App\Models\Site;

/**
 * Domain Settings link catalog summary — never load full WP catalog into form.
 */
final class SiteLinkCatalogSummaryPresenter
{
    /**
     * @return array{wordpress_active: int, manual: int, exclusions: int, inactive: int, label: string}
     */
    public function forSite(Site $site): array
    {
        $siteId = (int) $site->id;
        $wp = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->where('source', SiteSyncSchema::SOURCE_WORDPRESS)
            ->whereNull('inactive_at')
            ->count();
        $manual = SeoSiteManualLink::query()->where('site_id', $siteId)->count();
        $excluded = SeoSiteLinkExclusion::query()->where('site_id', $siteId)->count();
        $inactive = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->whereNotNull('inactive_at')
            ->count();

        return [
            'wordpress_active' => $wp,
            'manual' => $manual,
            'exclusions' => $excluded,
            'inactive' => $inactive,
            'label' => sprintf(
                'WordPress active: %d · Manual: %d · Exclusions: %d · Inactive/deleted: %d. Effective = WP + Manual − Exclusions. Đồng bộ lại qua nút «Đồng bộ & kiểm tra website».',
                $wp,
                $manual,
                $excluded,
                $inactive,
            ),
        ];
    }
}
