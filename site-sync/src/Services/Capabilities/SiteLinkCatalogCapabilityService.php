<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Capabilities;

use Omnichannel\Addons\SiteSync\Contracts\SiteLinkCatalogCapability;
use Omnichannel\Addons\SiteSync\Services\Reconciliation\SiteLinkCatalogReconciler;

final readonly class SiteLinkCatalogCapabilityService implements SiteLinkCatalogCapability
{
    public function __construct(
        private SiteLinkCatalogReconciler $catalog,
    ) {}

    public function effectiveLinks(int $siteId): array
    {
        if ($siteId <= 0) {
            return [];
        }

        return $this->catalog->effectiveLinks($siteId);
    }
}
