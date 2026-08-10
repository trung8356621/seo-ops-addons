<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\Dtos\AgentPlanDraft;

interface ContentProjectPlanGenerator
{
    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    public function generate(
        AgentExecutionContext $context,
        string $objective,
        array $constraints = [],
        ?array $projectContext = null,
        ?ContentProjectAutomationPolicy $policy = null,
    ): AgentPlanDraft;
}
