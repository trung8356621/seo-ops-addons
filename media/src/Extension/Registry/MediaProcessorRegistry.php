<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Extension\Registry;

use Omnichannel\Addons\Media\Extension\Contracts\MediaProcessorDriver;
use InvalidArgumentException;

final class MediaProcessorRegistry
{
    /** @var array<string, MediaProcessorDriver> */
    private array $drivers = [];

    public function register(string $id, MediaProcessorDriver $driver): void
    {
        if (isset($this->drivers[$id])) {
            throw new InvalidArgumentException("Media processor driver [{$id}] already registered.");
        }

        $this->drivers[$id] = $driver;
    }

    public function get(string $id): ?MediaProcessorDriver
    {
        return $this->drivers[$id] ?? null;
    }

    /**
     * @return array<string, MediaProcessorDriver>
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
