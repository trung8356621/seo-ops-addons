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

    /**
     * Latest YYYY-MM period with persisted Search Performance rows for the site,
     * constrained to periods on or before $onOrBeforePeriod.
     *
     * Null when no active GSC property exists or no rows exist in range.
     * Never inferred from property existence alone.
     */
    public function latestSyncedPeriodOnOrBefore(int $siteId, string $onOrBeforePeriod): ?string;
}
