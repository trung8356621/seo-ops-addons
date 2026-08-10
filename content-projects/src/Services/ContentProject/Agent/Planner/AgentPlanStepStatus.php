<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

final class AgentPlanStepStatus
{
    public const PENDING = 'pending';

    public const WAITING = 'waiting';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    public const CANCELLED = 'cancelled';

    public const AWAITING_APPROVAL = 'awaiting_approval';
}
