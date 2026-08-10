<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Data;

final readonly class AgentAutomationPreview
{
    /**
     * @param  list<string>  $nextRuns
     * @param  list<array<string, mixed>>  $workflowSteps
     * @param  list<string>  $warnings
     * @param  list<string>  $capabilities
     * @param  list<string>  $writeEffects
     * @param  array<string, mixed>  $quotaEstimate
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $siteRef,
        public string $scopeLabel,
        public array $scheduleSummary,
        public array $nextRuns,
        public array $workflowSteps,
        public ?array $conditionSummary,
        public ?array $notificationSummary,
        public ?array $quietHours,
        public array $permissions,
        public array $capabilities,
        public array $writeEffects,
        public array $quotaEstimate,
        public array $warnings,
        public bool $requiresExplicitSave,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'site_ref' => $this->siteRef,
            'scope' => $this->scopeLabel,
            'schedule' => $this->scheduleSummary,
            'next_runs' => $this->nextRuns,
            'workflow_steps' => $this->workflowSteps,
            'condition' => $this->conditionSummary,
            'notification' => $this->notificationSummary,
            'quiet_hours' => $this->quietHours,
            'permissions' => $this->permissions,
            'capabilities' => $this->capabilities,
            'write_effects' => $this->writeEffects,
            'quota_estimate' => $this->quotaEstimate,
            'warnings' => $this->warnings,
            'requires_explicit_save' => $this->requiresExplicitSave,
        ];
    }
}
