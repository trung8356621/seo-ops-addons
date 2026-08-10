<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Contracts;

use Omnichannel\Addons\Agent\Automation\Platform\AutomationModuleContext;

/**
 * Automation platform module — đăng ký events, actions, conditions, menu, permissions, health, settings.
 */
interface AutomationModuleProvider
{
    public function id(): string;

    public function register(AutomationModuleContext $context): void;
}
