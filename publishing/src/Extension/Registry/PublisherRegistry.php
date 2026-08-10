<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Extension\Registry;

use Omnichannel\Addons\Publishing\Extension\Contracts\PublisherDriver;
use InvalidArgumentException;

final class PublisherRegistry
{
    /** @var array<string, PublisherDriver> */
    private array $drivers = [];

    public function register(string $id, PublisherDriver $driver): void
    {
        if (isset($this->drivers[$id])) {
            throw new InvalidArgumentException("Publisher driver [{$id}] already registered.");
        }

        $this->drivers[$id] = $driver;
    }

    public function get(string $id): ?PublisherDriver
    {
        return $this->drivers[$id] ?? null;
    }

    /**
     * @return array<string, PublisherDriver>
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
}
