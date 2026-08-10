<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Data;

/**
 * Canonical GSC Search Analytics scope — dedupe, provider collect, sync chunks.
 */
final class GscSearchAnalyticsRequest
{
    /**
     * @param  list<string>  $dimensions
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly ?string $tenantRef,
        public readonly ?string $siteRef,
        public readonly string $propertyRef,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly array $dimensions = ['date', 'query', 'page', 'country', 'device', 'search_appearance'],
        public readonly string $providerKey = 'manual_import',
        public readonly array $options = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scopeKey(): array
    {
        return [
            'tenant' => $this->tenantRef,
            'site' => $this->siteRef,
            'property' => $this->propertyRef,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'dimensions' => $this->dimensions,
            'provider' => $this->providerKey,
        ];
    }
}
