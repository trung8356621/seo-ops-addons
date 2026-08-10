<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution;

use Omnichannel\Addons\Agent\Enums\AgentWorkspace\AgentExecutionStatus;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentExecutionPlan;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentConversationService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sequential multi-intent plan runner — no autonomous Run All.
 */
final class AgentPlanStepRunner
{
    public function __construct(
        private readonly AgentExecutionOrchestrator $orchestrator,
        private readonly AgentPlanOutputBinder $binder,
        private readonly AgentConversationService $conversations,
    ) {}

    /**
     * @param  list<array{skill_key: string, title?: string, form_input?: array<string, mixed>}>  $steps
     */
    public function createPlan(
        AgentWorkspaceContext $context,
        SeoAgentConversation $conversation,
        array $steps,
    ): SeoAgentExecutionPlan {
        if ($steps === []) {
            throw new RuntimeException('agent.plan.empty');
        }

        $normalized = [];
        foreach (array_values($steps) as $index => $step) {
            $skillKey = (string) ($step['skill_key'] ?? '');
            if ($skillKey === '') {
                throw new RuntimeException('agent.plan.invalid_step');
            }
            $normalized[] = [
                'index' => $index,
                'skill_key' => $skillKey,
                'title' => (string) ($step['title'] ?? $skillKey),
                'status' => 'locked',
                'depends_on' => $index === 0 ? null : $index - 1,
                'execution_ref' => null,
                'form_input' => is_array($step['form_input'] ?? null) ? $step['form_input'] : [],
            ];
        }
        $normalized[0]['status'] = 'ready';

        return SeoAgentExecutionPlan::query()->create([
            'public_ref' => 'aplan_'.Str::lower((string) Str::ulid()),
            'conversation_id' => $conversation->id,
            'site_id' => $context->siteId,
            'created_by' => $context->actorUserId,
            'status' => 'ready',
            'current_step_index' => 0,
            'steps' => $normalized,
            'bindings' => [],
        ]);
    }

    /**
     * Run only the current ready step — never run-all.
     */
    public function runCurrentStep(
        AgentWorkspaceContext $context,
        SeoAgentExecutionPlan $plan,
        array $formInput = [],
    ): AgentExecutionResult {
        $this->assertPlanScope($context, $plan);
        if (in_array((string) $plan->status, ['cancelled', 'failed', 'succeeded'], true)) {
            throw new RuntimeException('agent.plan.terminal');
        }

        $steps = is_array($plan->steps) ? $plan->steps : [];
        $index = (int) $plan->current_step_index;
        if (! isset($steps[$index])) {
            throw new RuntimeException('agent.plan.step_missing');
        }

        $step = $steps[$index];
        if (($step['status'] ?? '') === 'locked') {
            throw new RuntimeException('agent.plan.step_locked');
        }
        if (($step['status'] ?? '') === 'succeeded') {
            throw new RuntimeException('agent.plan.step_already_done');
        }

        $bindings = is_array($plan->bindings) ? $plan->bindings : [];
        $prior = is_array($bindings['last_output'] ?? null) ? $bindings['last_output'] : [];
        $mergedInput = $this->binder->bind($prior, array_merge(
            is_array($step['form_input'] ?? null) ? $step['form_input'] : [],
            $formInput,
        ));

        $steps[$index]['status'] = 'running';
        $plan->steps = $steps;
        $plan->status = 'running';
        $plan->save();

        $request = new AgentExecutionRequest(
            context: $context,
            conversation: $plan->conversation,
            skillKey: (string) $step['skill_key'],
            formInput: $mergedInput,
            mode: 'plan_step',
            planRef: (string) $plan->public_ref,
            stepIndex: $index,
        );

        $result = $this->orchestrator->execute($request);

        // Attach plan_id on execution if possible.
        if ($result->executionRef !== '') {
            $execution = \Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentExecution::query()
                ->where('public_ref', $result->executionRef)
                ->first();
            if ($execution !== null) {
                $execution->plan_id = $plan->id;
                $execution->step_index = $index;
                $execution->save();
            }
        }

        $steps = is_array($plan->steps) ? $plan->steps : [];
        $steps[$index]['execution_ref'] = $result->executionRef;

        if ($result->status === AgentExecutionStatus::AwaitingConfirmation
            || ($result->code === 'confirmation_required')) {
            $steps[$index]['status'] = 'awaiting_confirmation';
            $plan->steps = $steps;
            $plan->status = 'awaiting_confirmation';
            $plan->save();
            $this->publishPlanMessage($context, $plan, 'Plan step cần xác nhận.');

            return $result;
        }

        if (! $result->ok) {
            $steps[$index]['status'] = 'failed';
            $plan->steps = $steps;
            $plan->status = 'failed';
            $plan->save();
            $this->publishPlanMessage($context, $plan, 'Plan dừng vì step thất bại.');

            return $result;
        }

        $steps[$index]['status'] = 'succeeded';
        $bindings['last_output'] = $this->binder->bind($result->data, []);
        $next = $index + 1;
        if (isset($steps[$next])) {
            $steps[$next]['status'] = 'ready';
            // Keep later steps locked.
            for ($i = $next + 1, $n = count($steps); $i < $n; $i++) {
                $steps[$i]['status'] = 'locked';
            }
            $plan->current_step_index = $next;
            $plan->status = 'ready';
        } else {
            $plan->status = 'succeeded';
        }

        $plan->steps = $steps;
        $plan->bindings = $bindings;
        $plan->save();
        $this->publishPlanMessage($context, $plan, 'Plan step hoàn tất.');

        return $result;
    }

    public function cancelPlan(AgentWorkspaceContext $context, SeoAgentExecutionPlan $plan): SeoAgentExecutionPlan
    {
        $this->assertPlanScope($context, $plan);
        if (in_array((string) $plan->status, ['succeeded', 'cancelled'], true)) {
            return $plan;
        }

        $plan->status = 'cancelled';
        $plan->cancelled_at = now();
        $plan->save();

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    public function present(SeoAgentExecutionPlan $plan): array
    {
        $steps = is_array($plan->steps) ? $plan->steps : [];

        return [
            'plan_ref' => $plan->public_ref,
            'status' => $plan->status,
            'current_step_index' => (int) $plan->current_step_index,
            'steps' => $steps,
            'can_run_all' => false,
            'bindings' => is_array($plan->bindings) ? array_intersect_key(
                $plan->bindings,
                array_flip(['last_output']),
            ) : [],
        ];
    }

    private function assertPlanScope(AgentWorkspaceContext $context, SeoAgentExecutionPlan $plan): void
    {
        if ((int) $plan->site_id !== (int) $context->siteId) {
            throw new RuntimeException('agent.plan.site_mismatch');
        }
        if ($plan->created_by !== null && (int) $plan->created_by !== (int) $context->actorUserId) {
            throw new RuntimeException('agent.plan.actor_mismatch');
        }
    }

    private function publishPlanMessage(
        AgentWorkspaceContext $context,
        SeoAgentExecutionPlan $plan,
        string $content,
    ): void {
        $this->conversations->appendMessage(
            $plan->conversation,
            role: 'assistant',
            messageType: 'execution_plan',
            content: $content,
            structured: $this->present($plan),
            createdBy: $context->actorUserId,
        );
    }
}
