<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpProviderResult;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data\SerpQueryRequest;

/**
 * SERP intelligence provider boundary — tách khỏi SeoProviderInterface (rank/audit).
 */
interface SerpIntelligenceProviderInterface
{
    public function key(): string;

    public function supports(SerpQueryRequest $request): bool;

    public function collect(SerpQueryRequest $request): SerpProviderResult;

    /**
     * @return array{healthy: bool, code: ?string, message: ?string, metadata: array<string, mixed>}
     */
    public function health(): array;
}
