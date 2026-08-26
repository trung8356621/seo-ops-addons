<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Presentation;

use Omnichannel\Addons\Seo\Services\EffectiveDomainLinkResolver;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkCatalog;
use Omnichannel\Addons\SiteSync\Models\SeoSiteLinkExclusion;
use App\Models\Site;

/**
 * Domain Settings link catalog summary — never load full WP catalog into form.
 */
final class SiteLinkCatalogSummaryPresenter
{
    /**
     * @return array{
     *     wordpress_active: int,
     *     manual: int,
     *     product_categories: int,
     *     effective: int,
     *     exclusions: int,
     *     inactive: int,
     *     label: string
     * }
     */
    public function forSite(Site $site): array
    {
        $effectiveSummary = app(EffectiveDomainLinkResolver::class)->catalogSummary($site);

        $siteId = (int) $site->id;
        $excluded = SeoSiteLinkExclusion::query()->where('site_id', $siteId)->count();
        $inactive = SeoSiteLinkCatalog::query()
            ->forSite($siteId)
            ->whereNotNull('inactive_at')
            ->count();

        return [
            'wordpress_active' => (int) $effectiveSummary['wordpress_active'],
            'manual' => (int) $effectiveSummary['manual'],
            'product_categories' => (int) $effectiveSummary['product_categories'],
            'effective' => (int) $effectiveSummary['effective'],
            'exclusions' => $excluded,
            'inactive' => $inactive,
            'label' => (string) $effectiveSummary['label'],
        ];
    }
}
