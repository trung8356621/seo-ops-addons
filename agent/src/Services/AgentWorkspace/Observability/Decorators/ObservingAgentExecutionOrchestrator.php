<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentExecutionOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionCancellation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionConfirmation;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionPreview;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRetry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService;
use Throwable;

final class ObservingAgentExecutionOrchestrator implements AgentExecutionOrchestrator
{
    public function __construct(
        private readonly AgentExecutionOrchestrator $inner,
        private readonly AgentTraceService $traces,
        private readonly AgentMetricRecorder $metrics,
    ) {}

    public function preview(AgentExecutionRequest $request): AgentExecutionPreview
    {
        $traceId = $this->traces->startTrace($request->context, 'execution_preview', [
            'skill_key' => $request->skillKey,
        ]);
        $spanId = $this->traces->startSpan($traceId, 'execution_preview');
        $this->metrics->record('execution.preview', 1, ['skill_key' => $request->skillKey], $traceId, $request->context->siteId);

        try {
            $preview = $this->inner->preview($request);
            $this->traces->endSpan($traceId, $spanId, 'ok', [
                'execution_ref' => $preview->executionRef,
                'requires_confirmation' => $preview->requiresConfirmation,
            ]);
            $this->traces->finishTrace($traceId, 'ok');

            return $preview;
        } catch (Throwable $e) {
            $this->traces->endSpan($traceId, $spanId, 'error', [], $e::class);
            $this->traces->finishTrace($traceId, 'error');
            throw $e;
        }
    }

    public function execute(AgentExecutionRequest $request): AgentExecutionResult
    {
        $traceId = $this->traces->startTrace($request->context, 'execution', [
            'skill_key' => $request->skillKey,
        ]);
        $spanId = $this->traces->startSpan($traceId, 'execution');

        try {
            $result = $this->inner->execute($request);
            $this->metrics->record(
                $result->ok ? 'execution.success' : 'execution.failure',
                1,
                [
                    'skill_key' => $request->skillKey,
                    'status' => $result->status->value,
                    'error_category' => $result->errorCategory?->value ?? '',
                ],
                $traceId,
                $request->context->siteId,
            );
            if ($result->idempotentReplay) {
                $this->metrics->record('execution.retry', 1, ['status' => 'idempotent_replay'], $traceId, $request->context->siteId);
            }
            $this->traces->endSpan($traceId, $spanId, $result->ok ? 'ok' : 'error', [
                'execution_ref' => $result->executionRef,
                'code' => $result->code,
            ], $result->ok ? null : $result->code);
            $this->traces->finishTrace($traceId, $result->ok ? 'ok' : 'error');

            return $result;
        } catch (Throwable $e) {
            $this->metrics->record('execution.failure', 1, ['status' => 'exception'], $traceId, $request->context->siteId);
            $this->traces->endSpan($traceId, $spanId, 'error', [], $e::class);
            $this->traces->finishTrace($traceId, 'error');
            throw $e;
        }
    }

    public function confirm(AgentExecutionConfirmation $confirmation): AgentExecutionResult
    {
        $traceId = $this->traces->startTrace($confirmation->context, 'confirmation', [
            'execution_ref' => $confirmation->executionRef,
        ]);
        $spanId = $this->traces->startSpan($traceId, 'confirmation');
        $this->metrics->record('execution.confirm', 1, [], $traceId, $confirmation->context->siteId);

        try {
            $result = $this->inner->confirm($confirmation);
            $this->metrics->record(
                $result->ok ? 'execution.success' : 'execution.failure',
                1,
                ['status' => $result->status->value],
                $traceId,
                $confirmation->context->siteId,
            );
            $this->traces->endSpan($traceId, $spanId, $result->ok ? 'ok' : 'error');
            $this->traces->finishTrace($traceId, $result->ok ? 'ok' : 'error');

            return $result;
        } catch (Throwable $e) {
            $this->traces->endSpan($traceId, $spanId, 'error', [], $e::class);
            $this->traces->finishTrace($traceId, 'error');
            throw $e;
        }
    }

    public function cancel(AgentExecutionCancellation $cancellation): AgentExecutionResult
    {
        return $this->inner->cancel($cancellation);
    }

    public function retry(AgentExecutionRetry $retry): AgentExecutionResult
    {
        $this->metrics->record('execution.retry', 1, [], null, $retry->context->siteId);

        return $this->inner->retry($retry);
    }
}
