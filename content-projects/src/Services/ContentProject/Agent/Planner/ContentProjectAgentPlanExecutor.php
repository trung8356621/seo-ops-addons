<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Jobs\ExecuteContentProjectAgentPlanStepJob;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlan;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlanStep;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentCapabilityResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentScopeEvaluator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentGateway;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectMetricKeys;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsMetrics;
use Illuminate\Support\Facades\DB;

/**
 * Executes ONE plan step per invoke — business only via AgentGateway::execute().
 */
final class ContentProjectAgentPlanExecutor
{
    /** @var list<string> */
    private const NON_RETRYABLE = [
        AgentErrorCodes::AUTHENTICATION_FAILED,
        AgentErrorCodes::PERMISSION_DENIED,
        AgentErrorCodes::INVALID_INPUT,
        AgentErrorCodes::CAPABILITY_NOT_FOUND,
        AgentErrorCodes::CAPABILITY_NOT_ALLOWED,
        AgentErrorCodes::TENANT_ACCESS_DENIED,
        AgentErrorCodes::CONFIRMATION_REQUIRED,
        AgentErrorCodes::CONFIRMATION_INVALID,
        AgentErrorCodes::CONFIRMATION_EXPIRED,
        AgentErrorCodes::LIFECYCLE_INVALID_TRANSITION,
        AgentErrorCodes::QUOTA_EXCEEDED,
        AgentErrorCodes::PLAN_INVALID_CAPABILITY,
        AgentErrorCodes::PLAN_UNSAFE_STEP,
        AgentErrorCodes::PLAN_POLICY_DENIED,
        AgentErrorCodes::BUDGET_EXCEEDED,
        AgentErrorCodes::APPROVAL_REQUIRED,
    ];

    public function __construct(
        private readonly ContentProjectAgentGateway $gateway,
        private readonly ContentProjectAgentPlanLock $lock,
        private readonly ContentProjectAgentPlanRevalidator $revalidator,
        private readonly ContentProjectAutomationPolicyService $policyService,
        private readonly ContentProjectAgentConditionRegistry $conditions,
        private readonly ContentProjectAgentApprovalService $approvals,
        private readonly ContentProjectAgentBudgetGuard $budgetGuard,
        private readonly ContentProjectOpsMetrics $metrics,
        private readonly AgentScopeEvaluator $scopeEvaluator,
    ) {}

    public function processNext(string $planRef): void
    {
        $lock = $this->lock->acquire($planRef);
        if ($lock === null) {
            return;
        }

        try {
            $this->processNextLocked($planRef);
        } finally {
            $this->lock->release($lock);
        }
    }

