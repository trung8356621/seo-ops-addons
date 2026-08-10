<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Contracts;

use InvalidArgumentException;

final class GscIntelligenceProviderRegistry
{
    /** @var array<string, GscIntelligenceProviderInterface> */
    private array $providers = [];

    public function register(GscIntelligenceProviderInterface $provider): void
    {
        $key = $provider->key();
        if (isset($this->providers[$key])) {
            throw new InvalidArgumentException("GSC intelligence provider [{$key}] already registered.");
        }

        $this->providers[$key] = $provider;
    }

    public function get(string $key): ?GscIntelligenceProviderInterface
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
     * @return array<string, GscIntelligenceProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
