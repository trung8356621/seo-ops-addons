<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class AutomationSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'automation.list',
                'slash_command' => '/automations',
                'name' => 'Automations',
                'description' => 'Liệt kê Agent Automations của site hiện tại.',
                'category' => 'automation',
                'capability' => 'agent.automation.list',
                'icon' => 'heroicon-o-clock',
                'sort_order' => 11,
                'is_featured' => true,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
                'example_prompts' => ['Xem automations', 'Danh sách automation'],
            ],
            [
                'key' => 'automation.create',
                'slash_command' => '/create-automation',
                'name' => 'Create automation',
                'description' => 'Tạo automation (preview trước, save tường minh).',
                'category' => 'automation',
                'capability' => 'agent.automation.create',
                'sort_order' => 12,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'name', 'label' => 'Tên', 'type' => 'text', 'required' => true],
                    [
                        'key' => 'type',
                        'label' => 'Loại',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'scheduled_report',
                        'options' => [
                            ['value' => 'scheduled_report', 'label' => 'Scheduled report'],
                            ['value' => 'condition_watch', 'label' => 'Condition watch'],
                            ['value' => 'planning_workflow', 'label' => 'Planning workflow'],
                            ['value' => 'guarded_action', 'label' => 'Guarded action'],
                        ],
                    ],
                    [
                        'key' => 'frequency',
                        'label' => 'Tần suất',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'daily',
                        'options' => [
                            ['value' => 'hourly', 'label' => 'Hourly'],
                            ['value' => 'daily', 'label' => 'Daily'],
                            ['value' => 'weekly', 'label' => 'Weekly'],
                            ['value' => 'monthly', 'label' => 'Monthly'],
                        ],
                    ],
                    ['key' => 'time', 'label' => 'Giờ (HH:MM)', 'type' => 'text', 'required' => true, 'default' => '09:00'],
                    ['key' => 'timezone', 'label' => 'Timezone', 'type' => 'text', 'required' => true, 'default' => 'UTC'],
                    ['key' => 'skill_key', 'label' => 'Skill key (read)', 'type' => 'text', 'required' => false],
                    ['key' => 'explicit_save', 'label' => 'Save (1=yes)', 'type' => 'text', 'required' => false, 'default' => '0'],
                ],
            ],
            [
                'key' => 'automation.status',
                'slash_command' => '/automation-status',
                'name' => 'Automation status',
                'description' => 'Xem trạng thái một automation.',
                'category' => 'automation',
                'capability' => 'agent.automation.status',
                'sort_order' => 13,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'automation_ref', 'label' => 'Automation ref', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'automation.run',
                'slash_command' => '/run-automation',
                'name' => 'Run automation now',
                'description' => 'Chạy ngay (cùng policy với scheduled).',
                'category' => 'automation',
                'capability' => 'agent.automation.run',
                'sort_order' => 14,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'automation_ref', 'label' => 'Automation ref', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'automation.pause',
                'slash_command' => '/pause-automation',
                'name' => 'Pause automation',
                'description' => 'Tạm dừng dispatch tương lai.',
                'category' => 'automation',
                'capability' => 'agent.automation.pause',
                'sort_order' => 15,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'automation_ref', 'label' => 'Automation ref', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'automation.resume',
                'slash_command' => '/resume-automation',
                'name' => 'Resume automation',
                'description' => 'Tiếp tục sau khi revalidate.',
                'category' => 'automation',
                'capability' => 'agent.automation.resume',
                'sort_order' => 16,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'automation_ref', 'label' => 'Automation ref', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'automation.delete',
                'slash_command' => '/delete-automation',
                'name' => 'Delete automation',
                'description' => 'Soft-delete; giữ lịch sử run.',
                'category' => 'automation',
                'capability' => 'agent.automation.delete',
                'sort_order' => 17,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'confirm',
                'form_schema' => [
                    ['key' => 'automation_ref', 'label' => 'Automation ref', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'automation.history',
                'slash_command' => '/automation-history',
                'name' => 'Automation history',
                'description' => 'Lịch sử run của automation.',
                'category' => 'automation',
                'capability' => 'agent.automation.history',
                'sort_order' => 18,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'automation_ref', 'label' => 'Automation ref', 'type' => 'text', 'required' => true],
                ],
            ],
        ];
    }
}
