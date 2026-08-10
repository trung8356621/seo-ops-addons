<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class SerpIntelligenceSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'serp.create_queries',
                'slash_command' => '/create-serp-queries',
                'name' => 'Tạo SERP queries',
                'description' => 'Tạo danh sách query SERP từ keyword/cluster.',
                'category' => 'serp_intelligence',
                'capability' => 'serp_intelligence.create_queries',
                'sort_order' => 60,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
            ],
            [
                'key' => 'serp.import',
                'slash_command' => '/import-serp',
                'name' => 'Import SERP thủ công',
                'description' => 'Import SERP snapshot thủ công khi chưa cấu hình provider.',
                'category' => 'serp_intelligence',
                'capability' => 'serp_intelligence.import_snapshot',
                'sort_order' => 61,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'payload', 'label' => 'SERP payload', 'type' => 'textarea', 'required' => true],
                ],
            ],
            [
                'key' => 'serp.collect',
                'slash_command' => '/collect-serp',
                'name' => 'Thu thập SERP',
                'description' => 'Thu thập SERP qua provider đã cấu hình (có quota preview).',
                'category' => 'serp_intelligence',
                'capability' => 'serp_intelligence.collect',
                'sort_order' => 62,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
                'availability_policy' => [
                    'provider' => 'serp',
                    'requires_context' => ['workspace_ref'],
                ],
                'form_schema' => [
                    ['key' => 'workspace_ref', 'label' => 'Keyword Workspace', 'type' => 'workspace', 'required' => false],
                    ['key' => 'cluster_ref', 'label' => 'Cluster', 'type' => 'text', 'required' => false],
                    ['key' => 'keyword_ref', 'label' => 'Keyword', 'type' => 'text', 'required' => false],
                    [
                        'key' => 'device',
                        'label' => 'Device',
                        'type' => 'select',
                        'default' => 'desktop',
                        'options' => [
                            ['value' => 'desktop', 'label' => 'Desktop'],
                            ['value' => 'mobile', 'label' => 'Mobile'],
                        ],
                    ],
                    ['key' => 'country', 'label' => 'Country', 'type' => 'text', 'default' => 'vn'],
                    ['key' => 'location', 'label' => 'Location', 'type' => 'text', 'required' => false],
                    ['key' => 'provider', 'label' => 'Provider', 'type' => 'text', 'required' => false],
                    ['key' => 'sampling', 'label' => 'Sampling', 'type' => 'number', 'required' => false],
                ],
                'example_prompts' => ['Kiểm tra SERP cho một cluster'],
            ],
            [
                'key' => 'serp.validate_cluster',
                'slash_command' => '/validate-cluster-serp',
                'name' => 'Validate cluster SERP',
                'description' => 'Kiểm tra cluster có cần tách dựa trên SERP evidence.',
                'category' => 'serp_intelligence',
                'capability' => 'serp_intelligence.validate_cluster',
                'sort_order' => 63,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
            ],
            [
                'key' => 'serp.list_content_gaps',
                'slash_command' => '/list-content-gaps',
                'name' => 'Content gaps',
                'description' => 'Xem danh sách content gap từ SERP.',
                'category' => 'serp_intelligence',
                'capability' => 'serp_intelligence.list_content_gaps',
                'sort_order' => 64,
                'required_scopes' => ['content-project:read'],
            ],
        ];
    }
}
