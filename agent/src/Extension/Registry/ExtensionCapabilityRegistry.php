<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Registry;

use Omnichannel\Addons\Agent\Extension\Contracts\CapabilityContributor;
use InvalidArgumentException;

final class ExtensionCapabilityRegistry
{
    /** @var array<string, CapabilityContributor> */
    private array $contributors = [];

    public function register(string $id, CapabilityContributor $contributor): void
    {
        if (isset($this->contributors[$id])) {
            throw new InvalidArgumentException("Capability contributor [{$id}] already registered.");
        }

        $this->contributors[$id] = $contributor;
    }

    public function get(string $id): ?CapabilityContributor
    {
        return $this->contributors[$id] ?? null;
    }

    /**
     * @return array<string, CapabilityContributor>
     */
    public function all(): array
    {
        return $this->contributors;
    }

    public function has(string $id): bool
    {
        return isset($this->contributors[$id]);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->contributors);
    }
}
