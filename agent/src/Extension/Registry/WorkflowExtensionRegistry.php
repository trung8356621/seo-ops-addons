<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Registry;

use Omnichannel\Addons\Agent\Extension\Contracts\WorkflowContributor;
use InvalidArgumentException;

final class WorkflowExtensionRegistry
{
    /** @var array<string, WorkflowContributor> */
    private array $contributors = [];

    public function register(string $id, WorkflowContributor $contributor): void
    {
        if (isset($this->contributors[$id])) {
            throw new InvalidArgumentException("Workflow contributor [{$id}] already registered.");
        }

        $this->contributors[$id] = $contributor;
    }

    public function get(string $id): ?WorkflowContributor
    {
        return $this->contributors[$id] ?? null;
    }

    /**
     * @return array<string, WorkflowContributor>
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
