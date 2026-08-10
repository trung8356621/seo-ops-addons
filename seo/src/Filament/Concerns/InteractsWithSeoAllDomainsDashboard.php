<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Concerns;

use Omnichannel\Addons\Seo\Support\SeoAccessControl;

trait InteractsWithSeoAllDomainsDashboard
{
    protected function isAllDomainsDashboard(): bool
    {
        return ! SeoAccessControl::hasGlobalSiteScope();
    }
}
