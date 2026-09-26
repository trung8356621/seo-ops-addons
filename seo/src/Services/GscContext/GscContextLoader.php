<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\GscContext;

use Omnichannel\Addons\Seo\Services\GscContext\Dto\GscContext;

/**
 * Load contract for request-scoped GSC memoization.
 */
interface GscContextLoader
{
    public function forSite(int $siteId, string $periodKey): GscContext;

    public function sourceUpdatedAt(int $siteId): ?string;
}
