<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;

interface AutomationActionHandler
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $settings
     */
    public function handle(
        AutomationActionContext $context,
        array $input,
        array $settings,
    ): AutomationActionResult;
}
