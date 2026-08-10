<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\AutomationActionRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationConditionRegistry;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationHealthCheckRegistry;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationMenuRegistry;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationPermissionRegistry;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationSettingsRegistry;
use Illuminate\Contracts\Container\Container;

final class AutomationModuleContext
{
    public function __construct(
        public readonly BusinessEventRegistry $events,
        public readonly AutomationActionRegistry $actions,
        public readonly AutomationConditionRegistry $conditions,
        public readonly AutomationHealthCheckRegistry $healthChecks,
        public readonly AutomationMenuRegistry $menus,
        public readonly AutomationPermissionRegistry $permissions,
        public readonly AutomationSettingsRegistry $settings,
        public readonly Container $container,
    ) {}

    public static function create(Container $container): self
    {
        return new self(
            events: new BusinessEventRegistry,
            actions: new AutomationActionRegistry($container),
            conditions: $container->make(AutomationConditionRegistry::class),
            healthChecks: $container->make(AutomationHealthCheckRegistry::class),
            menus: $container->make(AutomationMenuRegistry::class),
            permissions: $container->make(AutomationPermissionRegistry::class),
            settings: $container->make(AutomationSettingsRegistry::class),
            container: $container,
        );
    }
}
