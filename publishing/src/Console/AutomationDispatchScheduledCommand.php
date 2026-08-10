<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerService;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationSchedulerHeartbeatService;
use Illuminate\Console\Command;

final class AutomationDispatchScheduledCommand extends Command
{
    protected $signature = 'automation:dispatch-scheduled';

    protected $description = 'Dispatch due scheduled automation rules (idempotent per occurrence).';

    public function handle(
        AutomationSchedulerService $scheduler,
        AutomationSchedulerHeartbeatService $heartbeats,
    ): int {
        $stats = $scheduler->dispatchDueRules();
        $heartbeats->beat(AutomationSchedulerHeartbeatService::NAME_DISPATCH_SCHEDULED, $stats);
        $this->info(sprintf(
            'claimed=%d dispatched=%d skipped=%d',
            $stats['claimed'],
            $stats['dispatched'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
