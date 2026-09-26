<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\MonthlyMcp;

use Omnichannel\Addons\Seo\Services\Context\ContextDataQuality;

/**
 * Compatibility facade — delegates to ContextDataQuality.
 */
final class McpDataQualityGuard
{
    public function __construct(
        private readonly ContextDataQuality $quality = new ContextDataQuality,
    ) {}

    /**
     * @param  array<string, mixed>  $distribution
     * @param  array<string, mixed>  $linking
     * @return list<string>
     */
    public function siteWarnings(int $articleTotal, array $distribution, array $linking): array
    {
        return $this->quality->siteWarnings($articleTotal, $distribution, $linking);
    }
}