    private function processNextLocked(string $planRef): void
    {
        $plan = ContentProjectAgentPlan::query()->where('public_ref', $planRef)->with('steps')->first();
        if (! $plan instanceof ContentProjectAgentPlan) {
            return;
        }

        if ((string) $plan->status !== AgentPlanStatus::RUNNING) {
            return;
        }

        $step = $this->nextRunnableStep($plan);
        if ($step === null) {
            $this->completePlanIfDone($plan);

            return;
        }

        $context = $this->buildContext($plan);
        $policy = $plan->policy_ref !== null ? $this->policyService->findByRef((string) $plan->policy_ref) : null;

        $revalidation = $this->revalidator->revalidate($plan, $step, $context, $policy);
        if (! $revalidation['ok']) {
            $this->failStep($plan, $step, (string) ($revalidation['code'] ?? AgentErrorCodes::INTERNAL_ERROR), (string) ($revalidation['message'] ?? 'Revalidation failed.'));

            return;
        }

        $stepType = (string) $step->step_type;

        if ($stepType === AgentPlanStepType::WAIT_OPERATION) {
            $this->processWaitOperation($plan, $step, $context);

            return;
        }

        if ($stepType === AgentPlanStepType::WAIT_CONDITION) {
            $this->processWaitCondition($plan, $step, $context);

            return;
        }

        $capability = (string) ($step->capability ?? '');
        if ($capability === '') {
            $this->failStep($plan, $step, AgentErrorCodes::PLAN_INVALID_INPUT, 'Missing capability.');

            return;
        }

        if ($policy !== null && $this->policyService->requiresConfirmation($policy, $capability)) {
            if ($step->status !== AgentPlanStepStatus::AWAITING_APPROVAL) {
                $this->requestApproval($plan, $step, $capability, $context);

                return;
            }
        }

        $step->status = AgentPlanStepStatus::RUNNING;
        $step->started_at = $step->started_at ?? now();
        $step->attempt_count = (int) $step->attempt_count + 1;
        $step->save();

        $idempotencyKey = 'plan:'.$plan->public_ref.':step:'.$step->public_ref;
        $input = $this->resolveStepInput($plan, $step);

        $execContext = new AgentExecutionContext(
            actorRef: $context->actorRef,
            actorType: $context->actorType,
            tenantRef: $context->tenantRef,
            siteRef: $context->siteRef,
            requestRef: $context->requestRef,
            sessionRef: $context->sessionRef,
            idempotencyKey: $idempotencyKey,
            confirmationToken: $step->confirmation_token_ref,
            dryRun: $context->dryRun,
            locale: $context->locale,
            timezone: $context->timezone,
            resolvedSiteId: $context->resolvedSiteId,
            resolvedActorUserId: $context->resolvedActorUserId,
            scopes: $context->scopes,
        );

        $result = $this->gateway->execute($execContext, $capability, $input);

        $this->metrics->increment(ContentProjectMetricKeys::AGENT_STEP_EXECUTED_TOTAL, 1, $plan->site_id !== null ? (int) $plan->site_id : null);
        $this->budgetGuard->increment((int) $plan->tenant_id, $plan->site_id !== null ? (int) $plan->site_id : null);

        if ($result->success) {
            $this->completeStep($plan, $step, $result);
            $this->dispatchNext($plan);

            return;
        }

        if ($this->shouldRetry($result, $step)) {
            $this->scheduleRetry($plan, $step, $result);

            return;
        }

        $this->failStep($plan, $step, $result->code, $result->message);
    }

    private function processWaitOperation(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step, AgentExecutionContext $context): void
    {
        $payload = is_array($step->condition_payload) ? $step->condition_payload : [];
        $operationRef = (string) ($step->operation_ref ?? $payload['operation_ref'] ?? '');

        if ($operationRef === '') {
            $prev = $this->previousStep($plan, $step);
            $operationRef = $prev !== null ? (string) ($prev->operation_ref ?? '') : '';
        }

        if ($operationRef === '') {
            $this->failStep($plan, $step, AgentErrorCodes::OPERATION_NOT_FOUND, 'No operation to wait for.');

            return;
        }

        $result = $this->gateway->execute($context, 'content_project.get_operation', [
            'operation_ref' => $operationRef,
        ]);

        $status = (string) ($result->data['status'] ?? '');
        if ($result->success && in_array($status, ['completed', 'succeeded', 'success'], true)) {
            $step->status = AgentPlanStepStatus::COMPLETED;
            $step->completed_at = now();
            $step->save();
            $plan->current_step_index = (int) $step->position + 1;
            $plan->save();
            $this->dispatchNext($plan);

            return;
        }

        if ($result->success && in_array($status, ['failed', 'error'], true)) {
            $this->failStep($plan, $step, AgentErrorCodes::INTERNAL_ERROR, 'Waited operation failed.');

            return;
        }

        $step->status = AgentPlanStepStatus::WAITING;
        $step->save();
        $this->schedulePoll($plan);
    }

    private function processWaitCondition(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step, AgentExecutionContext $context): void
    {
        $payload = is_array($step->condition_payload) ? $step->condition_payload : [];
        $condition = (string) ($payload['condition'] ?? '');

        if ($this->conditions->evaluate($context, $condition, $payload)) {
            $step->status = AgentPlanStepStatus::COMPLETED;
            $step->completed_at = now();
            $step->save();
            $plan->current_step_index = (int) $step->position + 1;
            $plan->save();
            $this->dispatchNext($plan);

            return;
        }

        $step->status = AgentPlanStepStatus::WAITING;
        $step->save();
        $this->schedulePoll($plan);
    }

