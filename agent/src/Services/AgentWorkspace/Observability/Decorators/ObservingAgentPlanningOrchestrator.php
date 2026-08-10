<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Decorators;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentCostUsageTracker;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentMetricRecorder;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentPolicyViolationDetector;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\AgentTraceService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanningOrchestrator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlan;
use Throwable;

/**
 * Side-channel decorator — preserves planning behavior/return types.
 */
final class ObservingAgentPlanningOrchestrator implements AgentPlanningOrchestrator
{
    public function __construct(
        private readonly AgentPlanningOrchestrator $inner,
        private readonly AgentTraceService $traces,
        private readonly AgentMetricRecorder $metrics,
        private readonly AgentPolicyViolationDetector $policy,
        private readonly AgentCostUsageTracker $cost,
    ) {}

    public function plan(AgentPlanningRequest $request): array
    {
        $traceId = $this->traces->startTrace($request->context, 'planning', [
            'conversation_id' => $request->conversation->id ?? null,
        ]);
        $spanId = $this->traces->startSpan($traceId, 'planning');
        $this->metrics->record('planning.count', 1, [], $traceId, $request->context->siteId, $request->context->actorUserId);

        try {
            $result = $this->inner->plan($request);
            $type = (string) ($result['type'] ?? $result['response']['type'] ?? 'unknown');
            $this->metrics->record('planning.success', 1, ['response_type' => $type], $traceId, $request->context->siteId);
            $this->metrics->record('planning.response_type', 1, ['response_type' => $type], $traceId, $request->context->siteId);
            if (($result['needs_clarification'] ?? false) === true || $type === 'clarification') {
                $this->metrics->record('planning.clarification', 1, ['response_type' => $type], $traceId, $request->context->siteId);
            }
            if (($result['repaired'] ?? false) === true || ! empty($result['repair_actions'] ?? [])) {
                $this->metrics->record('planning.repair', 1, [], $traceId, $request->context->siteId);
            }
            $meta = is_array($result['meta'] ?? null) ? $result['meta'] : [];
            if (isset($meta['latency_ms'])) {
                $this->metrics->record('planning.latency_ms', (float) $meta['latency_ms'], [
                    'provider' => (string) ($meta['provider'] ?? ''),
                    'model' => (string) ($meta['model'] ?? ''),
                ], $traceId, $request->context->siteId);
            }
            $this->cost->track(
                is_array($meta['usage'] ?? null) ? $meta['usage'] : null,
                isset($meta['provider']) ? (string) $meta['provider'] : null,
                isset($meta['model']) ? (string) $meta['model'] : null,
                isset($meta['latency_ms']) ? (int) $meta['latency_ms'] : null,
                $traceId,
                $request->context->siteId,
            );
            $this->policy->inspect($result, $traceId, $request->context->siteId);
            $this->traces->endSpan($traceId, $spanId, 'ok', ['response_type' => $type]);
            $this->traces->finishTrace($traceId, 'ok');
            $result['trace_id'] = $traceId;

            return $result;
        } catch (Throwable $e) {
            $this->metrics->record('planning.failure', 1, ['status' => 'failed'], $traceId, $request->context->siteId);
            $this->traces->endSpan($traceId, $spanId, 'error', [], $e::class);
            $this->traces->finishTrace($traceId, 'error');
            throw $e;
        }
    }

    public function answerClarification(AgentPlanningRequest $request, array $answers): array
    {
        return $this->inner->answerClarification($request, $answers);
    }

    public function validateProposal(AgentPlanningResponse $response, AgentPlanningRequest $request): AgentPlanningResponse
    {
        $traceId = $this->traces->startTrace($request->context, 'plan_validation');
        $spanId = $this->traces->startSpan($traceId, 'plan_validation');
        try {
            $validated = $this->inner->validateProposal($response, $request);
            $this->traces->endSpan($traceId, $spanId, 'ok');
            $this->traces->finishTrace($traceId, 'ok');

            return $validated;
        } catch (Throwable $e) {
            $this->metrics->record('planning.validation_fail', 1, [], $traceId, $request->context->siteId);
            $this->traces->endSpan($traceId, $spanId, 'error', [], $e::class);
            $this->traces->finishTrace($traceId, 'error');
            throw $e;
        }
    }

    public function editPlan(AgentPlanningRequest $request, AgentProposedPlan $plan, array $edits): array
    {
        return $this->inner->editPlan($request, $plan, $edits);
    }

    public function savePlan(AgentPlanningRequest $request, AgentProposedPlan $plan): array
    {
        return $this->inner->savePlan($request, $plan);
    }

    public function suggestNextActions(AgentPlanningRequest $request, array $resultContext = []): array
    {
        return $this->inner->suggestNextActions($request, $resultContext);
    }
}
