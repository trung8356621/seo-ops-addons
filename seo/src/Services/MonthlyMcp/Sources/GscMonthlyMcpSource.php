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
 * GSC monthly MCP source — adapts GscContextGateway → snapshot (owns MCP conversion).
 *
 * Compatibility: preserves ai_lines from builder context for report/UI consumers.
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
        $ctx = $this->gateway->forSite((int) $site->id, $period->periodKey());

        return MonthlyMcpSourcePayload::make(
            McpSourceKey::Gsc,
            $ctx->metrics,
            $ctx->summary,
            $ctx->context,
            $ctx->sourceUpdatedAt,
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->gateway->sourceUpdatedAt((int) $site->id);
    }
}
