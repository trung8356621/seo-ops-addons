<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts\MonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;

/**
 * Keyword monthly MCP source — cluster context builder retired; emits empty payload.
 */
final class KeywordMonthlyMcpSource implements MonthlyMcpSource
{
    public function key(): string
    {
        return McpSourceKey::Keywords->value;
    }

    public function schemaVersion(): string
    {
        return 'v1';
    }

    public function build(Site $site, SeoMcpPeriod $period): MonthlyMcpSourcePayload
    {
        unset($site, $period);

        return MonthlyMcpSourcePayload::make(
            McpSourceKey::Keywords,
            [],
            [],
            [],
            null,
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        unset($site);

        return null;
    }
}
