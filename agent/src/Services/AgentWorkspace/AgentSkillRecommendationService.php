<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;

/**
 * Deterministic skill recommendations from context — no AI required.
 */
final class AgentSkillRecommendationService
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AgentSkillAvailabilityService $availability,
    ) {}

    /**
     * @param  array{
     *     project_ref?: string|null,
     *     workspace_ref?: string|null,
     *     article_ref?: string|null,
     *     project_phase?: string|null,
     *     has_running_operations?: bool,
     *     recent_skill_keys?: list<string>,
     *     scopes?: list<string>,
     *     role?: string|null,
     *     providers?: array<string, bool>
     * }  $context
     * @return list<array{skill: AgentSkillDefinition, availability: array{status: string, reason: string, usable: bool}, reason: string}>
     */
    public function recommend(array $context = [], int $limit = 8): array
    {
        $keys = [];

        if (! empty($context['has_running_operations'])) {
            $keys = ['operations.operation_status', 'content_project.status', 'content_project.publishing_queue'];
        } elseif (! empty($context['project_ref'])) {
            $phase = (string) ($context['project_phase'] ?? '');
            $keys = match ($phase) {
                'generating', 'running' => ['operations.operation_status', 'content_project.status'],
                'review' => ['content_project.approve', 'content_project.start_review', 'content_project.status'],
                'approved' => ['content_project.schedule', 'content_project.publish_now', 'content_project.status'],
                default => [
                    'content_project.status',
                    'content_project.add_items',
                    'content_project.generate',
                    'content_project.rerun',
                    'content_project.schedule',
                    'content_project.archive',
                ],
            };
        } elseif (! empty($context['workspace_ref'])) {
            $keys = [
                'keyword.analyze',
                'keyword.build_topical_map',
                'serp.collect',
                'keyword.preview_project',
            ];
        } else {
            $keys = [
                'content_project.create',
                'keyword.import',
                'keyword.analyze',
                'operations.daily_report',
                'operations.site_health',
            ];
        }

        $recent = $context['recent_skill_keys'] ?? [];
        if (is_array($recent)) {
            foreach (array_reverse($recent) as $recentKey) {
                if (is_string($recentKey) && $recentKey !== '') {
                    array_unshift($keys, $recentKey);
                }
            }
        }

        $out = [];
        $seen = [];
        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $skill = $this->skills->get($key);
            if ($skill === null || $skill->isHidden) {
                continue;
            }

            $availability = $this->availability->resolve($skill, $context);
            // Skip archive when clearly blocked by running ops.
            if ($key === 'content_project.archive' && ! empty($context['has_running_operations'])) {
                $availability = \Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillAvailability::of(
                    \Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillAvailability::WRONG_CONTEXT,
                    'Project đang chạy AI nên chưa thể archive.',
                );
            }

            $out[] = [
                'skill' => $skill,
                'availability' => $availability->toArray(),
                'reason' => $this->reasonFor($skill, $context),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function reasonFor(AgentSkillDefinition $skill, array $context): string
    {
        if (! empty($context['project_ref'])) {
            return 'Phù hợp với Content Project hiện tại';
        }
        if (! empty($context['workspace_ref'])) {
            return 'Phù hợp với Keyword Workspace hiện tại';
        }

        return 'Gợi ý bắt đầu';
    }
}
