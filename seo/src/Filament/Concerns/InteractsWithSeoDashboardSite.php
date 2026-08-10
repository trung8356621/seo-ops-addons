<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;

trait InteractsWithSeoDashboardSite
{
    protected function resolveDashboardSiteId(): ?int
    {
        $siteId = SeoAccessControl::globalSiteId();

        return $siteId !== null && $siteId > 0 ? $siteId : null;
    }

    protected function resolveDashboardSite(): ?Site
    {
        $siteId = $this->resolveDashboardSiteId();
        if ($siteId === null) {
            return null;
        }

        return Site::query()->find($siteId);
    }

    protected function hasDashboardSiteScope(): bool
    {
        return $this->resolveDashboardSiteId() !== null;
    }
}
