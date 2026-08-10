<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationRunResult;

interface AgentAutomationRunner
{
    public function run(int $runId): AgentAutomationRunResult;
}
