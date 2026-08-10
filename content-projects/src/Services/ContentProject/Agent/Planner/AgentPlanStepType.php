<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

final class AgentPlanStepType
{
    public const CAPABILITY = 'capability';

    public const WAIT_OPERATION = 'wait_operation';

    public const WAIT_CONDITION = 'wait_condition';
}
