<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts;

use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\Contracts\SerpIntelligenceProviderInterface;
use InvalidArgumentException;

final class SerpIntelligenceProviderRegistry
{
    /** @var array<string, SerpIntelligenceProviderInterface> */
    private array $providers = [];

    public function register(SerpIntelligenceProviderInterface $provider): void
    {
        $key = $provider->key();
        if (isset($this->providers[$key])) {
            throw new InvalidArgumentException("SERP intelligence provider [{$key}] already registered.");
        }

        $this->providers[$key] = $provider;
    }

    public function get(string $key): ?SerpIntelligenceProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * @return array<string, SerpIntelligenceProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
