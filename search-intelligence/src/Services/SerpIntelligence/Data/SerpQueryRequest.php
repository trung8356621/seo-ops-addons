<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Data;

/**
 * Canonical SERP query scope — dùng cho dedupe, cache key, provider collect.
 */
final class SerpQueryRequest
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public readonly ?string $tenantRef,
        public readonly ?string $siteRef,
        public readonly string $query,
        public readonly string $displayQuery,
        public readonly string $normalizedQuery,
        public readonly string $language,
        public readonly string $country,
        public readonly ?string $location,
        public readonly string $device,
        public readonly string $searchEngine,
        public readonly string $providerKey,
        public readonly array $options = [],
    ) {}

    /**
     * @return array{
     *   tenant: ?string,
     *   site: ?string,
     *   normalized_query: string,
     *   display_query: string,
     *   language: string,
     *   country: string,
     *   location: ?string,
     *   device: string,
     *   search_engine: string,
     *   provider: string
     * }
     */
    public function scopeKey(): array
    {
        return [
            'tenant' => $this->tenantRef,
            'site' => $this->siteRef,
            'normalized_query' => $this->normalizedQuery,
            'display_query' => $this->displayQuery,
            'language' => $this->language,
            'country' => $this->country,
            'location' => $this->location,
            'device' => $this->device,
            'search_engine' => $this->searchEngine,
            'provider' => $this->providerKey,
        ];
    }
}
