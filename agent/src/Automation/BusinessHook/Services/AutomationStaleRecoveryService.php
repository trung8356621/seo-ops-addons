<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Services;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\AutomationNodeExecutionStatus;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationNodeJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Jobs\ExecuteAutomationRuleJob;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationExecution;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationNodeExecution;
use Illuminate\Support\Facades\Log;

final class AutomationStaleRecoveryService
{
    private const EXECUTION_STALE_SECONDS = 900;

    private const NODE_STALE_SECONDS = 600;

    /**
     * @return array{executions: int, nodes: int, scheduled: int, missed: int}
     */
    public function recover(): array
    {
        $stats = ['executions' => 0, 'nodes' => 0, 'scheduled' => 0, 'missed' => 0];

        $staleExecutions = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Processing->value)
            ->where(function ($query): void {
                $query->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subSeconds(self::EXECUTION_STALE_SECONDS));
            })
            ->where('started_at', '<', now()->subSeconds(self::EXECUTION_STALE_SECONDS))
            ->limit(100)
            ->get();

        foreach ($staleExecutions as $execution) {
            if (! $execution instanceof AutomationExecution) {
                continue;
            }

            $hasActiveNodes = AutomationNodeExecution::query()
                ->where('automation_execution_id', $execution->id)
                ->whereIn('status', [
                    AutomationNodeExecutionStatus::Pending->value,
                    AutomationNodeExecutionStatus::Scheduled->value,
                    AutomationNodeExecutionStatus::Processing->value,
                ])
                ->exists();

            if ($hasActiveNodes) {
                $execution->forceFill([
                    'heartbeat_at' => now(),
                    'error_code' => BusinessHookErrorCode::ExecutionStale->value,
                ])->save();
                $stats['executions']++;
                continue;
            }

            $execution->forceFill([
                'status' => AutomationExecutionStatus::Pending->value,
                'error_code' => BusinessHookErrorCode::ExecutionStale->value,
            ])->save();
            ExecuteAutomationRuleJob::dispatch($execution->id)->onQueue('automation-critical');
            $stats['executions']++;
        }

        $staleNodes = AutomationNodeExecution::query()
            ->where('status', AutomationNodeExecutionStatus::Processing->value)
            ->where(function ($query): void {
                $query->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<', now()->subSeconds(self::NODE_STALE_SECONDS));
            })
            ->where('started_at', '<', now()->subSeconds(self::NODE_STALE_SECONDS))
            ->limit(200)
            ->get();

        foreach ($staleNodes as $nodeExec) {
            if (! $nodeExec instanceof AutomationNodeExecution) {
                continue;
            }

            if ($this->isUnsafeRecovery($nodeExec)) {
                $nodeExec->forceFill([
                    'status' => AutomationNodeExecutionStatus::Failed->value,
                    'error_code' => BusinessHookErrorCode::NodeRecoveryUnsafe->value,
                    'error_message' => 'Manual review required.',
                    'finished_at' => now(),
                ])->save();
                $stats['nodes']++;

                continue;
            }

            $nodeExec->forceFill([
                'status' => AutomationNodeExecutionStatus::Pending->value,
                'started_at' => null,
                'error_code' => BusinessHookErrorCode::NodeStale->value,
            ])->save();
            ExecuteAutomationNodeJob::dispatch($nodeExec->id)->onQueue('automation-critical');
            $stats['nodes']++;
        }

        $dueScheduled = AutomationNodeExecution::query()
            ->where('status', AutomationNodeExecutionStatus::Scheduled->value)
            ->whereNotNull('available_at')
            ->where('available_at', '<=', now())
            ->limit(200)
            ->get();

        foreach ($dueScheduled as $nodeExec) {
            if (! $nodeExec instanceof AutomationNodeExecution) {
                continue;
            }
            ExecuteAutomationNodeJob::dispatch($nodeExec->id);
            $stats['scheduled']++;
        }

        $missedRules = \Omnichannel\Addons\Agent\Automation\BusinessHook\Models\AutomationRule::query()
            ->where('trigger_type', 'schedule')
            ->where('is_enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<', now()->subMinutes(5))
            ->limit(50)
            ->get();

        foreach ($missedRules as $rule) {
            Log::warning('automation.scheduler.missed', ['rule_id' => $rule->id]);
            $stats['missed']++;
        }

        return $stats;
    }

    private function isUnsafeRecovery(AutomationNodeExecution $nodeExec): bool
    {
        if ($nodeExec->node_type !== 'action') {
            return false;
        }

        $input = is_array($nodeExec->input_snapshot) ? $nodeExec->input_snapshot : [];

        return $input !== [] && $nodeExec->output_snapshot === null;
    }
}
