<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class KeywordIntelligenceSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'keyword.list_workspaces',
                'slash_command' => '/list-keyword-workspaces',
                'name' => 'Danh sách Keyword Workspace',
                'description' => 'Xem các Keyword Workspace của site.',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.list_workspaces',
                'sort_order' => 40,
                'required_scopes' => ['content-project:read'],
            ],
            [
                'key' => 'keyword.import',
                'slash_command' => '/import-keywords',
                'name' => 'Nhập từ khóa',
                'description' => 'Import bộ từ khóa vào Keyword Workspace.',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.import_keywords',
                'sort_order' => 41,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
                'availability_policy' => ['requires_context' => ['workspace_ref']],
                'form_schema' => [
                    ['key' => 'workspace_ref', 'label' => 'Keyword Workspace', 'type' => 'workspace', 'required' => true],
                    ['key' => 'keywords_text', 'label' => 'Danh sách từ khóa', 'type' => 'textarea', 'required' => true],
                ],
                'example_prompts' => ['Nhập bộ từ khóa'],
            ],
            [
                'key' => 'keyword.analyze',
                'slash_command' => '/analyze-keywords',
                'name' => 'Phân tích từ khóa',
                'description' => 'Phân tích và gom nhóm từ khóa trong workspace.',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.analyze_workspace',
                'sort_order' => 42,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
                'availability_policy' => ['requires_context' => ['workspace_ref']],
                'form_schema' => [
                    ['key' => 'workspace_ref', 'label' => 'Keyword Workspace', 'type' => 'workspace', 'required' => true],
                    [
                        'key' => 'scope',
                        'label' => 'Phạm vi',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'unanalyzed',
                        'options' => [
                            ['value' => 'all', 'label' => 'Toàn bộ'],
                            ['value' => 'unanalyzed', 'label' => 'Chỉ keyword chưa phân tích'],
                            ['value' => 'selected', 'label' => 'Keyword được chọn'],
                        ],
                    ],
                    [
                        'key' => 'strategy',
                        'label' => 'Strategy',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'balanced',
                        'options' => [
                            ['value' => 'strict', 'label' => 'Strict'],
                            ['value' => 'balanced', 'label' => 'Balanced'],
                            ['value' => 'broad', 'label' => 'Broad'],
                        ],
                    ],
                    [
                        'key' => 'use_ai_intent',
                        'label' => 'Dùng AI cho intent',
                        'type' => 'boolean',
                        'required' => false,
                        'default' => true,
                    ],
                ],
                'example_prompts' => ['Phân tích những từ khóa chưa xử lý trong workspace này'],
            ],
            [
                'key' => 'keyword.list_clusters',
                'slash_command' => '/list-keyword-clusters',
                'name' => 'Danh sách cluster',
                'description' => 'Xem các keyword cluster trong workspace.',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.list_clusters',
                'sort_order' => 43,
                'required_scopes' => ['content-project:read'],
                'availability_policy' => ['requires_context' => ['workspace_ref']],
            ],
            [
                'key' => 'keyword.build_topical_map',
                'slash_command' => '/build-topical-map',
                'name' => 'Xây Topical Map',
                'description' => 'Tạo draft Topical Map từ cluster đã duyệt (không tự approve).',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.build_topical_map',
                'sort_order' => 46,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
                'availability_policy' => ['requires_context' => ['workspace_ref']],
                'form_schema' => [
                    ['key' => 'workspace_ref', 'label' => 'Keyword Workspace', 'type' => 'workspace', 'required' => true],
                    [
                        'key' => 'mode',
                        'label' => 'Mode',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'balanced',
                        'options' => [
                            ['value' => 'conservative', 'label' => 'Conservative'],
                            ['value' => 'balanced', 'label' => 'Balanced'],
                            ['value' => 'expansive', 'label' => 'Expansive'],
                        ],
                    ],
                    [
                        'key' => 'source',
                        'label' => 'Nguồn',
                        'type' => 'select',
                        'required' => true,
                        'default' => 'approved',
                        'options' => [
                            ['value' => 'approved', 'label' => 'Approved clusters'],
                            ['value' => 'approved_reviewed', 'label' => 'Approved + Reviewed'],
                        ],
                    ],
                    [
                        'key' => 'keep_manual_structure',
                        'label' => 'Giữ cấu trúc thủ công',
                        'type' => 'boolean',
                        'required' => false,
                        'default' => true,
                    ],
                ],
                'example_prompts' => ['Xây Topical Map'],
            ],
            [
                'key' => 'keyword.approve_topical_map',
                'slash_command' => '/approve-topical-map',
                'name' => 'Duyệt Topical Map',
                'description' => 'Approve draft Topical Map hiện tại.',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.approve_topical_map',
                'sort_order' => 47,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'confirm',
            ],
            [
                'key' => 'keyword.preview_project',
                'slash_command' => '/preview-project',
                'name' => 'Xem trước project từ kế hoạch',
                'description' => 'Preview Content Project từ Topical Map / cluster đã duyệt.',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.preview_content_project',
                'sort_order' => 48,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'preview',
                'availability_policy' => ['requires_context' => ['workspace_ref']],
                'example_prompts' => ['Tạo Content Project từ Topical Map đã duyệt'],
            ],
            [
                'key' => 'keyword.create_project_from_map',
                'slash_command' => '/create-project-from-map',
                'name' => 'Tạo project từ Topical Map',
                'description' => 'Tạo Content Project từ Topical Map đã duyệt.',
                'category' => 'keyword_intelligence',
                'capability' => 'keyword_intelligence.create_content_project',
                'sort_order' => 49,
                'required_scopes' => ['content-project:write'],
                'confirmation_policy' => 'confirm',
                'availability_policy' => ['requires_context' => ['workspace_ref']],
            ],
        ];
    }
}
