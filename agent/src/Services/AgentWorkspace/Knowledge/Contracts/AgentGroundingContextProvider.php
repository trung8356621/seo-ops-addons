<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentGroundedContextPackage;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;

interface AgentGroundingContextProvider
{
    public function build(
        AgentPlanningRequest $request,
        ?AgentWorkspaceContext $context = null,
    ): AgentGroundedContextPackage;
}
