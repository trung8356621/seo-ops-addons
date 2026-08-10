<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Contracts;

use Omnichannel\Addons\Agent\Automation\Data\EventEnvelope;

interface AutomationEventDispatcher
{
    public function dispatch(EventEnvelope $event): void;
}
