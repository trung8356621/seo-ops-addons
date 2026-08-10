<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningValidationResult;

interface AgentPlanValidator
{
    public function validate(
        AgentPlanningResponse $response,
        AgentPlanningRequest $request,
        AgentWorkspaceContext $context,
    ): AgentPlanningValidationResult;
}
