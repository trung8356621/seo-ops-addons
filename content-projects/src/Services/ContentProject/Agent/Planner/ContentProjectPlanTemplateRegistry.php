<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\Dtos\AgentPlanDraft;

/**
 * Built-in plan templates — no keyword discovery.
 */
final class ContentProjectPlanTemplateRegistry
{
    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    public function build(
        string $templateKey,
        AgentExecutionContext $context,
        string $objective,
        array $constraints = [],
        ?array $projectContext = null,
    ): ?AgentPlanDraft {
        return match ($templateKey) {
            'generate_new_content_project' => $this->generateNewContentProject($objective, $constraints),
            'generate_only' => $this->generateOnly($objective, $constraints, $projectContext),
            'review_existing' => $this->reviewExisting($objective, $constraints, $projectContext),
            'schedule_approved' => $this->scheduleApproved($objective, $constraints, $projectContext),
            'publish_due_check' => $this->publishDueCheck($objective, $constraints, $projectContext),
            'restore_and_rebuild' => $this->restoreAndRebuild($objective, $constraints, $projectContext),
            default => null,
        };
    }

    /** @return list<string> */
    public function keys(): array
    {
        return [
            'generate_new_content_project',
            'generate_only',
            'review_existing',
            'schedule_approved',
            'publish_due_check',
            'restore_and_rebuild',
        ];
    }

    /**
     * @param  array<string, mixed>  $constraints
     */
    private function generateNewContentProject(string $objective, array $constraints): AgentPlanDraft
    {
        $itemSeed = $constraints['item_seed'] ?? null;
        $warnings = [];
        $missing = [];

        if (! is_array($itemSeed) || $itemSeed === []) {
            $missing[] = 'constraints.item_seed';
        }

        $steps = [
            [
                'capability' => 'content_project.create',
                'intent' => 'Create content project',
                'input' => [
                    'attributes' => $constraints['attributes'] ?? ['name' => $objective],
                    'tasksData' => is_array($itemSeed) ? $itemSeed : [],
                ],
            ],
            [
                'capability' => 'content_project.generate',
                'intent' => 'Generate items',
                'input' => ['project_ref' => '{{step:0:project_ref}}'],
            ],
            [
                'step_type' => AgentPlanStepType::WAIT_OPERATION,
                'intent' => 'Wait for generation',
                'condition_payload' => ['condition' => 'operation_completed'],
            ],
            [
                'capability' => 'content_project.start_review',
                'intent' => 'Start review',
                'input' => ['project_ref' => '{{step:0:project_ref}}'],
            ],
        ];

        return new AgentPlanDraft(
            objective: $objective,
            steps: $steps,
            warnings: $warnings,
            missingInputs: $missing,
            estimated: ['step_count' => count($steps)],
            requiresPlanConfirmation: true,
            templateKey: 'generate_new_content_project',
        );
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    private function generateOnly(string $objective, array $constraints, ?array $projectContext): AgentPlanDraft
    {
        $projectRef = (string) ($constraints['project_ref'] ?? $projectContext['project_ref'] ?? '');
        $missing = $projectRef === '' ? ['project_ref'] : [];

        return new AgentPlanDraft(
            objective: $objective,
            steps: [
                [
                    'capability' => 'content_project.generate',
                    'intent' => 'Generate project items',
                    'input' => array_filter(['project_ref' => $projectRef, 'item_refs' => $constraints['item_refs'] ?? null]),
                ],
                [
                    'step_type' => AgentPlanStepType::WAIT_OPERATION,
                    'intent' => 'Wait for generation',
                    'condition_payload' => ['condition' => 'operation_completed'],
                ],
            ],
            missingInputs: $missing,
            estimated: ['step_count' => 2],
            templateKey: 'generate_only',
        );
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    private function reviewExisting(string $objective, array $constraints, ?array $projectContext): AgentPlanDraft
    {
        $projectRef = (string) ($constraints['project_ref'] ?? $projectContext['project_ref'] ?? '');

        return new AgentPlanDraft(
            objective: $objective,
            steps: [
                [
                    'capability' => 'content_project.get_status',
                    'intent' => 'Check project status',
                    'input' => ['project_ref' => $projectRef],
                ],
                [
                    'step_type' => AgentPlanStepType::WAIT_CONDITION,
                    'intent' => 'Wait until all items generated',
                    'condition_payload' => ['condition' => 'all_items_generated', 'project_ref' => $projectRef],
                ],
                [
                    'capability' => 'content_project.start_review',
                    'intent' => 'Start review',
                    'input' => ['project_ref' => $projectRef],
                ],
            ],
            missingInputs: $projectRef === '' ? ['project_ref'] : [],
            estimated: ['step_count' => 3],
            templateKey: 'review_existing',
        );
    }

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    private function scheduleApproved(string $objective, array $constraints, ?array $projectContext): AgentPlanDraft
    {
        $projectRef = (string) ($constraints['project_ref'] ?? $projectContext['project_ref'] ?? '');

        return new AgentPlanDraft(
            objective: $objective,
            steps: [
                [
                    'step_type' => AgentPlanStepType::WAIT_CONDITION,
                    'intent' => 'Wait until items approved',
                    'condition_payload' => ['condition' => 'all_items_approved', 'project_ref' => $projectRef],
                ],
                [
                    'capability' => 'content_project.auto_schedule',
                    'intent' => 'Auto-schedule approved items',
                    'input' => ['project_ref' => $projectRef],
                ],
            ],
            missingInputs: $projectRef === '' ? ['project_ref'] : [],
            estimated: ['step_count' => 2],
            templateKey: 'schedule_approved',
        );
    }

    /**
     * Readiness check only — no publish_now loop.
     *
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    private function publishDueCheck(string $objective, array $constraints, ?array $projectContext): AgentPlanDraft
    {
        $projectRef = (string) ($constraints['project_ref'] ?? $projectContext['project_ref'] ?? '');

        return new AgentPlanDraft(
            objective: $objective,
            steps: [
                [
                    'capability' => 'content_project.get_publishing_queue',
                    'intent' => 'Inspect publishing queue',
                    'input' => ['project_ref' => $projectRef],
                ],
                [
                    'step_type' => AgentPlanStepType::WAIT_CONDITION,
                    'intent' => 'Wait until schedule reached',
                    'condition_payload' => ['condition' => 'schedule_reached', 'project_ref' => $projectRef],
                ],
            ],
            missingInputs: $projectRef === '' ? ['project_ref'] : [],
            estimated: ['step_count' => 2],
            requiresPlanConfirmation: true,
            templateKey: 'publish_due_check',
        );
    }

    /**
     * Restore + generate as separate steps; restore requires confirmation.
     *
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    private function restoreAndRebuild(string $objective, array $constraints, ?array $projectContext): AgentPlanDraft
    {
        $projectRef = (string) ($constraints['project_ref'] ?? $projectContext['project_ref'] ?? '');

        return new AgentPlanDraft(
            objective: $objective,
            steps: [
                [
                    'capability' => 'content_project.restore',
                    'intent' => 'Restore archived project',
                    'input' => ['project_ref' => $projectRef],
                ],
                [
                    'capability' => 'content_project.generate',
                    'intent' => 'Regenerate items after restore',
                    'input' => ['project_ref' => $projectRef],
                ],
            ],
            missingInputs: $projectRef === '' ? ['project_ref'] : [],
            estimated: ['step_count' => 2],
            requiresPlanConfirmation: true,
            templateKey: 'restore_and_rebuild',
        );
    }
}
