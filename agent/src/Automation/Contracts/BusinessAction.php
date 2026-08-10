<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Contracts;

use Omnichannel\Addons\Agent\Automation\Data\ActionContext;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Data\ActionResult;

interface BusinessAction
{
    public static function definition(): ActionDefinition;

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ActionContext $context, array $input): ActionResult;
}
