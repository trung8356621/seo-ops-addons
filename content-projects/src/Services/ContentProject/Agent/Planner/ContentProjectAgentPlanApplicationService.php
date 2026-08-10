<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Jobs\ExecuteContentProjectAgentPlanStepJob;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlan;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlanStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Illuminate\Support\Facades\DB;

/**
 * Plan lifecycle — confirm, start, pause, resume, cancel, retry, list/get.
 */
final class ContentProjectAgentPlanApplicationService
{
    public function __construct(
        private readonly ContentProjectAgentPlanner $planner,
        private readonly ContentProjectAgentApprovalService $approvals,
    ) {}

    /**
     * @param  array<string, mixed>  $constraints
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function createDraft(AgentExecutionContext $context, string $objective, array $constraints = []): array
    {
        return $this->planner->createDraft($context, $objective, $constraints, persist: true);
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function confirmPlan(AgentExecutionContext $context, string $planRef): array
    {
        $plan = $this->findPlanForTenant($planRef, $context);
        if ($plan === null) {
            return $this->notFound();
        }

        if (! in_array((string) $plan->status, [AgentPlanStatus::DRAFT, AgentPlanStatus::PENDING_CONFIRMATION], true)) {
            return ['success' => false, 'code' => AgentErrorCodes::PLAN_INVALID_STATE, 'message' => 'Plan cannot be confirmed.'];
        }

        $plan->status = AgentPlanStatus::CONFIRMED;
        $plan->confirmation_status = 'confirmed';
        $plan->save();

        return ['success' => true, 'code' => 'plan.confirmed', 'message' => 'Plan confirmed.', 'data' => $this->planner->serializePlan($plan)];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function startPlan(AgentExecutionContext $context, string $planRef): array
    {
        $plan = $this->findPlanForTenant($planRef, $context);
        if ($plan === null) {
            return $this->notFound();
        }

        if (! in_array((string) $plan->status, [AgentPlanStatus::CONFIRMED, AgentPlanStatus::DRAFT], true)) {
            return ['success' => false, 'code' => AgentErrorCodes::PLAN_INVALID_STATE, 'message' => 'Plan cannot be started.'];
        }

        if ($plan->requires_user_confirmation && (string) $plan->confirmation_status !== 'confirmed') {
            return ['success' => false, 'code' => AgentErrorCodes::CONFIRMATION_REQUIRED, 'message' => 'Plan confirmation required.'];
        }

        $plan->status = AgentPlanStatus::RUNNING;
        $plan->started_at = now();
        $plan->save();

        DB::afterCommit(static fn () => ExecuteContentProjectAgentPlanStepJob::dispatch((string) $plan->public_ref));

        return ['success' => true, 'code' => 'plan.started', 'message' => 'Plan started.', 'data' => $this->planner->serializePlan($plan)];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function pausePlan(AgentExecutionContext $context, string $planRef): array
    {
        $plan = $this->findPlanForTenant($planRef, $context);
        if ($plan === null) {
            return $this->notFound();
        }

        if ((string) $plan->status !== AgentPlanStatus::RUNNING) {
            return ['success' => false, 'code' => AgentErrorCodes::PLAN_INVALID_STATE, 'message' => 'Plan is not running.'];
        }

        $plan->status = AgentPlanStatus::PAUSED;
        $plan->save();

        return ['success' => true, 'code' => 'plan.paused', 'message' => 'Plan paused.', 'data' => $this->planner->serializePlan($plan)];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function resumePlan(AgentExecutionContext $context, string $planRef): array
    {
        $plan = $this->findPlanForTenant($planRef, $context);
        if ($plan === null) {
            return $this->notFound();
        }

        if ((string) $plan->status !== AgentPlanStatus::PAUSED) {
            return ['success' => false, 'code' => AgentErrorCodes::PLAN_INVALID_STATE, 'message' => 'Plan is not paused.'];
        }

        $plan->status = AgentPlanStatus::RUNNING;
        $plan->save();

        DB::afterCommit(static fn () => ExecuteContentProjectAgentPlanStepJob::dispatch((string) $plan->public_ref));

        return ['success' => true, 'code' => 'plan.resumed', 'message' => 'Plan resumed.', 'data' => $this->planner->serializePlan($plan)];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function cancelPlan(AgentExecutionContext $context, string $planRef): array
    {
        $plan = $this->findPlanForTenant($planRef, $context);
        if ($plan === null) {
            return $this->notFound();
        }

        if (in_array((string) $plan->status, AgentPlanStatus::terminal(), true)) {
            return ['success' => false, 'code' => AgentErrorCodes::PLAN_INVALID_STATE, 'message' => 'Plan already terminal.'];
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($plan): void {
            $plan->status = AgentPlanStatus::CANCELLED;
            $plan->cancelled_at = now();
            $plan->save();

            ContentProjectAgentPlanStep::query()
                ->where('plan_id', $plan->id)
                ->whereIn('status', [AgentPlanStepStatus::PENDING, AgentPlanStepStatus::WAITING, AgentPlanStepStatus::AWAITING_APPROVAL])
                ->update(['status' => AgentPlanStepStatus::CANCELLED]);

            \Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentApproval::query()
                ->where('plan_ref', $plan->public_ref)
                ->where('status', AgentApprovalStatus::PENDING)
                ->update(['status' => AgentApprovalStatus::CANCELLED]);
        });

        return ['success' => true, 'code' => 'plan.cancelled', 'message' => 'Plan cancelled.', 'data' => $this->planner->serializePlan($plan->fresh('steps') ?? $plan)];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function retryStep(AgentExecutionContext $context, string $planRef, string $stepRef): array
    {
        $plan = $this->findPlanForTenant($planRef, $context);
        if ($plan === null) {
            return $this->notFound();
        }

        $step = ContentProjectAgentPlanStep::query()
            ->where('plan_id', $plan->id)
            ->where('public_ref', $stepRef)
            ->first();

        if (! $step instanceof ContentProjectAgentPlanStep) {
            return $this->notFound('Step not found.');
        }

        if ((string) $step->status !== AgentPlanStepStatus::FAILED) {
            return ['success' => false, 'code' => AgentErrorCodes::PLAN_INVALID_STATE, 'message' => 'Step is not failed.'];
        }

        $step->status = AgentPlanStepStatus::PENDING;
        $step->failed_at = null;
        $step->error_summary = null;
        $step->save();

        $plan->status = AgentPlanStatus::RUNNING;
        $plan->save();

        DB::afterCommit(static fn () => ExecuteContentProjectAgentPlanStepJob::dispatch((string) $plan->public_ref));

        return ['success' => true, 'code' => 'plan.step_retry_scheduled', 'message' => 'Step retry scheduled.'];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function getPlan(AgentExecutionContext $context, string $planRef): array
    {
        $plan = $this->findPlanForTenant($planRef, $context);
        if ($plan === null) {
            return $this->notFound();
        }

        return ['success' => true, 'code' => 'plan.found', 'message' => 'Plan found.', 'data' => $this->planner->serializePlan($plan->load('steps'))];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function listPlans(AgentExecutionContext $context, int $limit = 20): array
    {
        $tenantId = $this->tenantIdFromRef($context->tenantRef);
        $plans = ContentProjectAgentPlan::query()
            ->where('tenant_id', $tenantId)
            ->when($context->resolvedSiteId, fn ($q, $siteId) => $q->where('site_id', $siteId))
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get();

        return [
            'success' => true,
            'code' => 'plan.list',
            'message' => 'Plans listed.',
            'data' => [
                'plans' => $plans->map(fn (ContentProjectAgentPlan $p) => $this->planner->serializePlan($p))->all(),
            ],
        ];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function approve(AgentExecutionContext $context, string $approvalRef, string $fingerprint): array
    {
        $tenantId = $this->tenantIdFromRef($context->tenantRef);
        $approval = $this->approvals->approve($approvalRef, $context->actorRef, $tenantId, $fingerprint);
        if ($approval === null) {
            return ['success' => false, 'code' => AgentErrorCodes::APPROVAL_INVALID, 'message' => 'Approval invalid or expired.'];
        }

        if ($approval->step_ref !== null) {
            ContentProjectAgentPlanStep::query()
                ->where('public_ref', $approval->step_ref)
                ->update(['status' => AgentPlanStepStatus::PENDING]);
        }

        DB::afterCommit(static fn () => ExecuteContentProjectAgentPlanStepJob::dispatch($approval->plan_ref));

        return ['success' => true, 'code' => 'approval.approved', 'message' => 'Approval granted.'];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function rejectApproval(AgentExecutionContext $context, string $approvalRef): array
    {
        $tenantId = $this->tenantIdFromRef($context->tenantRef);
        $approval = $this->approvals->reject($approvalRef, $context->actorRef, $tenantId);
        if ($approval === null) {
            return ['success' => false, 'code' => AgentErrorCodes::APPROVAL_INVALID, 'message' => 'Approval invalid.'];
        }

        return ['success' => true, 'code' => 'approval.rejected', 'message' => 'Approval rejected.'];
    }

    /**
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function listApprovals(AgentExecutionContext $context): array
    {
        $tenantId = $this->tenantIdFromRef($context->tenantRef);
        $rows = $this->approvals->listPending($tenantId, $context->resolvedSiteId);

        return [
            'success' => true,
            'code' => 'approval.list',
            'message' => 'Pending approvals.',
            'data' => [
                'approvals' => array_map(static fn ($a): array => [
                    'approval_ref' => (string) $a->public_ref,
                    'plan_ref' => (string) $a->plan_ref,
                    'step_ref' => $a->step_ref,
                    'action' => (string) $a->action,
                    'summary' => (string) $a->summary,
                    'risk_level' => (string) $a->risk_level,
                    'expires_at' => $a->expires_at?->toIso8601String(),
                ], $rows),
            ],
        ];
    }

    private function findPlanForTenant(string $planRef, AgentExecutionContext $context): ?ContentProjectAgentPlan
    {
        try {
            $id = ContentProjectAgentPlanRef::decodePlan($planRef);
        } catch (\Throwable) {
            return null;
        }

        $tenantId = $this->tenantIdFromRef($context->tenantRef);

        return ContentProjectAgentPlan::query()
            ->where('id', $id)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    /**
     * @return array{success: bool, code: string, message: string}
     */
    private function notFound(string $message = 'Plan not found.'): array
    {
        return ['success' => false, 'code' => AgentErrorCodes::PLAN_NOT_FOUND, 'message' => $message];
    }

    private function tenantIdFromRef(string $tenantRef): int
    {
        if (preg_match('/(\d+)/', $tenantRef, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
