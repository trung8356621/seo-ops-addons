<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Profile\Contracts;

use App\Models\Site;

interface WordPressSiteProfileSource
{
    /**
     * @return array{success: bool, message: string, site_name?: string, short_description?: string}
     */
    public function read(Site $site): array;
}
