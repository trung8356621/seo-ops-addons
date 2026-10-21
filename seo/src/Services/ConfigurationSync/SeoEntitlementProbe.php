<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

interface SeoEntitlementProbe
{
    public function seoServiceEnabled(): bool;
}
