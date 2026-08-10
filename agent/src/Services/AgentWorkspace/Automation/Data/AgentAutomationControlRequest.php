<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationControlRequest
{
    public const ACTION_PAUSE = 'pause';

    public const ACTION_RESUME = 'resume';

    public const ACTION_DELETE = 'delete';

    public const ACTION_ENABLE = 'enable';

    public const ACTION_DISABLE = 'disable';

    public function __construct(
        public string $automationHashId,
        public string $action,
        public ?string $reason = null,
        public string $catchUpPolicy = 'skip_missed',
    ) {}
}
