<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

final class AgentAutomationLevel
{
    public const MANUAL = 'manual';

    public const ASSISTED = 'assisted';

    public const REVIEWED_AUTOMATION = 'reviewed_automation';

    public const FULL_AUTOMATION = 'full_automation';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MANUAL,
            self::ASSISTED,
            self::REVIEWED_AUTOMATION,
            self::FULL_AUTOMATION,
        ];
    }
}
