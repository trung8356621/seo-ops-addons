<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Jobs;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRunner;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue job for Agent Workspace automation runs.
 * Only calls AgentAutomationRunner — no business services.
 */
final class RunAgentAutomationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $runId,
    ) {}

    public function uniqueId(): string
    {
        return 'agent-automation-run:'.$this->runId;
    }

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentAutomationRunner $runner,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $runner->run($this->runId);
    }
}
