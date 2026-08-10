<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlan;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAgentPlanStep;
use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentErrorCodes;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\Dtos\AgentPlanDraft;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\RuleBasedContentProjectPlanGenerator;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectMetricKeys;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsMetrics;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrate generator + validator + persist draft plan/steps.
 */
final class ContentProjectAgentPlanner
{
    public function __construct(
        private readonly RuleBasedContentProjectPlanGenerator $generator,
        private readonly ContentProjectCanonicalPlanValidator $validator,
        private readonly ContentProjectAutomationPolicyService $policyService,
        private readonly ContentProjectOpsMetrics $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    public function createDraft(
        AgentExecutionContext $context,
        string $objective,
        array $constraints = [],
        ?array $projectContext = null,
        bool $persist = true,
        ?string $name = null,
    ): array {
        $tenantId = $this->tenantIdFromRef($context->tenantRef);
        $siteId = $context->resolvedSiteId;
        $policy = $this->policyService->resolveForTenant($tenantId, $siteId);

        $draft = $this->generator->generate($context, $objective, $constraints, $projectContext, $policy);

        if ($draft->steps === []) {
            return [
                'success' => false,
                'code' => AgentErrorCodes::PLAN_INVALID_INPUT,
                'message' => implode(' ', $draft->warnings) ?: 'Unable to build plan draft.',
                'data' => $draft->toArray(),
            ];
        }

        $validationErrors = $this->validator->validate($draft->steps, $policy, $policy?->automation_level);
        if ($validationErrors !== []) {
            return [
                'success' => false,
                'code' => (string) ($validationErrors[0]['code'] ?? AgentErrorCodes::PLAN_INVALID_INPUT),
                'message' => (string) ($validationErrors[0]['message'] ?? 'Plan validation failed.'),
                'data' => ['errors' => $validationErrors, 'draft' => $draft->toArray()],
            ];
        }

        if (! $persist) {
            return [
                'success' => true,
                'code' => 'plan.draft_ready',
                'message' => 'Plan draft ready.',
                'data' => $draft->toArray(),
            ];
        }

        $plan = $this->persistDraft($context, $draft, $policy, $name, $constraints);

        $this->metrics->increment(ContentProjectMetricKeys::AGENT_PLAN_CREATED_TOTAL, 1, $siteId);

        return [
            'success' => true,
            'code' => 'plan.created',
            'message' => 'Plan draft saved.',
            'data' => $this->serializePlan($plan),
        ];
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function persistDraft(
        AgentExecutionContext $context,
        AgentPlanDraft $draft,
        ?ContentProjectAutomationPolicy $policy,
        ?string $name,
        array $constraints,
    ): ContentProjectAgentPlan {
        return DB::connection('omi_seo_ai')->transaction(function () use ($context, $draft, $policy, $name, $constraints): ContentProjectAgentPlan {
            $tenantId = $this->tenantIdFromRef($context->tenantRef);

            $plan = ContentProjectAgentPlan::query()->create([
                'public_ref' => 'apl_'.Str::lower((string) Str::ulid()),
                'tenant_id' => $tenantId,
                'site_id' => $context->resolvedSiteId,
                'session_ref' => $context->sessionRef,
                'name' => $name ?? substr($draft->objective, 0, 255),
                'objective' => $draft->objective,
                'status' => $draft->requiresPlanConfirmation ? AgentPlanStatus::PENDING_CONFIRMATION : AgentPlanStatus::DRAFT,
                'trigger_type' => (string) ($constraints['trigger_type'] ?? AgentPlanTriggerType::MANUAL),
                'policy_ref' => $policy !== null ? ContentProjectAgentPlanRef::policy((int) $policy->id) : null,
                'project_ref' => $constraints['project_ref'] ?? null,
                'current_step_index' => 0,
                'total_steps' => count($draft->steps),
                'input_payload' => $constraints,
                'resolved_context' => [
                    'template_key' => $draft->templateKey,
                    'scopes' => array_values($context->scopes),
                    'resolved_actor_user_id' => $context->resolvedActorUserId,
                ],
                'summary' => $draft->estimated,
                'requires_user_confirmation' => $draft->requiresPlanConfirmation,
                'confirmation_status' => $draft->requiresPlanConfirmation ? 'pending' : null,
                'created_by_type' => 'agent',
                'created_by_ref' => $context->actorRef,
                'automation_level' => $policy?->automation_level,
                'expires_at' => now()->addDays(7),
            ]);

            $plan->public_ref = ContentProjectAgentPlanRef::plan((int) $plan->id);
            $plan->save();

            foreach ($draft->steps as $position => $step) {
                $stepModel = ContentProjectAgentPlanStep::query()->create([
                    'public_ref' => 'aps_'.Str::lower((string) Str::ulid()),
                    'plan_id' => $plan->id,
                    'position' => $position,
                    'capability' => $step['capability'] ?? null,
                    'intent' => $step['intent'] ?? null,
                    'input_payload' => $step['input'] ?? [],
                    'status' => AgentPlanStepStatus::PENDING,
                    'step_type' => $step['step_type'] ?? AgentPlanStepType::CAPABILITY,
                    'depends_on_step_refs' => $step['depends_on_step_refs'] ?? [],
                    'condition_payload' => $step['condition_payload'] ?? null,
                    'max_attempts' => (int) config('seo-content-ai.content_project_agent.executor.max_step_retries', 4),
                ]);
                $stepModel->public_ref = ContentProjectAgentPlanRef::step((int) $stepModel->id);
                $stepModel->save();
            }

            return $plan->load('steps');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializePlan(ContentProjectAgentPlan $plan): array
    {
        return [
            'plan_ref' => (string) $plan->public_ref,
            'status' => (string) $plan->status,
            'objective' => (string) $plan->objective,
            'total_steps' => (int) $plan->total_steps,
            'requires_user_confirmation' => (bool) $plan->requires_user_confirmation,
            'steps' => $plan->relationLoaded('steps')
                ? $plan->steps->map(static fn (ContentProjectAgentPlanStep $s): array => [
                    'step_ref' => (string) $s->public_ref,
                    'position' => (int) $s->position,
                    'capability' => $s->capability,
                    'intent' => $s->intent,
                    'status' => (string) $s->status,
                    'step_type' => (string) $s->step_type,
                ])->all()
                : [],
        ];
    }

    private function tenantIdFromRef(string $tenantRef): int
    {
        if (preg_match('/(\d+)/', $tenantRef, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
