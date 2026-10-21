<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SocialAutomation\Models\SaSchedule;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;
use Omnichannel\Addons\SocialAutomation\Scheduling\ScheduleDispatcher;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceResolver;

/**
 * Tick enabled schedules + reconcile expired leases.
 * Wire into Laravel scheduler after migrate (see final integration notes).
 */
final class SocialAutomationScheduleTickCommand extends Command
{
    protected $signature = 'social-automation:schedule-tick';

    protected $description = 'Fire due Social Automation schedules (online-only) and reconcile job leases';

    public function handle(ScheduleDispatcher $dispatcher, JobOrchestrator $orchestrator, SocialAutomationServiceResolver $resolver): int
    {
        if (! $resolver->isActive()) {
            $this->warn('Social Automation service inactive — skip.');

            return self::SUCCESS;
        }
        if (! SocialAutomationSchema::schedulesReady()) {
            $this->warn('PENDING DB MIGRATION — sa_schedules missing.');

            return self::SUCCESS;
        }

        $now = now();
        $fired = 0;
        foreach (SaSchedule::query()->where('enabled', true)->get() as $schedule) {
            // V1: caller/cron decides cadence; each invoke creates one trigger with idempotency on fire timestamp minute.
            $fireAt = $now->copy()->startOfMinute();
            $result = $dispatcher->fire($schedule, $fireAt);
            if ($result['ok'] ?? false) {
                $fired++;
            }
        }

        $reconciled = $orchestrator->reconcileExpiredLeases();
        $this->info("triggers_considered={$fired} leases_reconciled=".((int) ($reconciled['reconciled'] ?? 0)));

        return self::SUCCESS;
    }
}
