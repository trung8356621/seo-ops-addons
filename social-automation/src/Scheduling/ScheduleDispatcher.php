<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Scheduling;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Domain\AgentKindPolicy;
use Omnichannel\Addons\SocialAutomation\Domain\OnlineStateCalculator;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaSchedule;
use Omnichannel\Addons\SocialAutomation\Models\SaScheduleTrigger;
use Omnichannel\Addons\SocialAutomation\Models\SaTask;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationServiceConfig;
use Throwable;

/**
 * Fire a schedule once (caller supplies fire time). Offline → skip, no backlog.
 */
final class ScheduleDispatcher
{
    public function __construct(
        private readonly JobOrchestrator $orchestrator,
        private readonly CronSkipPolicy $policy = new CronSkipPolicy,
        private readonly OnlineStateCalculator $online = new OnlineStateCalculator,
        private readonly AgentKindPolicy $agentKinds = new AgentKindPolicy,
    ) {}

    /**
     * @return array{ok: bool, trigger?: array<string, mixed>, errors?: list<string>}
     */
    public function fire(SaSchedule $schedule, \DateTimeInterface $fireAt): array
    {
        if (! SocialAutomationSchema::schedulesReady() || ! SocialAutomationSchema::jobsReady()) {
            return ['ok' => false, 'errors' => ['schema_unavailable']];
        }

        $task = SaTask::query()->find($schedule->task_id);
        if (! $task instanceof SaTask || $task->status !== 'active') {
            return ['ok' => false, 'errors' => ['task_not_active']];
        }

        $gate = $this->orchestrator->assertTaskPackageDispatchable($task);
        if (! ($gate['ok'] ?? false)) {
            return ['ok' => false, 'errors' => $gate['errors'] ?? ['package_disabled']];
        }

        $fireIso = $fireAt instanceof \DateTimeImmutable
            ? $fireAt->format(\DateTimeInterface::ATOM)
            : (new \DateTimeImmutable($fireAt->format('c')))->format(\DateTimeInterface::ATOM);
        $idem = $this->policy->triggerIdempotencyKey($schedule->schedule_uuid, $fireIso);

        $existing = SaScheduleTrigger::query()->where('idempotency_key', $idem)->first();
        if ($existing instanceof SaScheduleTrigger) {
            return [
                'ok' => true,
                'trigger' => $this->triggerArray($existing),
            ];
        }

        $agents = SaAgent::query()->get()->map(function (SaAgent $a): array {
            $kind = $this->agentKinds->normalize(
                isset($a->agent_kind) ? (string) $a->agent_kind : AgentKindPolicy::KIND_RUNNER
            );

            return [
                'online' => $this->agentKinds->countsAsOnlineForDispatch(
                    $kind,
                    $this->online->isOnline($a->last_heartbeat_at)
                ),
            ];
        })->all();

        $offlineGate = $this->policy->whenAllAgentsOffline($agents);
        $target = (int) ($schedule->target_account_count ?? $task->target_account_count);

        try {
            $trigger = DB::connection(SocialAutomationServiceConfig::CONNECTION)->transaction(function () use (
                $schedule, $task, $idem, $fireAt, $offlineGate, $target
            ): SaScheduleTrigger {
                if (! ($offlineGate['should_dispatch'] ?? false)) {
                    $summary = $this->policy->summarizeDispatch($target, 0);

                    return SaScheduleTrigger::query()->create([
                        'trigger_uuid' => (string) Str::uuid(),
                        'schedule_id' => $schedule->id,
                        'task_id' => $task->id,
                        'idempotency_key' => $idem,
                        'fired_at' => $fireAt,
                        'outcome' => CronSkipPolicy::REASON_OFFLINE,
                        'target_count' => $target,
                        'dispatched_count' => 0,
                        'shortfall' => $target,
                        'skip_reason' => CronSkipPolicy::REASON_OFFLINE,
                        'summary' => $summary,
                    ]);
                }

                // Select + reserve inside this transaction (account row locks in reserveJob).
                $selection = $this->orchestrator->selectEligible($task, $target);
                $created = 0;
                $trigger = SaScheduleTrigger::query()->create([
                    'trigger_uuid' => (string) Str::uuid(),
                    'schedule_id' => $schedule->id,
                    'task_id' => $task->id,
                    'idempotency_key' => $idem,
                    'fired_at' => $fireAt,
                    'outcome' => 'dispatching',
                    'target_count' => $target,
                    'dispatched_count' => 0,
                    'shortfall' => $target,
                    'skip_reason' => null,
                    'summary' => null,
                ]);

                foreach ($selection['selected'] as $row) {
                    $job = $this->orchestrator->reserveJob($task, $row, (int) $trigger->id, 'scheduler');
                    if ($job !== null) {
                        $created++;
                    }
                }

                $summary = $this->policy->summarizeDispatch($target, $created);
                $trigger->fill([
                    'outcome' => $summary['outcome'],
                    'dispatched_count' => $created,
                    'shortfall' => $summary['shortfall'],
                    'skip_reason' => $created === 0 ? CronSkipPolicy::REASON_NO_ELIGIBLE : null,
                    'summary' => $summary + ['skip_reasons' => $selection['skip_reasons']],
                ])->save();

                $schedule->last_fired_at = $fireAt;
                $schedule->save();

                return $trigger->fresh() ?? $trigger;
            });
        } catch (Throwable) {
            // Concurrent ticks racing the unique idempotency_key — treat as idempotent win.
            $race = SaScheduleTrigger::query()->where('idempotency_key', $idem)->first();
            if ($race instanceof SaScheduleTrigger) {
                return ['ok' => true, 'trigger' => $this->triggerArray($race)];
            }

            return ['ok' => false, 'errors' => ['trigger_failed']];
        }

        return ['ok' => true, 'trigger' => $this->triggerArray($trigger)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSchedulesForUi(): array
    {
        if (! SocialAutomationSchema::schedulesReady()) {
            return [];
        }

        return SaSchedule::query()->with('task')->orderByDesc('id')->limit(100)->get()
            ->map(static function (SaSchedule $s): array {
                return [
                    'schedule_uuid' => $s->schedule_uuid,
                    'task_name' => $s->task?->name,
                    'cron_expression' => $s->cron_expression,
                    'timezone' => $s->timezone,
                    'enabled' => (bool) $s->enabled,
                    'target_account_count' => $s->target_account_count,
                    'package_version_pin' => $s->package_version_pin,
                    'last_fired_at' => $s->last_fired_at?->toIso8601String(),
                ];
            })->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function triggerArray(SaScheduleTrigger $t): array
    {
        return [
            'trigger_uuid' => $t->trigger_uuid,
            'idempotency_key' => $t->idempotency_key,
            'outcome' => $t->outcome,
            'target_count' => $t->target_count,
            'dispatched_count' => $t->dispatched_count,
            'shortfall' => $t->shortfall,
            'skip_reason' => $t->skip_reason,
            'summary' => $t->summary,
            'fired_at' => $t->fired_at?->toIso8601String(),
        ];
    }
}
