<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Providers\Serp;

use Omnichannel\Addons\Seo\Contracts\SerpRankProviderInterface;
use Omnichannel\Addons\SearchIntelligence\Support\SerpProviderKeys;

final class SerpRankProviderRegistry
{
    /** @var array<string, SerpRankProviderInterface> */
    private array $providers = [];

    public function __construct(
        SerperDevProvider $serper,
        SerpApiProvider $serpApi,
        SearchApiProvider $searchApi,
    ) {
        foreach ([$serper, $serpApi, $searchApi] as $provider) {
            $this->providers[$provider->providerKey()] = $provider;
        }
    }

    public function get(string $providerKey): SerpRankProviderInterface
    {
        if (! isset($this->providers[$providerKey])) {
            throw new \InvalidArgumentException("Unknown SERP provider: {$providerKey}");
        }

        return $this->providers[$providerKey];
    }

    public function has(string $providerKey): bool
    {
        return isset($this->providers[$providerKey]);
    }

    /**
     * @return list<SerpRankProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return SerpProviderKeys::all();
    }
}
