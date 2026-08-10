<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationStaleRecoveryService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerHeartbeatService;
use Illuminate\Console\Command;

final class AutomationRecoverStaleCommand extends Command
{
    protected $signature = 'automation:recover-stale';

    protected $description = 'Recover stale automation executions and node jobs.';

    public function handle(
        AutomationStaleRecoveryService $recovery,
        AutomationSchedulerHeartbeatService $heartbeats,
    ): int {
        $stats = $recovery->recover();
        $heartbeats->beat(AutomationSchedulerHeartbeatService::NAME_RECOVER_STALE, $stats);
        $this->info(sprintf(
            'executions=%d nodes=%d scheduled=%d missed=%d',
            $stats['executions'],
            $stats['nodes'],
            $stats['scheduled'],
            $stats['missed'],
        ));

        return self::SUCCESS;
    }
}
