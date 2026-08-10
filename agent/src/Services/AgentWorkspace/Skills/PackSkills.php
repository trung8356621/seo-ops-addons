<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class PackSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'packs.list',
                'slash_command' => '/agent-packs',
                'name' => 'Agent Packs',
                'description' => 'Danh sách Skill Packs (manager).',
                'category' => 'packs',
                'capability' => 'agent.pack.list',
                'sort_order' => 30,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
            ],
            [
                'key' => 'packs.status',
                'slash_command' => '/pack-status',
                'name' => 'Pack Status',
                'description' => 'Trạng thái một pack.',
                'category' => 'packs',
                'capability' => 'agent.pack.status',
                'sort_order' => 31,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'pack_ref', 'label' => 'Pack hash/key', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'packs.validate',
                'slash_command' => '/validate-pack',
                'name' => 'Validate Pack',
                'description' => 'Validate declarative pack JSON (không enable).',
                'category' => 'packs',
                'capability' => 'agent.pack.validate',
                'sort_order' => 32,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'manifest_json', 'label' => 'Manifest JSON', 'type' => 'textarea', 'required' => true],
                ],
            ],
            [
                'key' => 'packs.evaluate',
                'slash_command' => '/evaluate-pack',
                'name' => 'Evaluate Pack',
                'description' => 'Chạy dataset pack:{key}:{dataset} offline.',
                'category' => 'packs',
                'capability' => 'agent.pack.evaluate',
                'sort_order' => 33,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'preview',
                'form_schema' => [
                    ['key' => 'pack_key', 'label' => 'Pack key', 'type' => 'text', 'required' => true],
                    ['key' => 'dataset_key', 'label' => 'Dataset key', 'type' => 'text', 'required' => true],
                    ['key' => 'dry_run', 'label' => 'Dry run (1=yes)', 'type' => 'text', 'default' => '1'],
                ],
            ],
            [
                'key' => 'packs.enable',
                'slash_command' => '/enable-pack',
                'name' => 'Enable Pack',
                'description' => 'Enable pack sau validate/gate + approval tường minh.',
                'category' => 'packs',
                'capability' => 'agent.pack.enable',
                'sort_order' => 34,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'confirm',
                'form_schema' => [
                    ['key' => 'pack_ref', 'label' => 'Pack hash', 'type' => 'text', 'required' => true],
                    ['key' => 'explicit_approval', 'label' => 'Approve (1)', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'packs.disable',
                'slash_command' => '/disable-pack',
                'name' => 'Disable Pack',
                'description' => 'Disable pack — giữ history, không xóa business data.',
                'category' => 'packs',
                'capability' => 'agent.pack.disable',
                'sort_order' => 35,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'confirm',
                'form_schema' => [
                    ['key' => 'pack_ref', 'label' => 'Pack hash', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'packs.skills',
                'slash_command' => '/pack-skills',
                'name' => 'Pack Skills',
                'description' => 'Liệt kê skills compiled của pack.',
                'category' => 'packs',
                'capability' => 'agent.pack.skills',
                'sort_order' => 36,
                'availability_policy' => ['status_override' => 'available', 'min_role' => 'manager'],
                'confirmation_policy' => 'none',
                'form_schema' => [
                    ['key' => 'pack_ref', 'label' => 'Pack hash/key', 'type' => 'text', 'required' => true],
                ],
            ],
        ];
    }
}
