<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Registry;

use Omnichannel\Addons\Agent\Automation\Platform\Data\SettingDefinition;
use InvalidArgumentException;

final class AutomationSettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $settings = [];

    public function register(SettingDefinition $definition): void
    {
        if (isset($this->settings[$definition->key])) {
            throw new InvalidArgumentException("Setting [{$definition->key}] already registered.");
        }

        $this->settings[$definition->key] = $definition;
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return $this->settings;
    }
}
