<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Registry;

use Omnichannel\Addons\Agent\Automation\Platform\Data\MenuItemDefinition;
use InvalidArgumentException;

final class AutomationMenuRegistry
{
    /** @var array<string, MenuItemDefinition> */
    private array $items = [];

    public function register(MenuItemDefinition $definition): void
    {
        if (isset($this->items[$definition->key])) {
            throw new InvalidArgumentException("Menu item [{$definition->key}] already registered.");
        }

        $this->items[$definition->key] = $definition;
    }

    /**
     * @return array<string, MenuItemDefinition>
     */
    public function all(): array
    {
        return $this->items;
    }
}
