<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Registry;

use Omnichannel\Addons\Agent\Extension\ExtensionDefinition;
use Omnichannel\Addons\Agent\Extension\ExtensionEvents;
use Omnichannel\Addons\Agent\Extension\ExtensionEventBus;
use Omnichannel\Addons\Agent\Extension\ExtensionStateStore;
use InvalidArgumentException;

final class ExtensionRegistry
{
    /** @var array<string, ExtensionDefinition> */
    private array $definitions = [];

    public function __construct(
        private readonly ExtensionStateStore $stateStore,
        private readonly ExtensionEventBus $events,
    ) {}

    public function register(ExtensionDefinition $definition): void
    {
        $id = $definition->manifest->id;

        if (isset($this->definitions[$id])) {
            throw new InvalidArgumentException("Extension [{$id}] already registered.");
        }

        $this->definitions[$id] = $definition;
    }

    public function enable(string $id): void
    {
        if (! isset($this->definitions[$id])) {
            throw new InvalidArgumentException("Extension [{$id}] not installed.");
        }

        $this->stateStore->setEnabled($id, true);
        $this->definitions[$id]->status = 'healthy';
        $this->events->dispatch(ExtensionEvents::EXTENSION_ENABLED, ['extension_id' => $id]);
    }

    public function disable(string $id): void
    {
        if (! isset($this->definitions[$id])) {
            throw new InvalidArgumentException("Extension [{$id}] not installed.");
        }

        $this->stateStore->setEnabled($id, false);
        $this->definitions[$id]->status = 'disabled';
        $this->events->dispatch(ExtensionEvents::EXTENSION_DISABLED, ['extension_id' => $id]);
    }

    /**
     * @return list<ExtensionDefinition>
     */
    public function installed(): array
    {
        return array_values($this->definitions);
    }

    public function find(string $id): ?ExtensionDefinition
    {
        return $this->definitions[$id] ?? null;
    }
}
