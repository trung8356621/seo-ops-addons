<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use App\Models\Site;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway;

/**
 * Compatibility adapter — prefer SiteContextGateway for new callers.
 *
 * @deprecated Use SiteContextGateway (Site Intelligence Context). Kept for transitional call sites.
 */
final class SiteMcpContextBuilder
{
    public function __construct(
        private readonly SiteContextGateway $gateway,
    ) {}

    public function build(Site $site, string $periodKey): MonthlyMcpSourcePayload
    {
        return $this->gateway->forSite($site, $periodKey)->toMonthlyPayload();
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->gateway->sourceUpdatedAt($site);
    }
}
