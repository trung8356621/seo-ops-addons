<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentIntentResolution;

/**
 * Simple multi-step plan presentation — not an autonomous planner.
 */
final class AgentExecutionPlanService
{
    public function __construct(
        private readonly AgentWorkspaceQuotaService $quotas,
    ) {}

    public function detectMultiIntent(string $text): ?AgentIntentResolution
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        $hasAnalyze = str_contains($normalized, 'phân tích') || str_contains($normalized, 'analyze');
        $hasCreate = (str_contains($normalized, 'tạo project') || str_contains($normalized, 'create project'))
            || (str_contains($normalized, 'tạo') && str_contains($normalized, 'project'));
        $hasTopical = str_contains($normalized, 'topical');

        if (! ($hasAnalyze && ($hasCreate || $hasTopical))) {
            return null;
        }

        $steps = [
            ['skill_key' => 'keyword.analyze', 'title' => 'Phân tích Keyword Workspace'],
            ['skill_key' => 'keyword.list_clusters', 'title' => 'Review cluster'],
            ['skill_key' => 'keyword.build_topical_map', 'title' => 'Xây Topical Map'],
            ['skill_key' => 'keyword.preview_project', 'title' => 'Preview Content Project'],
            ['skill_key' => 'content_project.create', 'title' => 'Tạo Content Project'],
        ];

        if (count($steps) > $this->quotas->maxMultiStepPlanActions()) {
            $steps = array_slice($steps, 0, $this->quotas->maxMultiStepPlanActions());
        }

        return new AgentIntentResolution(
            skillKey: null,
            confidence: 0.8,
            source: AgentIntentResolution::SOURCE_MULTI,
            planSteps: $steps,
            requiresUserChoice: true,
            message: 'Yêu cầu gồm nhiều bước. Chạy từng bước và xác nhận riêng với write/risky actions.',
        );
    }
}
