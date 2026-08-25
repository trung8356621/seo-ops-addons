<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources;

use App\Models\Site;
use Omnichannel\Addons\SearchIntelligence\Support\GscIntelligence\GscMcpContextBuilder;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts\MonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;

final class GscMonthlyMcpSource implements MonthlyMcpSource
{
    public function __construct(
        private readonly GscMcpContextBuilder $builder,
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
        $built = $this->builder->build((int) $site->id, $period->periodKey());

        return MonthlyMcpSourcePayload::make(
            McpSourceKey::Gsc,
            $built['metrics'],
            $built['summary'],
            $built['context'],
            $built['source_updated_at'],
        );
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->builder->sourceUpdatedAt((int) $site->id);
    }
}
