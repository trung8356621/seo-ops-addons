<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Skills;

final class OperationsSkills
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'key' => 'site.discover',
                'slash_command' => '/discover-site',
                'name' => 'Discover site',
                'description' => 'Detect WordPress capability manifest + site profile.',
                'category' => 'operations',
                'capability' => 'site.discover',
                'icon' => 'heroicon-o-magnifying-glass-circle',
                'aliases' => ['discover_site'],
                'sort_order' => 20,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'example_prompts' => ['Discover site capabilities', 'Phát hiện capability website'],
            ],
            [
                'key' => 'site.sync',
                'slash_command' => '/sync-site',
                'name' => 'Đồng bộ & kiểm tra website',
                'description' => 'Chạy RunSiteSync orchestrator (delta-first).',
                'category' => 'operations',
                'capability' => 'site.sync',
                'icon' => 'heroicon-o-arrow-path',
                'aliases' => ['sync_site'],
                'sort_order' => 21,
                'is_featured' => true,
                'required_scopes' => ['content-project:write'],
                'form_schema' => [
                    ['key' => 'mode', 'label' => 'Mode', 'type' => 'select', 'required' => false, 'options' => [
                        ['value' => 'delta', 'label' => 'Delta'],
                        ['value' => 'snapshot', 'label' => 'Snapshot'],
                    ]],
                ],
                'example_prompts' => ['Sync site', 'Đồng bộ website'],
            ],
            [
                'key' => 'site.sync_keywords',
                'slash_command' => '/sync-keywords',
                'name' => 'Sync keywords',
                'description' => 'Provider keywords + workspace fallback khi thiếu capability.',
                'category' => 'operations',
                'capability' => 'site.sync_keywords',
                'icon' => 'heroicon-o-hashtag',
                'aliases' => ['sync_keywords'],
                'sort_order' => 22,
                'required_scopes' => ['content-project:write'],
            ],
            [
                'key' => 'site.sync_links',
                'slash_command' => '/sync-links',
                'name' => 'Sync links',
                'description' => 'URL catalog + validate changed links.',
                'category' => 'operations',
                'capability' => 'site.sync_links',
                'icon' => 'heroicon-o-link',
                'aliases' => ['sync_links'],
                'sort_order' => 23,
                'required_scopes' => ['content-project:write'],
            ],
            [
                'key' => 'site.discover_contacts',
                'slash_command' => '/discover-contacts',
                'name' => 'Discover contacts',
                'description' => 'Suggest contacts / profile từ WordPress (không overwrite manual).',
                'category' => 'operations',
                'capability' => 'site.discover_contacts',
                'icon' => 'heroicon-o-phone',
                'aliases' => ['discover_contacts'],
                'sort_order' => 24,
                'required_scopes' => ['content-project:write'],
            ],
            [
                'key' => 'site.refresh_snapshot',
                'slash_command' => '/refresh-snapshot',
                'name' => 'Refresh snapshot',
                'description' => 'Force full snapshot (Advanced).',
                'category' => 'operations',
                'capability' => 'site.refresh_snapshot',
                'icon' => 'heroicon-o-circle-stack',
                'aliases' => ['refresh_snapshot'],
                'sort_order' => 25,
                'confirmation_policy' => 'confirm',
                'required_scopes' => ['content-project:write'],
            ],
        ];
    }
}
