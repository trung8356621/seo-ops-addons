<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Registry;

use Omnichannel\Addons\Agent\Extension\Contracts\PipelineDefinitionInterface;
use Omnichannel\Addons\Agent\Extension\Contracts\PipelineStepDriver;
use InvalidArgumentException;

/**
 * Holds two independent slots:
 * - legacy `PipelineStepDriver` keyed by extension id, used only by ExtensionHealthService.
 * - real `PipelineDefinitionInterface` keyed by pipeline key (e.g. "article", "rewrite"),
 *   used by PipelineResolver for actual pipeline execution.
 */
final class PipelineRegistry
{
    /** @var array<string, PipelineStepDriver> */
    private array $drivers = [];

    /** @var array<string, PipelineDefinitionInterface> */
    private array $definitions = [];

    public function register(string $id, PipelineStepDriver $driver): void
    {
        if (isset($this->drivers[$id])) {
            throw new InvalidArgumentException("Pipeline step driver [{$id}] already registered.");
        }

        $this->drivers[$id] = $driver;
    }

    public function get(string $id): ?PipelineStepDriver
    {
        return $this->drivers[$id] ?? null;
    }

    /**
     * @return array<string, PipelineStepDriver>
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

    public function registerDefinition(PipelineDefinitionInterface $definition): void
    {
        $key = $definition->key();
        if (isset($this->definitions[$key])) {
            throw new InvalidArgumentException("Pipeline definition [{$key}] already registered.");
        }

        $this->definitions[$key] = $definition;
    }

    public function getDefinition(string $key): ?PipelineDefinitionInterface
    {
        return $this->definitions[$key] ?? null;
    }

    public function hasDefinition(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return list<string>
     */
    public function definitionKeys(): array
    {
        return array_keys($this->definitions);
    }
}
