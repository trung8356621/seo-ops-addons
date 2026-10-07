<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthMonitor;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticServiceHealthStatus;

/**
 * Operational monitor for seo-ops-semantic — records health into notifications.
 * For human diagnostics use `semantic:doctor`.
 */
final class SemanticMonitorCommand extends Command
{
    protected $signature = 'semantic:monitor';

    protected $description = 'Probe seo-ops-semantic /health/ready and update operational alert state';

    public function handle(SemanticServiceHealthMonitor $monitor): int
    {
        if (! (bool) config('semantic.enabled', false)) {
            $this->info('Semantic integration disabled (SEMANTIC_ENABLED=false); skipping monitor.');

            return self::SUCCESS;
        }

        $snapshot = $monitor->check();
        $this->line('status='.$snapshot->status->value);
        if ($snapshot->errorCode !== '') {
            $this->line('error_code='.$snapshot->errorCode);
        }
        $this->line($snapshot->reason);

        return $snapshot->status === SemanticServiceHealthStatus::Healthy
            ? self::SUCCESS
            : self::FAILURE;
    }
}
