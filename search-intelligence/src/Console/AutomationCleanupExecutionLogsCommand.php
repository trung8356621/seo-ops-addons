<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\ExecutionCleanupService;
use Illuminate\Console\Command;

final class AutomationCleanupExecutionLogsCommand extends Command
{
    protected $signature = 'automation:cleanup-execution-logs';

    protected $description = 'Delete automation execution logs older than configured retention.';

    public function handle(ExecutionCleanupService $cleanup): int
    {
        $result = $cleanup->cleanupExpiredLogs();

        if ($result['skipped']) {
            $this->info('skipped=retention_forever');

            return self::SUCCESS;
        }

        $this->info(sprintf('deleted=%d', $result['deleted']));

        return self::SUCCESS;
    }
}
