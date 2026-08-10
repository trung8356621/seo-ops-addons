<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlan;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlanStep;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;

/**
 * Revalidate plan/step before execution — tenant, phase, budget, policy, fingerprint.
 */
final class ContentProjectAgentPlanRevalidator
{
    public function __construct(
        private readonly ContentProjectAutomationPolicyService $policyService,
        private readonly ContentProjectAgentBudgetGuard $budgetGuard,
        private readonly ContentProjectAgentApprovalService $approvals,
        private readonly ContentProjectAgentGateway $gateway,
    ) {}

    /**
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function revalidate(
        ContentProjectAgentPlan $plan,
        ContentProjectAgentPlanStep $step,
        AgentExecutionContext $context,
        ?ContentProjectAutomationPolicy $policy = null,
    ): array {
        if ((int) $plan->tenant_id !== $this->tenantIdFromRef($context->tenantRef)) {
            return ['ok' => false, 'code' => AgentErrorCodes::TENANT_ACCESS_DENIED, 'message' => 'Tenant mismatch.'];
        }

        $budget = $this->budgetGuard->check((int) $plan->tenant_id, $plan->site_id !== null ? (int) $plan->site_id : null);
        if ($budget['status'] === 'exceeded') {
            return ['ok' => false, 'code' => AgentErrorCodes::BUDGET_EXCEEDED, 'message' => $budget['message'] ?? 'Budget exceeded.'];
        }

        if ($plan->project_ref !== null && $plan->project_ref !== '') {
            $status = $this->gateway->execute($context, 'content_project.get_status', [
                'project_ref' => (string) $plan->project_ref,
            ]);
            if (! $status->success) {
                return ['ok' => false, 'code' => $status->code, 'message' => $status->message];
            }
        }

        $capability = (string) ($step->capability ?? '');
        if ($capability !== '' && $policy !== null && ! $this->policyService->isCapabilityAllowed($policy, $capability)) {
            return ['ok' => false, 'code' => AgentErrorCodes::PLAN_POLICY_DENIED, 'message' => 'Capability blocked by policy.'];
        }

        if ($step->status === AgentPlanStepStatus::AWAITING_APPROVAL) {
            $pending = $this->approvals->findPendingForStep((string) $plan->public_ref, (string) $step->public_ref);
            if ($pending === null) {
                return ['ok' => false, 'code' => AgentErrorCodes::APPROVAL_REQUIRED, 'message' => 'Approval required.'];
            }
        }

        return ['ok' => true];
    }

    private function tenantIdFromRef(string $tenantRef): int
    {
        if (preg_match('/(\d+)/', $tenantRef, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
