<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class GeneralSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'general.help',
                'slash_command' => '/help',
                'name' => 'Trợ giúp',
                'description' => 'Xem các kỹ năng Agent theo nhóm.',
                'category' => 'general',
                'capability' => 'agent.help',
                'icon' => 'heroicon-o-question-mark-circle',
                'sort_order' => 1,
                'is_featured' => true,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
                'form_schema' => [],
                'example_prompts' => ['Giúp tôi bắt đầu', 'Agent làm được gì?'],
            ],
            [
                'key' => 'general.new_chat',
                'slash_command' => '/new-chat',
                'name' => 'Chat mới',
                'description' => 'Tạo cuộc hội thoại mới.',
                'category' => 'general',
                'capability' => 'agent.new_chat',
                'icon' => 'heroicon-o-plus-circle',
                'sort_order' => 2,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'operations.site_health',
                'slash_command' => '/site-health',
                'name' => 'Kiểm tra sức khỏe site',
                'description' => 'Xem tình trạng sức khỏe website hiện tại.',
                'category' => 'operations',
                'capability' => 'content_project.get_site_health',
                'icon' => 'heroicon-o-heart',
                'sort_order' => 10,
                'is_featured' => true,
                'required_scopes' => ['content-project:read'],
                'example_prompts' => ['Kiểm tra sức khỏe site', 'Site có ổn không?'],
            ],
            [
                'key' => 'operations.daily_report',
                'slash_command' => '/daily-report',
                'name' => 'Báo cáo hôm nay',
                'description' => 'Tóm tắt việc đã chạy, lỗi và bài cần chú ý hôm nay.',
                'category' => 'operations',
                'capability' => 'content_project.get_daily_report',
                'icon' => 'heroicon-o-clipboard-document-list',
                'sort_order' => 11,
                'is_featured' => true,
                'required_scopes' => ['content-project:read'],
                'example_prompts' => ['Báo cáo hôm nay', 'Hôm nay có gì lỗi?'],
            ],
            [
                'key' => 'operations.operation_status',
                'slash_command' => '/operation-status',
                'name' => 'Kiểm tra operation',
                'description' => 'Xem trạng thái một operation theo public ref.',
                'category' => 'operations',
                'capability' => 'content_project.get_operation',
                'icon' => 'heroicon-o-arrow-path',
                'sort_order' => 12,
                'required_scopes' => ['content-project:read'],
                'form_schema' => [
                    ['key' => 'operation_ref', 'label' => 'Operation ref', 'type' => 'text', 'required' => true],
                ],
                'input_schema' => [
                    'operation_ref' => ['type' => 'string', 'required' => true],
                ],
            ],
        ];
    }
}
