<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources;

use App\Models\Site;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\KeywordLandscapeReadModel;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts\MonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;

/**
 * Keyword monthly MCP source — site-level Keyword Landscape via Topic Core SSOT.
 */
final class KeywordMonthlyMcpSource implements MonthlyMcpSource
{
    public function __construct(
        private readonly KeywordLandscapeReadModel $landscape,
    ) {}

    public function key(): string
    {
        return McpSourceKey::Keywords->value;
    }

    public function schemaVersion(): string
    {
        return 'v2';
    }

    public function build(Site $site, SeoMcpPeriod $period): MonthlyMcpSourcePayload
    {
        unset($period);
        $parts = $this->landscape->forSite((int) $site->id, true)->toMcpPayloadParts();

        return MonthlyMcpSourcePayload::make(
            McpSourceKey::Keywords,
            $parts['metrics'],
            $parts['summary'],
            $parts['context'],
            $this->sourceUpdatedAt($site),
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->landscape->sourceUpdatedAt((int) $site->id);
    }
}
