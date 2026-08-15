<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp\Contracts;

use App\Models\Site;
use Omnichannel\Addons\Seo\Models\SeoMcpPeriod;
use Omnichannel\Addons\Seo\Services\MonthlyMcp\Dto\MonthlyMcpSourcePayload;

interface MonthlyMcpSource
{
    public function key(): string;

    public function schemaVersion(): string;

    public function build(Site $site, SeoMcpPeriod $period): MonthlyMcpSourcePayload;

    public function sourceUpdatedAt(Site $site): ?string;
}
