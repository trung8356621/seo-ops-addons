<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\GscContext\GscContextGateway;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts\MonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;

/**
 * GSC monthly MCP source — adapts GscContextGateway → snapshot (no parallel build path).
 */
final class GscMonthlyMcpSource implements MonthlyMcpSource
{
    public function __construct(
        private readonly GscContextGateway $gateway,
    ) {}

    public function key(): string
    {
        return McpSourceKey::Gsc->value;
    }

    public function schemaVersion(): string
    {
        return 'v1';
    }

    public function build(Site $site, SeoMcpPeriod $period): MonthlyMcpSourcePayload
    {
        $parts = $this->gateway->forSite((int) $site->id, $period->periodKey())->toMonthlyParts();

        return MonthlyMcpSourcePayload::make(
            McpSourceKey::Gsc,
            $parts['metrics'],
            $parts['summary'],
            $parts['context'],
            $parts['source_updated_at'],
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->gateway->sourceUpdatedAt((int) $site->id);
    }
}
