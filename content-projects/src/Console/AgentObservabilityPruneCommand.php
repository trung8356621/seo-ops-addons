<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\Agent\Jobs\ApplyAgentRetentionJob;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentRetentionService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class AgentObservabilityPruneCommand extends Command
{
    protected $signature = 'agent:observability:prune {--dry-run : Report only} {--sync : Run inline}';

    protected $description = 'Prune Agent observability raw events/traces per retention policy.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentRetentionService $retention,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('sync') || $dryRun) {
            $counts = $retention->prune($dryRun);
            $this->info(($dryRun ? 'dry-run ' : '').json_encode($counts));

            return self::SUCCESS;
        }

        ApplyAgentRetentionJob::dispatch(false);
        $this->info('queued');

        return self::SUCCESS;
    }
}
