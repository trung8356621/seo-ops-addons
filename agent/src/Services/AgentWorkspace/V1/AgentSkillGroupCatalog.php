<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\V1;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentSkillDefinition;

/**
 * Curated skill groups for discoverability (no architecture change).
 */
final class AgentSkillGroupCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'content_projects', 'label' => 'Content Projects', 'label_vi' => 'Content Projects', 'description' => 'Tạo/chạy/duyệt/handoff project (không schedule/publish).', 'skill_keys' => [
                'content_project.create', 'content_project.add_items', 'content_project.generate', 'content_project.rerun',
                'content_project.resume_failed_step', 'content_project.acknowledge_generation_error',
                'content_project.start_review', 'content_project.approve', 'content_project.send_to_publishing_queue', 'content_project.status',
                'content_project.archive', 'content_project.restore', 'content_project.stop_execution', 'content_project.resume_execution',
            ]],
            ['key' => 'publishing', 'label' => 'Publishing', 'label_vi' => 'Đăng bài', 'description' => 'Publishing Queue: schedule, Quick Mode, publish, retry, return.', 'skill_keys' => [
                'content_project.publishing_queue', 'content_project.schedule', 'content_project.auto_schedule', 'content_project.publish_now', 'content_project.retry_publish',
                'content_project.skip_publish', 'content_project.cancel_publish', 'content_project.unschedule', 'content_project.move_schedule',
                'content_project.return_to_content_project',
            ]],
            ['key' => 'keywords', 'label' => 'Keywords', 'label_vi' => 'Từ khóa', 'description' => 'Import/analyze/topical map.', 'skill_keys' => [
                'keyword.list_workspaces', 'keyword.import', 'keyword.analyze', 'keyword.build_topical_map', 'keyword.list_clusters',
            ]],
            ['key' => 'serp', 'label' => 'SERP', 'label_vi' => 'SERP', 'description' => 'Collect SERP & content gaps.', 'skill_keys' => [
                'serp.collect', 'serp.list_content_gaps', 'serp.validate_cluster',
            ]],
            ['key' => 'gsc', 'label' => 'GSC', 'label_vi' => 'GSC', 'description' => 'GSC opportunities (when skills exist).', 'skill_keys' => []],
            ['key' => 'seo_audit', 'label' => 'SEO Audit', 'label_vi' => 'SEO Audit', 'description' => 'Audit articles needing SEO work.', 'skill_keys' => ['seo_audit.list']],
            ['key' => 'reports_health', 'label' => 'Reports & Health', 'label_vi' => 'Báo cáo & sức khỏe', 'description' => 'Site health, daily report, timeline.', 'skill_keys' => [
                'operations.site_health', 'operations.daily_report', 'operations.operation_status', 'content_project.timeline',
            ]],
            ['key' => 'knowledge', 'label' => 'Knowledge', 'label_vi' => 'Knowledge', 'description' => 'Scoped knowledge & memory.', 'skill_keys' => [
                'knowledge.list', 'knowledge.add', 'knowledge.search', 'knowledge.review_memory',
            ]],
            ['key' => 'automations', 'label' => 'Automations', 'label_vi' => 'Automations', 'description' => 'Scheduled watches & guarded runs.', 'skill_keys' => [
                'automation.list', 'automation.create', 'automation.run', 'automation.history',
            ]],
            ['key' => 'operations', 'label' => 'Operations', 'label_vi' => 'Operations', 'description' => 'Agent ops & evaluation.', 'skill_keys' => [
                'observability.health', 'observability.metrics', 'observability.trace', 'observability.run_evaluation',
            ]],
            ['key' => 'packs', 'label' => 'Packs', 'label_vi' => 'Packs', 'description' => 'Skill packs & studio.', 'skill_keys' => [
                'packs.list', 'packs.validate', 'packs.enable', 'packs.disable',
            ]],
            ['key' => 'writing_editing', 'label' => 'Writing & Editing', 'label_vi' => 'Viết & chỉnh sửa', 'description' => 'Article helpers (P2 backlog when contracts exist).', 'skill_keys' => []],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function present(AgentSkillRegistry $skills): array
    {
        $out = [];
        foreach (self::groups() as $group) {
            $items = [];
            foreach ($group['skill_keys'] as $key) {
                $skill = $skills->get($key);
                if ($skill === null) {
                    continue;
                }
                $items[] = $this->skillCard($skill);
            }
            $out[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'label_vi' => $group['label_vi'],
                'description' => $group['description'],
                'skills' => $items,
                'count' => count($items),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function skillCard(AgentSkillDefinition $skill): array
    {
        $mode = $skill->confirmationPolicy === 'none' ? 'read' : 'write';

        return [
            'key' => $skill->key,
            'name' => $skill->name,
            'slash_command' => $skill->slashCommand,
            'mode' => $mode,
            'confirmation_policy' => $skill->confirmationPolicy,
            'confirmation_badge' => $skill->confirmationPolicy !== 'none',
            'write_badge' => $mode === 'write',
            'read_badge' => $mode === 'read',
            'is_featured' => $skill->isFeatured,
        ];
    }
}
