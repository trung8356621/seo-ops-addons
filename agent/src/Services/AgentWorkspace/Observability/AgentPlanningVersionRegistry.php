<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

/**
 * Version snapshot for planning/evaluation traces — no raw prompts.
 */
final class AgentPlanningVersionRegistry
{
    public function __construct(
        private readonly string $plannerVersion = 'phase3-planning-v1',
        private readonly string $promptTemplateVersion = 'agent-plan-template-v1',
        private readonly string $schemaVersion = 'agent-planning-response-v1',
        private readonly string $skillCatalogVersion = 'builtin-catalog-v1',
        private readonly string $contextAssemblerVersion = 'assembler-v1',
        private readonly string $validatorVersion = 'plan-validator-v1',
        private readonly ?string $deploymentRef = null,
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function snapshot(?string $provider = null, ?string $model = null): array
    {
        return [
            'planner_version' => $this->plannerVersion,
            'prompt_template_version' => $this->promptTemplateVersion,
            'schema_version' => $this->schemaVersion,
            'skill_catalog_version' => $this->skillCatalogVersion,
            'context_assembler_version' => $this->contextAssemblerVersion,
            'validator_version' => $this->validatorVersion,
            'provider' => $provider,
            'model' => $model,
            'deployment_ref' => $this->deploymentRef ?? (string) (config('app.version') ?? 'unknown'),
        ];
    }
}
