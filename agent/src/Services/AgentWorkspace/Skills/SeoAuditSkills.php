<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class SeoAuditSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'seo_audit.list',
                'slash_command' => '/audit-list',
                'name' => 'Danh sách SEO Audit',
                'description' => 'Liệt kê bài cần xử lý trên SEO Audit (cùng query với /articles/optimal).',
                'category' => 'seo_audit',
                'capability' => 'seo_audit.list',
                'sort_order' => 55,
                'required_scopes' => ['content-project:read'],
                'confirmation_policy' => 'none',
                'availability_policy' => ['requires_context' => ['site_ref']],
                'form_schema' => [
                    [
                        'key' => 'post_type',
                        'label' => 'Post type',
                        'type' => 'text',
                        'required' => false,
                        'help' => 'Để trống hoặc all = mọi post type',
                    ],
                    [
                        'key' => 'limit',
                        'label' => 'Giới hạn',
                        'type' => 'number',
                        'required' => false,
                        'default' => 50,
                    ],
                ],
                'input_schema' => [
                    'post_type' => ['type' => 'string', 'required' => false],
                    'limit' => ['type' => 'integer', 'required' => false],
                ],
                'example_prompts' => [
                    'Liệt kê bài SEO audit',
                    'Audit list post type article',
                ],
            ],
        ];
    }
}
