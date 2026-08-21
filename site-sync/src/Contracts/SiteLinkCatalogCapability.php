<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Contracts;

interface SiteLinkCatalogCapability
{
    public const ID = 'site-sync.link-catalog';

    /**
     * @return list<array<string, mixed>>
     */
    public function effectiveLinks(int $siteId): array;
}
