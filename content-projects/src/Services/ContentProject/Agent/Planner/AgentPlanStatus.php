<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

final class AgentPlanStatus
{
    public const DRAFT = 'draft';

    public const PENDING_CONFIRMATION = 'pending_confirmation';

    public const CONFIRMED = 'confirmed';

    public const RUNNING = 'running';

    public const PAUSED = 'paused';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** @return list<string> */
    public static function terminal(): array
    {
        return [self::COMPLETED, self::FAILED, self::CANCELLED, self::EXPIRED];
    }
}
