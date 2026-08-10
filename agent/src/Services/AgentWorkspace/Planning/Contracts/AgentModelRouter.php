<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelRoutingContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentModelSelection;

interface AgentModelRouter
{
    public function resolve(AgentModelRoutingContext $context): AgentModelSelection;
}
