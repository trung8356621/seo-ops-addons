<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Contracts\AgentAutomationRunner;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data\AgentAutomationRunResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService;
use Throwable;

final class ObservingAgentAutomationRunner implements AgentAutomationRunner
{
    public function __construct(
        private readonly AgentAutomationRunner $inner,
        private readonly AgentTraceService $traces,
        private readonly AgentMetricRecorder $metrics,
    ) {}

    public function run(int $runId): AgentAutomationRunResult
    {
        $traceId = $this->traces->startTrace(null, 'automation_run', [
            'run_id' => $runId,
        ]);
        $spanId = $this->traces->startSpan($traceId, 'automation_run');
        $this->metrics->record('automation.run', 1, [], $traceId);

        try {
            $result = $this->inner->run($runId);
            $status = $result->status;
            $metric = match ($status) {
                'succeeded' => 'automation.success',
                'no_change' => 'automation.no_change',
                'waiting_for_approval' => 'automation.approval_wait',
                'failed' => 'automation.failure',
                'skipped' => match ($result->skipReason) {
                    'quota_exceeded' => 'automation.quota_skip',
                    'overlap' => 'automation.overlap_skip',
                    'permission_lost' => 'automation.permission_lost',
                    default => 'automation.run',
                },
                default => 'automation.run',
            };
            if ($metric !== 'automation.run') {
                $this->metrics->record($metric, 1, ['status' => $status], $traceId);
            }
            $this->traces->endSpan($traceId, $spanId, $result->ok || $status === 'skipped' || $status === 'no_change' ? 'ok' : 'error', [
                'run_hash_id' => $result->runHashId,
                'status' => $status,
                'skip_reason' => $result->skipReason,
            ]);
            $this->traces->finishTrace($traceId, 'ok');

            return $result;
        } catch (Throwable $e) {
            $this->metrics->record('automation.failure', 1, ['status' => 'exception'], $traceId);
            $this->traces->endSpan($traceId, $spanId, 'error', [], $e::class);
            $this->traces->finishTrace($traceId, 'error');
            throw $e;
        }
    }
}
