<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsRequest;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data\GscSearchAnalyticsResult;

/**
 * GSC intelligence provider boundary — tách khỏi legacy GSC OAuth/sync jobs.
 */
interface GscIntelligenceProviderInterface
{
    public function key(): string;

    public function supports(GscSearchAnalyticsRequest $request): bool;

    public function collectAnalytics(GscSearchAnalyticsRequest $request): GscSearchAnalyticsResult;

    /**
     * @return array{healthy: bool, code: ?string, message: ?string, metadata: array<string, mixed>}
     */
    public function health(): array;
}
