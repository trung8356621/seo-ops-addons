<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class KnowledgeSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'knowledge.list',
                'slash_command' => '/knowledge',
                'name' => 'Knowledge Base',
                'description' => 'Xem knowledge/memory theo site hiện tại.',
                'category' => 'knowledge',
                'capability' => 'agent.knowledge.list',
                'icon' => 'heroicon-o-book-open',
                'sort_order' => 5,
                'is_featured' => true,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
                'example_prompts' => ['Xem knowledge', 'Site knowledge hiện có gì?'],
            ],
            [
                'key' => 'knowledge.add',
                'slash_command' => '/add-knowledge',
                'name' => 'Thêm knowledge',
                'description' => 'Thêm note/rule vào Site Knowledge (không ghi business table).',
                'category' => 'knowledge',
                'capability' => 'agent.knowledge.add',
                'icon' => 'heroicon-o-plus',
                'sort_order' => 6,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'title', 'label' => 'Tiêu đề', 'type' => 'text', 'required' => true],
                    ['key' => 'content', 'label' => 'Nội dung', 'type' => 'textarea', 'required' => true],
                    [
                        'key' => 'type',
                        'label' => 'Loại',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'general_note',
                        'options' => [
                            ['value' => 'general_note', 'label' => 'General note'],
                            ['value' => 'brand', 'label' => 'Brand'],
                            ['value' => 'tone', 'label' => 'Tone'],
                            ['value' => 'seo_rule', 'label' => 'SEO rule'],
                            ['value' => 'prohibited_term', 'label' => 'Prohibited term'],
                            ['value' => 'project_decision', 'label' => 'Project decision'],
                            ['value' => 'user_preference', 'label' => 'User preference'],
                        ],
                    ],
                    [
                        'key' => 'scope_type',
                        'label' => 'Scope',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'site',
                        'options' => [
                            ['value' => 'site', 'label' => 'Site'],
                            ['value' => 'project', 'label' => 'Project'],
                            ['value' => 'workspace', 'label' => 'Workspace'],
                            ['value' => 'user_preference', 'label' => 'User preference'],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'knowledge.search',
                'slash_command' => '/search-knowledge',
                'name' => 'Search knowledge',
                'description' => 'Tìm knowledge trong scope hiện tại.',
                'category' => 'knowledge',
                'capability' => 'agent.knowledge.search',
                'sort_order' => 7,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'query', 'label' => 'Từ khóa', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'knowledge.review_memory',
                'slash_command' => '/review-memory',
                'name' => 'Review memory proposals',
                'description' => 'Xem đề xuất memory chờ duyệt.',
                'category' => 'knowledge',
                'capability' => 'agent.knowledge.review_memory',
                'sort_order' => 8,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'knowledge.forget',
                'slash_command' => '/forget-memory',
                'name' => 'Forget knowledge',
                'description' => 'Disable/soft-delete knowledge (không xóa business source).',
                'category' => 'knowledge',
                'capability' => 'agent.knowledge.forget',
                'sort_order' => 9,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'confirm',
                'form_schema' => [
                    ['key' => 'knowledge_ref', 'label' => 'Knowledge ref', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'knowledge.verify',
                'slash_command' => '/verify-knowledge',
                'name' => 'Verify knowledge',
                'description' => 'Đánh dấu knowledge đã verify thủ công.',
                'category' => 'knowledge',
                'capability' => 'agent.knowledge.verify',
                'sort_order' => 10,
                'availability_policy' => ['status_override' => 'available'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'knowledge_ref', 'label' => 'Knowledge ref', 'type' => 'text', 'required' => true],
                ],
            ],
        ];
    }
}
