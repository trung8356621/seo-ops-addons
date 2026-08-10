<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentAutomationHealthEvaluator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentQualityGateService;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentEvaluationRun;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentReview;

/**
 * Scoped operations dashboard read model — no Blade raw queries.
 */
final class AgentOperationsDashboardService
{
    public function __construct(
        private readonly AgentMetricAggregator $metrics = new AgentMetricAggregator,
        private readonly AgentReviewService $reviews = new AgentReviewService,
        private readonly AgentGovernancePolicyService $governance = new AgentGovernancePolicyService,
        private readonly AgentAutomationHealthEvaluator $automationHealth = new AgentAutomationHealthEvaluator,
        private readonly AgentQualityGateService $gates = new AgentQualityGateService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(AgentWorkspaceContext $context): array
    {
        if (! $this->governance->canAccessDiagnostics($context->role, $context->scopes)) {
            return ['ok' => false, 'code' => 'forbidden'];
        }

        $metricRows = $this->metrics->snapshot($context->siteId, 7);
        $openReviews = $this->reviews->listOpen($context->siteId, 20);
        $latestEval = SeoAgentEvaluationRun::query()
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (SeoAgentEvaluationRun $r): array => [
                'hash_id' => $r->hash_id,
                'status' => $r->status,
                'gate_status' => $r->gate_status,
                'summary' => $r->summary,
                'finished_at' => optional($r->finished_at)?->toIso8601String(),
            ])->all();

        $policyViolations = SeoAgentReview::query()
            ->where('site_id', $context->siteId)
            ->where('reason', 'policy_violation')
            ->where('created_at', '>=', now()->subDays(7))
            ->count();

        return [
            'ok' => true,
            'metrics' => $metricRows,
            'reviews_open' => $openReviews,
            'evaluations' => $latestEval,
            'policy_violations_7d' => $policyViolations,
            'automation_health' => $this->automationHealth->evaluate([
                'failure_streak' => 0,
                'no_change_streak' => 0,
            ]),
            'gates' => $this->governance->evaluationGates(),
        ];
    }
}
