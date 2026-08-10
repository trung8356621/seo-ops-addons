<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class ObservabilitySkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'observability.health',
                'slash_command' => '/agent-health',
                'name' => 'Agent Health',
                'description' => 'Tổng quan sức khỏe Agent Workspace (manager).',
                'category' => 'observability',
                'capability' => 'agent.observability.health',
                'sort_order' => 20,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'observability.metrics',
                'slash_command' => '/agent-metrics',
                'name' => 'Agent Metrics',
                'description' => 'Xem metrics tổng hợp theo site.',
                'category' => 'observability',
                'capability' => 'agent.observability.metrics',
                'sort_order' => 21,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'observability.trace',
                'slash_command' => '/agent-trace',
                'name' => 'Agent Trace',
                'description' => 'Xem timeline trace (sanitized).',
                'category' => 'observability',
                'capability' => 'agent.observability.trace',
                'sort_order' => 22,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'trace_id', 'label' => 'Trace ID', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'observability.review',
                'slash_command' => '/review-agent',
                'name' => 'Review Queue',
                'description' => 'Hàng đợi human review.',
                'category' => 'observability',
                'capability' => 'agent.observability.review',
                'sort_order' => 23,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'observability.run_evaluation',
                'slash_command' => '/run-evaluation',
                'name' => 'Run Evaluation',
                'description' => 'Chạy offline evaluation (không execute business).',
                'category' => 'observability',
                'capability' => 'agent.observability.run_evaluation',
                'sort_order' => 24,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'dataset', 'label' => 'Dataset key', 'type' => 'text', 'required' => true, 'default' => 'core-routing'],
                    ['key' => 'dry_run', 'label' => 'Dry run (1=yes)', 'type' => 'text', 'default' => '1'],
                ],
            ],
            [
                'key' => 'observability.evaluation_status',
                'slash_command' => '/evaluation-status',
                'name' => 'Evaluation Status',
                'description' => 'Trạng thái evaluation run gần nhất.',
                'category' => 'observability',
                'capability' => 'agent.observability.evaluation_status',
                'sort_order' => 25,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'observability.policy_violations',
                'slash_command' => '/policy-violations',
                'name' => 'Policy Violations',
                'description' => 'Danh sách vi phạm policy gần đây.',
                'category' => 'observability',
                'capability' => 'agent.observability.policy_violations',
                'sort_order' => 26,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'observability.automation_health',
                'slash_command' => '/automation-health',
                'name' => 'Automation Health',
                'description' => 'Đánh giá sức khỏe automations (không auto-pause).',
                'category' => 'observability',
                'capability' => 'agent.observability.automation_health',
                'sort_order' => 27,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
            ],
        ];
    }
}
