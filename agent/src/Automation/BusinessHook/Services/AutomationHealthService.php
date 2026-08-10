<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationNodeExecution;
use Omnichannel\Addons\Agent\Automation\Platform\Registry\AutomationHealthCheckRegistry;

final class AutomationHealthService
{
    private const EXECUTION_STALE_SECONDS = 900;

    private const NODE_STALE_SECONDS = 600;

    private const HEARTBEAT_WARN_SECONDS = 300;

    public function __construct(
        private readonly AutomationSchedulerHeartbeatService $heartbeats,
        private readonly ?AutomationHealthCheckRegistry $moduleHealthChecks = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $now = now();

        $scheduler = [];
        foreach ([
            AutomationSchedulerHeartbeatService::NAME_DISPATCH_SCHEDULED,
            AutomationSchedulerHeartbeatService::NAME_RECOVER_STALE,
        ] as $name) {
            $beat = $this->heartbeats->lastBeat($name);
            $ageSeconds = $beat?->last_beat_at !== null
                ? (int) $beat->last_beat_at->diffInSeconds($now)
                : null;

            $scheduler[$name] = [
                'last_beat_at' => $beat?->last_beat_at?->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'healthy' => $ageSeconds !== null && $ageSeconds <= self::HEARTBEAT_WARN_SECONDS,
                'meta' => $beat?->meta,
            ];
        }

        $pendingExecutions = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Pending->value)
            ->count();

        $processingExecutions = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Processing->value)
            ->count();

        $pendingNodes = AutomationNodeExecution::query()
            ->whereIn('status', [
                AutomationNodeExecutionStatus::Pending->value,
                AutomationNodeExecutionStatus::Scheduled->value,
                AutomationNodeExecutionStatus::Waiting->value,
            ])
            ->count();

        $staleExecutions = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Processing->value)
            ->where(function ($query): void {
                $query->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subSeconds(self::EXECUTION_STALE_SECONDS));
            })
            ->where('started_at', '<', now()->subSeconds(self::EXECUTION_STALE_SECONDS))
            ->count();

        $staleNodes = AutomationNodeExecution::query()
            ->where('status', AutomationNodeExecutionStatus::Processing->value)
            ->where(function ($query): void {
                $query->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subSeconds(self::NODE_STALE_SECONDS));
            })
            ->where('started_at', '<', now()->subSeconds(self::NODE_STALE_SECONDS))
            ->count();

        $deadLetters = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Failed->value)
            ->where('finished_at', '>=', now()->subDays(7))
            ->count();

        $moduleChecks = $this->moduleHealthChecks?->runAll() ?? [];

        return [
            'checked_at' => $now->toIso8601String(),
            'scheduler' => $scheduler,
            'backlog' => [
                'pending_executions' => $pendingExecutions,
                'processing_executions' => $processingExecutions,
                'pending_nodes' => $pendingNodes,
            ],
            'stale' => [
                'executions' => $staleExecutions,
                'nodes' => $staleNodes,
            ],
            'dead_letters' => [
                'failed_executions_7d' => $deadLetters,
            ],
            'modules' => $moduleChecks,
            'healthy' => $staleExecutions === 0
                && $staleNodes === 0
                && collect($scheduler)->every(static fn (array $row): bool => (bool) ($row['healthy'] ?? false))
                && collect($moduleChecks)->every(static fn (array $row): bool => ($row['status'] ?? '') === 'ok'),
        ];
    }
}
