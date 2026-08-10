<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationTriggerType;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessEventName;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\BusinessEvent;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Support\AutomationSnapshotSanitizer;
use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class AutomationSchedulerService
{
    public function __construct(
        private readonly AutomationExecutionService $executionService,
        private readonly AutomationSnapshotSanitizer $sanitizer,
    ) {}

    /**
     * @return array{claimed: int, dispatched: int, skipped: int}
     */
    public function dispatchDueRules(?Carbon $now = null): array
    {
        $now ??= now();
        $stats = ['claimed' => 0, 'dispatched' => 0, 'skipped' => 0];

        $rules = AutomationRule::query()
            ->where('is_enabled', true)
            ->where('trigger_type', AutomationTriggerType::Schedule->value)
            ->whereNotNull('schedule_expression')
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', $now);
            })
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $rule instanceof AutomationRule) {
                continue;
            }

            $claimed = $this->claimRule($rule, $now);
            if (! $claimed) {
                $stats['skipped']++;

                continue;
            }

            $stats['claimed']++;
            $occurrenceAt = $rule->next_run_at ?? $now;
            $occurrenceKey = hash('sha256', $rule->id.'|'.(int) $rule->version.'|'.$occurrenceAt->utc()->toIso8601String());

            $existing = AutomationExecution::query()
                ->where('automation_rule_id', $rule->id)
                ->where('scheduled_occurrence_key', $occurrenceKey)
                ->exists();

            if ($existing) {
                $this->advanceSchedule($rule, $now);
                $stats['skipped']++;

                continue;
            }

            try {
                $event = BusinessEvent::query()->create([
                    'event_uuid' => (string) Str::uuid(),
                    'event_name' => BusinessEventName::ScheduleTriggered->value,
                    'subject_type' => null,
                    'subject_id' => null,
                    'site_id' => null,
                    'project_id' => null,
                    'payload' => $this->sanitizer->sanitize([
                        'rule_id' => $rule->id,
                        'rule_code' => $rule->code,
                        'scheduled_at' => $occurrenceAt->utc()->toIso8601String(),
                    ]) ?? [],
                    'context' => $this->sanitizer->sanitize([
                        'scheduled_occurrence_key' => $occurrenceKey,
                        'rule_id' => $rule->id,
                    ]) ?? [],
                    'occurred_at' => $occurrenceAt,
                    'created_at' => now(),
                ]);

                $execution = $this->executionService->createPendingExecution($event, $rule);
                if ($execution instanceof AutomationExecution) {
                    $execution->forceFill(['scheduled_occurrence_key' => $occurrenceKey])->save();
                    ExecuteAutomationRuleJob::dispatch($execution->id);
                    $stats['dispatched']++;
                }
            } catch (\Throwable $e) {
                Log::error('automation.scheduler.dispatch_failed', [
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $this->advanceSchedule($rule, $now);
        }

        return $stats;
    }

    public function computeNextRunAt(AutomationRule $rule, ?Carbon $from = null): ?Carbon
    {
        $expression = trim((string) ($rule->schedule_expression ?? ''));
        if ($expression === '') {
            return null;
        }

        try {
            $cron = new CronExpression($expression);
        } catch (\Throwable) {
            return null;
        }

        $tz = $rule->schedule_timezone ?: config('app.timezone', 'UTC');
        $from ??= now($tz);

        return Carbon::instance($cron->getNextRunDate($from->toDateTimeString(), 0, false, $tz));
    }

    private function claimRule(AutomationRule $rule, Carbon $now): bool
    {
        return (bool) \App\Support\Automation\AutomationConnection::db()->transaction(function () use ($rule, $now): bool {
            /** @var AutomationRule|null $locked */
            $locked = AutomationRule::query()->whereKey($rule->id)->lockForUpdate()->first();
            if (! $locked instanceof AutomationRule || ! $locked->is_enabled) {
                return false;
            }

            if ($locked->next_run_at !== null && $locked->next_run_at->isFuture()) {
                return false;
            }

            if ($locked->next_run_at === null) {
                $locked->next_run_at = $this->computeNextRunAt($locked, $now);
                $locked->save();
            }

            return $locked->next_run_at !== null && $locked->next_run_at->lte($now);
        });
    }

    private function advanceSchedule(AutomationRule $rule, Carbon $now): void
    {
        $next = $this->computeNextRunAt($rule, $now->copy()->addSecond());
        AutomationRule::query()->whereKey($rule->id)->update([
            'last_scheduled_at' => $now,
            'next_run_at' => $next,
        ]);
    }
}
