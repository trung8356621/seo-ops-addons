<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;

/**
 * Sanitized governance exports — no secrets/hidden prompts.
 */
final class AgentObservabilityExportService
{
    public function __construct(
        private readonly AgentObservabilityRedactor $redactor = new AgentObservabilityRedactor,
        private readonly AgentMetricAggregator $aggregates = new AgentMetricAggregator,
        private readonly AgentReviewService $reviews = new AgentReviewService,
        private readonly AgentTraceService $traces = new AgentTraceService,
        private readonly AgentGovernancePolicyService $governance = new AgentGovernancePolicyService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function export(AgentWorkspaceContext $context, string $type, array $filters = []): array
    {
        if (! $this->governance->canExport($context->role, $context->scopes)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }

        $payload = match ($type) {
            'metrics' => ['rows' => $this->aggregates->snapshot($context->siteId, (int) ($filters['days'] ?? 7))],
            'reviews' => ['rows' => $this->reviews->listOpen($context->siteId)],
            'trace_summary' => [
                'trace' => isset($filters['trace_id'])
                    ? $this->traces->getTraceTimeline((string) $filters['trace_id'], $context->siteId)
                    : null,
            ],
            'governance_summary' => [
                'gates' => $this->governance->evaluationGates(),
                'retention' => $this->governance->retentionDays(),
                'allowed_models' => $this->governance->allowedModels(),
            ],
            default => ['rows' => []],
        };

        return [
            'ok' => true,
            'type' => $type,
            'schema_version' => 'agent-observability-export-v1',
            'generated_at' => gmdate(DATE_ATOM),
            'generated_by' => $context->actorUserId,
            'site_ref' => $context->siteRef,
            'payload' => $this->redactor->redact($payload),
        ];
    }
}
