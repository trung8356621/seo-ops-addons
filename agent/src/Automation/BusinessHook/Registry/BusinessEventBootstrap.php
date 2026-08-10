<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Registry;

use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;
use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleRegistry;
use Illuminate\Contracts\Container\Container;

/**
 * @deprecated Use AutomationModuleRegistry via AutomationPlatformKernel.
 */
final class BusinessEventBootstrap
{
    public function register(BusinessEventRegistry $registry, ?Container $container = null): void
    {
        $container ??= app();
        $context = new AutomationModuleContext(
            events: $registry,
            actions: new AutomationActionRegistry($container),
            conditions: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationConditionRegistry,
            healthChecks: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationHealthCheckRegistry,
            menus: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationMenuRegistry,
            permissions: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationPermissionRegistry,
            settings: new \Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationSettingsRegistry,
            container: $container,
        );

        AutomationModuleRegistry::fromConfig($container)->boot($context);
    }
}
