<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts\MonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;
use Omnichannel\Addons\Seo\Services\SiteContext\SiteContextGateway;

/**
 * Site monthly MCP source — adapts SiteContextGateway → snapshot (owns MCP conversion).
 */
final class SiteMonthlyMcpSource implements MonthlyMcpSource
{
    public function __construct(
        private readonly SiteContextGateway $gateway,
    ) {}

    public function key(): string
    {
        return McpSourceKey::Site->value;
    }

    public function schemaVersion(): string
    {
        return 'v1';
    }

    public function build(Site $site, SeoMcpPeriod $period): MonthlyMcpSourcePayload
    {
        $ctx = $this->gateway->forSite($site, $period->periodKey());

        return MonthlyMcpSourcePayload::make(
            McpSourceKey::Site,
            $ctx->metrics,
            $ctx->summary,
            $ctx->context,
            $ctx->sourceUpdatedAt,
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->gateway->sourceUpdatedAt($site);
    }
}
