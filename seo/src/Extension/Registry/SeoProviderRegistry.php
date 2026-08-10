<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Extension\Registry;

use Omnichannel\Addons\Seo\Extension\Contracts\SeoProviderDriver;
use Omnichannel\Addons\Seo\Extension\Contracts\SeoProviderInterface;
use InvalidArgumentException;

/**
 * Holds two independent slots:
 * - legacy `SeoProviderDriver` keyed by extension id, used only by ExtensionHealthService.
 * - real `SeoProviderInterface` keyed by provider key (e.g. "local-seo", "ahrefs").
 */
final class SeoProviderRegistry
{
    /** @var array<string, SeoProviderDriver> */
    private array $drivers = [];

    /** @var array<string, SeoProviderInterface> */
    private array $providers = [];

    public function register(string $id, SeoProviderDriver $driver): void
    {
        if (isset($this->drivers[$id])) {
            throw new InvalidArgumentException("SEO provider driver [{$id}] already registered.");
        }

        $this->drivers[$id] = $driver;
    }

    public function get(string $id): ?SeoProviderDriver
    {
        return $this->drivers[$id] ?? null;
    }

    /**
     * @return array<string, SeoProviderDriver>
     */
    public function all(): array
    {
        return $this->drivers;
    }

    public function has(string $id): bool
    {
        return isset($this->drivers[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->drivers);
    }

    public function registerProvider(SeoProviderInterface $provider): void
    {
        $key = $provider->key();
        if (isset($this->providers[$key])) {
            throw new InvalidArgumentException("SEO provider [{$key}] already registered.");
        }

        $this->providers[$key] = $provider;
    }

    public function getProvider(string $key): ?SeoProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    public function hasProvider(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return list<string>
     */
    public function providerKeys(): array
    {
        return array_keys($this->providers);
    }
}
