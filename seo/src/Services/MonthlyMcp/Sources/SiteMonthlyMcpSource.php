<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Sources;

use App\Models\Site;
use Omnichannel\Addons\Seo\Enums\McpSourceKey;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts\MonthlyMcpSource;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\SiteMcpContextBuilder;

final class SiteMonthlyMcpSource implements MonthlyMcpSource
{
    public function __construct(
        private readonly SiteMcpContextBuilder $builder,
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
        return $this->builder->build($site, $period->periodKey());
    }

    public function sourceUpdatedAt(Site $site): ?string
    {
        return $this->builder->sourceUpdatedAt($site);
    }
}
