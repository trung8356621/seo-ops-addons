<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\Dtos;

/**
 * @phpstan-type PlanStepDraft array{
 *     capability?: string,
 *     intent?: string,
 *     input?: array<string, mixed>,
 *     step_type?: string,
 *     depends_on_step_refs?: list<string>,
 *     condition_payload?: array<string, mixed>
 * }
 */
final class AgentPlanDraft
{
    /**
     * @param  list<PlanStepDraft>  $steps
     * @param  list<string>  $warnings
     * @param  list<string>  $missingInputs
     * @param  array<string, mixed>  $estimated
     */
    public function __construct(
        public readonly string $objective,
        public readonly array $steps,
        public readonly array $warnings = [],
        public readonly array $missingInputs = [],
        public readonly array $estimated = [],
        public readonly bool $requiresPlanConfirmation = false,
        public readonly ?string $templateKey = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'objective' => $this->objective,
            'steps' => $this->steps,
            'warnings' => $this->warnings,
            'missing_inputs' => $this->missingInputs,
            'estimated' => $this->estimated,
            'requires_plan_confirmation' => $this->requiresPlanConfirmation,
            'template_key' => $this->templateKey,
        ];
    }
}
