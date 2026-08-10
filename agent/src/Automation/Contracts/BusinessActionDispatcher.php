<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Contracts;

use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;

/**
 * Execution boundary cho HTTP controller / Filament caller vào Catalog BusinessAction.
 * Caller không được gọi ActionRunner trực tiếp — luôn qua dispatcher này.
 */
interface BusinessActionDispatcher
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function dispatch(string $actionKey, array $input, ActionContext $context): ActionResult;
}
