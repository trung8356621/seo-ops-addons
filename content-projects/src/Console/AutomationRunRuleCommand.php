<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationExecutionService;
use Illuminate\Console\Command;

final class AutomationRunRuleCommand extends Command
{
    protected $signature = 'automation:run-rule
        {rule_code : Automation rule code}
        {--event-id= : Business event ID to attach execution}
        {--dry-run : Simulate action execution without side effects}';

    protected $description = 'Run an automation rule against a business event.';

    public function handle(AutomationExecutionService $executionService): int
    {
        if (app()->environment('production')) {
            $this->warn('Running in PRODUCTION environment. Proceed with caution.');
        }

        $ruleCode = (string) $this->argument('rule_code');
        $rule = AutomationRule::query()->where('code', $ruleCode)->with('actions')->first();

        if (! $rule instanceof AutomationRule) {
            $this->error("Rule [{$ruleCode}] not found.");

            return self::FAILURE;
        }

        $eventId = (int) ($this->option('event-id') ?? 0);
        if ($eventId <= 0) {
            $this->error('--event-id is required and must be a positive integer.');

            return self::FAILURE;
        }

        $event = BusinessEvent::query()->find($eventId);
        if (! $event instanceof BusinessEvent) {
            $this->error("Business event [{$eventId}] not found.");

            return self::FAILURE;
        }

        if ($event->event_name !== $rule->event_name) {
            $this->warn("Event name [{$event->event_name}] differs from rule event [{$rule->event_name}].");
        }

        $dryRun = (bool) $this->option('dry-run');

        $execution = $executionService->createPendingExecution($event, $rule);
        if ($execution === null) {
            $this->warn('Execution already exists for this event/rule/version (idempotent skip).');

            return self::SUCCESS;
        }

        $this->info("Created execution #{$execution->id} uuid={$execution->execution_uuid}");

        $result = $executionService->run((int) $execution->id, $dryRun);
        $this->info("Finished with status: {$result->status}".($dryRun ? ' (dry-run)' : ''));

        if ($result->error_message) {
            $this->line('Error: '.$result->error_message);
        }

        return self::SUCCESS;
    }
}
