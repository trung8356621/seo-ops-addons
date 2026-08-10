<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Extension\Registry;

use Omnichannel\Addons\Media\Extension\Contracts\AiImageProviderInterface;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiProviderDriver;
use Omnichannel\Addons\AiPrompt\Extension\Contracts\AiTextProviderInterface;
use InvalidArgumentException;

/**
 * Holds two independent slots:
 * - legacy `AiProviderDriver` keyed by extension id, used only by ExtensionHealthService.
 * - real `AiTextProviderInterface`/`AiImageProviderInterface` keyed by provider key
 *   (e.g. "gemini", "claude"), used by AiProviderResolver for actual generation.
 */
final class AiProviderRegistry
{
    /** @var array<string, AiProviderDriver> */
    private array $drivers = [];

    /** @var array<string, AiTextProviderInterface> */
    private array $textProviders = [];

    /** @var array<string, AiImageProviderInterface> */
    private array $imageProviders = [];

    public function register(string $id, AiProviderDriver $driver): void
    {
        if (isset($this->drivers[$id])) {
            throw new InvalidArgumentException("AI provider driver [{$id}] already registered.");
        }

        $this->drivers[$id] = $driver;
    }

    public function get(string $id): ?AiProviderDriver
    {
        return $this->drivers[$id] ?? null;
    }

    /**
     * @return array<string, AiProviderDriver>
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

    public function registerText(AiTextProviderInterface $provider): void
    {
        $key = $provider->key();
        if (isset($this->textProviders[$key])) {
            throw new InvalidArgumentException("AI text provider [{$key}] already registered.");
        }

        $this->textProviders[$key] = $provider;
    }

    public function getText(string $key): ?AiTextProviderInterface
    {
        return $this->textProviders[$key] ?? null;
    }

    public function hasText(string $key): bool
    {
        return isset($this->textProviders[$key]);
    }

    /**
     * @return list<string>
     */
    public function textKeys(): array
    {
        return array_keys($this->textProviders);
    }

    public function registerImage(AiImageProviderInterface $provider): void
    {
        $key = $provider->key();
        if (isset($this->imageProviders[$key])) {
            throw new InvalidArgumentException("AI image provider [{$key}] already registered.");
        }

        $this->imageProviders[$key] = $provider;
    }

    public function getImage(string $key): ?AiImageProviderInterface
    {
        return $this->imageProviders[$key] ?? null;
    }

    public function hasImage(string $key): bool
    {
        return isset($this->imageProviders[$key]);
    }

    /**
     * @return list<string>
     */
    public function imageKeys(): array
    {
        return array_keys($this->imageProviders);
    }
}
