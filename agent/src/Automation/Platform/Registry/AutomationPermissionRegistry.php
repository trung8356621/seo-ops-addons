<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Registry;

use Omnichannel\Addons\Agent\Automation\Platform\Data\PermissionDefinition;
use InvalidArgumentException;

final class AutomationPermissionRegistry
{
    /** @var array<string, PermissionDefinition> */
    private array $permissions = [];

    public function register(PermissionDefinition $definition): void
    {
        if (isset($this->permissions[$definition->key])) {
            throw new InvalidArgumentException("Permission [{$definition->key}] already registered.");
        }

        $this->permissions[$definition->key] = $definition;
    }

    /**
     * @return array<string, PermissionDefinition>
     */
    public function all(): array
    {
        return $this->permissions;
    }
}