    private function requestApproval(
        ContentProjectAgentPlan $plan,
        ContentProjectAgentPlanStep $step,
        string $capability,
        AgentExecutionContext $context,
    ): void {
        $fingerprint = hash('xxh3', $plan->public_ref.'|'.$step->public_ref.'|'.$capability);
        $summary = $capability === 'content_project.archive'
            ? 'Archive project — Destroy Workspace preview required.'
            : 'Confirm '.$capability;

        $this->approvals->create(
            planRef: (string) $plan->public_ref,
            stepRef: (string) $step->public_ref,
            tenantId: (int) $plan->tenant_id,
            siteId: $plan->site_id !== null ? (int) $plan->site_id : null,
            actorRef: $context->actorRef,
            action: $capability,
            summary: $summary,
            riskLevel: str_contains($capability, 'archive') || str_contains($capability, 'restore') ? 'destructive' : 'write',
            previewPayload: ['capability' => $capability, 'destroy_workspace' => str_contains($capability, 'archive')],
            stateFingerprint: $fingerprint,
        );

        $step->status = AgentPlanStepStatus::AWAITING_APPROVAL;
        $step->save();
    }

    private function completeStep(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step, AgentCapabilityResult $result): void
    {
        $step->status = AgentPlanStepStatus::COMPLETED;
        $step->completed_at = now();
        $step->result_code = $result->code;
        $step->result_summary = substr($result->message, 0, 500);
        $step->operation_ref = (string) ($result->data['operation_ref'] ?? $result->meta['operation_ref'] ?? $step->operation_ref);
        if ($plan->project_ref === null && isset($result->data['project_ref'])) {
            $plan->project_ref = (string) $result->data['project_ref'];
        }

        $resolved = is_array($plan->resolved_context) ? $plan->resolved_context : [];
        $outputs = is_array($resolved['step_outputs'] ?? null) ? $resolved['step_outputs'] : [];
        $outputs[(string) $step->position] = array_filter([
            'project_ref' => $result->data['project_ref'] ?? null,
            'operation_ref' => $result->data['operation_ref'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');
        $resolved['step_outputs'] = $outputs;
        $plan->resolved_context = $resolved;

        $step->save();

        $plan->current_step_index = max((int) $plan->current_step_index, (int) $step->position + 1);
        $plan->save();

        $this->completePlanIfDone($plan);
    }

    private function failStep(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step, string $code, string $message): void
    {
        $step->status = AgentPlanStepStatus::FAILED;
        $step->failed_at = now();
        $step->result_code = $code;
        $step->error_summary = substr($message, 0, 500);
        $step->save();

        $plan->status = AgentPlanStatus::FAILED;
        $plan->failed_at = now();
        $plan->save();

        $this->metrics->increment(ContentProjectMetricKeys::AGENT_PLAN_FAILED_TOTAL, 1, $plan->site_id !== null ? (int) $plan->site_id : null);
    }

    private function shouldRetry(AgentCapabilityResult $result, ContentProjectAgentPlanStep $step): bool
    {
        if (in_array($result->code, self::NON_RETRYABLE, true)) {
            return false;
        }

        $max = (int) ($step->max_attempts ?? config('seo-content-ai.content_project_agent.executor.max_step_retries', 4));

        return (int) $step->attempt_count < $max;
    }

    private function scheduleRetry(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step, AgentCapabilityResult $result): void
    {
        $this->metrics->increment(ContentProjectMetricKeys::AGENT_STEP_RETRY_TOTAL, 1, $plan->site_id !== null ? (int) $plan->site_id : null);

        $step->status = AgentPlanStepStatus::PENDING;
        $step->error_summary = substr($result->message, 0, 500);
        $step->save();

        $backoff = config('seo-content-ai.content_project_agent.executor.backoff', [60, 300, 900, 3600]);
        $index = max(0, min(count($backoff) - 1, (int) $step->attempt_count - 1));
        $delay = (int) ($backoff[$index] ?? 60);

        DB::afterCommit(static fn () => ExecuteContentProjectAgentPlanStepJob::dispatch((string) $plan->public_ref)->delay(now()->addSeconds($delay)));
    }

    private function schedulePoll(ContentProjectAgentPlan $plan): void
    {
        $poll = (int) config('seo-content-ai.content_project_agent.executor.poll_min_seconds', 5);
        DB::afterCommit(static fn () => ExecuteContentProjectAgentPlanStepJob::dispatch((string) $plan->public_ref)->delay(now()->addSeconds(max(1, $poll))));
    }

    private function dispatchNext(ContentProjectAgentPlan $plan): void
    {
        if ((string) $plan->status !== AgentPlanStatus::RUNNING) {
            return;
        }

        DB::afterCommit(static fn () => ExecuteContentProjectAgentPlanStepJob::dispatch((string) $plan->public_ref));
    }

    private function completePlanIfDone(ContentProjectAgentPlan $plan): void
    {
        $pending = ContentProjectAgentPlanStep::query()
            ->where('plan_id', $plan->id)
            ->whereNotIn('status', [AgentPlanStepStatus::COMPLETED, AgentPlanStepStatus::SKIPPED, AgentPlanStepStatus::CANCELLED])
            ->exists();

        if ($pending) {
            return;
        }

        $plan->status = AgentPlanStatus::COMPLETED;
        $plan->completed_at = now();
        $plan->save();

        $this->metrics->increment(ContentProjectMetricKeys::AGENT_PLAN_COMPLETED_TOTAL, 1, $plan->site_id !== null ? (int) $plan->site_id : null);
    }

    private function nextRunnableStep(ContentProjectAgentPlan $plan): ?ContentProjectAgentPlanStep
    {
        foreach ($plan->steps as $step) {
            if (! in_array((string) $step->status, [AgentPlanStepStatus::PENDING, AgentPlanStepStatus::WAITING, AgentPlanStepStatus::AWAITING_APPROVAL], true)) {
                continue;
            }

            if (! $this->dependenciesMet($plan, $step)) {
                continue;
            }

            return $step;
        }

        return null;
    }

    private function dependenciesMet(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step): bool
    {
        $deps = is_array($step->depends_on_step_refs) ? $step->depends_on_step_refs : [];
        if ($deps === []) {
            return true;
        }

        foreach ($deps as $depRef) {
            $dep = $plan->steps->firstWhere('public_ref', $depRef);
            if ($dep === null || (string) $dep->status !== AgentPlanStepStatus::COMPLETED) {
                return false;
            }
        }

        return true;
    }

    private function previousStep(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step): ?ContentProjectAgentPlanStep
    {
        $pos = (int) $step->position - 1;
        if ($pos < 0) {
            return null;
        }

        return $plan->steps->firstWhere('position', $pos);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveStepInput(ContentProjectAgentPlan $plan, ContentProjectAgentPlanStep $step): array
    {
        $input = is_array($step->resolved_input) && $step->resolved_input !== []
            ? $step->resolved_input
            : (is_array($step->input_payload) ? $step->input_payload : []);

        $resolved = is_array($plan->resolved_context) ? $plan->resolved_context : [];
        $outputs = is_array($resolved['step_outputs'] ?? null) ? $resolved['step_outputs'] : [];

        $encoded = json_encode($input);
        if (! is_string($encoded)) {
            return $input;
        }

        foreach ($outputs as $position => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as $key => $value) {
                $encoded = str_replace('{{step:'.$position.':'.$key.'}}', (string) $value, $encoded);
            }
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $input;
    }

    private function buildContext(ContentProjectAgentPlan $plan): AgentExecutionContext
    {
        $siteRef = $plan->site_id !== null && (int) $plan->site_id > 0
            ? \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef::site((int) $plan->site_id)
            : '';

        $resolved = is_array($plan->resolved_context) ? $plan->resolved_context : [];
        $scopes = $this->scopeEvaluator->normalizeStoredScopes($resolved['scopes'] ?? []);

        $actorUserId = isset($resolved['resolved_actor_user_id'])
            ? (int) $resolved['resolved_actor_user_id']
            : null;

        return AgentExecutionContext::fromArray([
            'actor_ref' => (string) ($plan->created_by_ref ?? 'agent:plan_executor'),
            'actor_type' => 'agent',
            'tenant_ref' => 'tenant:'.$plan->tenant_id,
            'site_ref' => $siteRef,
            'request_ref' => 'plan-exec:'.(string) $plan->public_ref,
            'session_ref' => $plan->session_ref,
            'resolved_site_id' => $plan->site_id !== null ? (int) $plan->site_id : null,
            'resolved_actor_user_id' => $actorUserId !== null && $actorUserId > 0 ? $actorUserId : null,
            'scopes' => $scopes,
        ]);
    }
}
