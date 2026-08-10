<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillAvailabilityService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceQuotaService;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\AgentPlanOutputBinder;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Contracts\AgentPlanValidator;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningRequest;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningValidationResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentProposedPlanStep;

final class DefaultAgentPlanValidator implements AgentPlanValidator
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentSkillAvailabilityService $availability,
        private readonly AgentWorkspaceQuotaService $quotas,
    ) {}

    public function validate(
        AgentPlanningResponse $response,
        AgentPlanningRequest $request,
        AgentWorkspaceContext $context,
    ): AgentPlanningValidationResult {
        $errors = [];
        $adjusted = $response->confidence;

        if (! in_array($response->type, AgentPlanningResponse::ALLOWED_TYPES, true)) {
            $errors[] = 'invalid_response_type';
        }
        if ($response->confidence < 0.0 || $response->confidence > 1.0) {
            $errors[] = 'confidence_out_of_range';
        }

        if ($response->type === AgentPlanningResponse::TYPE_SINGLE_INTENT) {
            if ($response->intent === null || $response->intent->skillKey === '') {
                $errors[] = 'missing_intent';
            } else {
                $errors = array_merge($errors, $this->validateSkill(
                    $response->intent->skillKey,
                    $response->intent->input,
                    $context,
                ));
            }
        }

        if ($response->type === AgentPlanningResponse::TYPE_EXECUTION_PLAN) {
            if ($response->plan === null || $response->plan->steps === []) {
                $errors[] = 'missing_plan_steps';
            } else {
                $errors = array_merge($errors, $this->validatePlan($response->plan->steps, $context));
            }
        }

        if ($response->type === AgentPlanningResponse::TYPE_CLARIFICATION) {
            if ($response->clarifyingQuestions === []) {
                $errors[] = 'missing_clarifying_questions';
            }
        }

        if ($response->assumptions !== []) {
            $adjusted = max(0.0, $adjusted - 0.05 * min(3, count($response->assumptions)));
        }

        foreach ($response->suggestedSkills as $row) {
            $key = (string) ($row['skill_key'] ?? '');
            if ($key !== '' && $this->skills->get($key) === null) {
                $errors[] = 'suggested_skill_unknown:'.$key;
            }
        }

        return new AgentPlanningValidationResult(
            ok: $errors === [],
            response: $response,
            errors: array_values(array_unique($errors)),
            adjustedConfidence: $adjusted,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    private function validateSkill(string $skillKey, array $input, AgentWorkspaceContext $context): array
    {
        $errors = [];
        if (str_starts_with($skillKey, 'internal.') || str_contains($skillKey, '::')) {
            $errors[] = 'internal_skill_forbidden';

            return $errors;
        }

        $skill = $this->skills->get($skillKey);
        if ($skill === null) {
            $errors[] = 'unknown_skill:'.$skillKey;

            return $errors;
        }
        if ($skill->isHidden) {
            $errors[] = 'hidden_skill:'.$skillKey;
        }

        $avail = $this->availability->resolve($skill, $context->toAvailabilityContext());
        if (! $avail->usable) {
            $errors[] = 'skill_unavailable:'.$skillKey;
        }

        $allowedFields = [];
        foreach ($skill->formSchema as $field) {
            $fk = (string) ($field['key'] ?? '');
            if ($fk !== '') {
                $allowedFields[$fk] = true;
            }
        }
        foreach (array_keys($input) as $fieldKey) {
            if (! is_string($fieldKey)) {
                continue;
            }
            if (in_array($fieldKey, ['site_ref', 'tenant_ref', 'site_id', 'api_key', 'confirmation_token'], true)) {
                $errors[] = 'forbidden_input_field:'.$fieldKey;
                continue;
            }
            if ($allowedFields !== [] && ! isset($allowedFields[$fieldKey])) {
                // soft: unknown fields rejected
                $errors[] = 'extra_input_field:'.$fieldKey;
            }
        }

        foreach (['site_ref', 'project_ref', 'workspace_ref'] as $refKey) {
            if (! isset($input[$refKey]) || ! is_string($input[$refKey])) {
                continue;
            }
            if ($refKey === 'site_ref' && $input[$refKey] !== '' && $input[$refKey] !== $context->siteRef) {
                $errors[] = 'cross_site_reference';
            }
        }

        return $errors;
    }

    /**
     * @param  list<AgentProposedPlanStep>  $steps
     * @return list<string>
     */
    private function validatePlan(array $steps, AgentWorkspaceContext $context): array
    {
        $errors = [];
        $max = $this->quotas->maxMultiStepPlanActions();
        if (count($steps) > $max) {
            $errors[] = 'too_many_steps';
        }

        $indexes = [];
        foreach ($steps as $step) {
            if ($step->index < 1) {
                $errors[] = 'invalid_step_index';
            }
            if (isset($indexes[$step->index])) {
                $errors[] = 'duplicate_step_index';
            }
            $indexes[$step->index] = true;
            $errors = array_merge($errors, $this->validateSkill($step->skillKey, $step->input, $context));

            foreach ($step->dependsOn as $dep) {
                if ($dep >= $step->index) {
                    $errors[] = 'future_dependency';
                }
                if ($dep < 1) {
                    $errors[] = 'invalid_dependency';
                }
            }

            foreach ($step->outputBindings as $bindKey => $bindVal) {
                if (! in_array($bindKey, AgentPlanOutputBinder::ALLOWED_KEYS, true)) {
                    $errors[] = 'output_binding_forbidden:'.$bindKey;
                }
            }
        }

        // cycle check: depends_on graph among indexes
        foreach ($steps as $step) {
            $seen = [];
            $stack = $step->dependsOn;
            while ($stack !== []) {
                $cur = array_pop($stack);
                if (isset($seen[$cur])) {
                    $errors[] = 'plan_cycle';
                    break 2;
                }
                $seen[$cur] = true;
                foreach ($steps as $other) {
                    if ($other->index === $cur) {
                        foreach ($other->dependsOn as $d) {
                            $stack[] = $d;
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
