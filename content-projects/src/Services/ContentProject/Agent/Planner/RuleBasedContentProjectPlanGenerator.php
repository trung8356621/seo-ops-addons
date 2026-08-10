<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner;

use Omnichannel\Addons\ContentProjects\Models\ContentProjectAutomationPolicy;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\AgentExecutionContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\Planner\Dtos\AgentPlanDraft;

/**
 * Template-based plan generator — uses constraints.item_seed, no keyword invention.
 */
final class RuleBasedContentProjectPlanGenerator implements ContentProjectPlanGenerator
{
    public function __construct(
        private readonly ContentProjectPlanTemplateRegistry $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $constraints
     * @param  array<string, mixed>|null  $projectContext
     */
    public function generate(
        AgentExecutionContext $context,
        string $objective,
        array $constraints = [],
        ?array $projectContext = null,
        ?ContentProjectAutomationPolicy $policy = null,
    ): AgentPlanDraft {
        $templateKey = trim((string) ($constraints['template'] ?? ''));
        if ($templateKey === '') {
            $templateKey = $this->inferTemplate($objective, $projectContext);
        }

        $draft = $this->templates->build($templateKey, $context, $objective, $constraints, $projectContext);

        return $draft ?? new AgentPlanDraft(
            objective: $objective,
            steps: [],
            warnings: ['No matching template for objective. Provide constraints.template or item_seed.'],
            missingInputs: ['template'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $projectContext
     */
    private function inferTemplate(string $objective, ?array $projectContext): string
    {
        $lower = strtolower($objective);

        if (str_contains($lower, 'restore')) {
            return 'restore_and_rebuild';
        }
        if (str_contains($lower, 'schedule')) {
            return 'schedule_approved';
        }
        if (str_contains($lower, 'publish')) {
            return 'publish_due_check';
        }
        if (str_contains($lower, 'review')) {
            return 'review_existing';
        }
        if (str_contains($lower, 'generate') && $projectContext !== null) {
            return 'generate_only';
        }

        return 'generate_new_content_project';
    }
}
