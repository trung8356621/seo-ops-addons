<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Jobs;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentRetentionService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ApplyAgentRetentionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly bool $dryRun = false,
    ) {}

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentRetentionService $retention,
    ): void {
        $databaseConnection->bootstrapLegacySharedConnection();
        $retention->prune($this->dryRun);
    }
}
