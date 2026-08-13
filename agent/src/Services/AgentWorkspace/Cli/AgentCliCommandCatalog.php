<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli;

/**
 * Static CLI command catalog — UX metadata only.
 * Maps slash commands → skill_key / capability_key. Executable SoT remains
 * CanonicalCapabilityRegistry (+ Gateway READ surface). Not a second MCP registry.
 */
final class AgentCliCommandCatalog
{
    /**
     * @return list<array{
     *   command: string,
     *   description: string,
     *   example: string,
     *   skill_key: string|null,
     *   capability_key: string|null,
     *   local_only: bool,
     *   category: string,
     *   args: list<array{
     *     flags: list<string>,
     *     key: string,
     *     label: string,
     *     required: bool,
     *     type: string,
     *     positional?: bool
     *   }>
     * }>
     */
    public static function all(): array
    {
        return [
            // --- Content Project ---
            [
                'command' => '/project-list',
                'description' => 'Liệt kê Content Project của site hiện tại.',
                'example' => '/project-list',
                'skill_key' => 'content_project.list',
                'capability_key' => 'content_project.list_projects',
                'local_only' => false,
                'category' => 'project',
                'args' => [],
            ],
            [
                'command' => '/project-view',
                'description' => 'Xem trạng thái và tiến độ Content Project.',
                'example' => '/project-view --project-id=31',
                'skill_key' => 'content_project.status',
                'capability_key' => 'content_project.get_status',
                'local_only' => false,
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],
            [
                'command' => '/project-create',
                'description' => 'Tạo Content Project mới.',
                'example' => '/project-create --name="Kế hoạch tháng 8" --month="08/2026" --member-id=12',
                'skill_key' => 'content_project.create',
                'capability_key' => 'content_project.create',
                'local_only' => false,
                'category' => 'project',
                'args' => [
                    ['flags' => ['--name'], 'key' => 'project_name', 'label' => 'Tên project', 'required' => true, 'type' => 'string'],
                    ['flags' => ['--month'], 'key' => 'month', 'label' => 'Tháng', 'required' => true, 'type' => 'month'],
                    ['flags' => ['--member-id', '--member'], 'key' => 'assignee_ref', 'label' => 'Member ID', 'required' => true, 'type' => 'member'],
                ],
            ],
            [
                'command' => '/project-edit',
                'description' => 'Sửa metadata Content Project.',
                'example' => '/project-edit --project-id=31 --name="Tên mới" --member-id=""',
                'skill_key' => 'content_project.update',
                'capability_key' => 'content_project.update',
                'local_only' => false,
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                    ['flags' => ['--name'], 'key' => 'project_name', 'label' => 'Tên mới', 'required' => false, 'type' => 'string'],
                    ['flags' => ['--member-id', '--member'], 'key' => 'assignee_ref', 'label' => 'Member ID', 'required' => false, 'type' => 'member'],
                ],
            ],
            [
                'command' => '/project-run',
                'description' => 'Chạy generate cho một Content Project.',
                'example' => '/project-run --project-id=31',
                'skill_key' => 'content_project.generate',
                'capability_key' => 'content_project.generate',
                'local_only' => false,
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],
            [
                'command' => '/project-review',
                'description' => 'Bắt đầu review các item của project.',
                'example' => '/project-review --project-id=31',
                'skill_key' => 'content_project.start_review',
                'capability_key' => 'content_project.start_review',
                'local_only' => false,
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],
            [
                'command' => '/project-archive',
                'description' => 'Lưu trữ (archive) Content Project.',
                'example' => '/project-archive --project-id=31',
                'skill_key' => 'content_project.archive',
                'capability_key' => 'content_project.archive',
                'local_only' => false,
                'category' => 'project',
                'args' => [
                    self::projectArg(),
                ],
            ],

            // --- Team / Members (local UI — no MCP capability) ---
            [
                'command' => '/member-list',
                'description' => 'Danh sách thành viên trên tài khoản.',
                'example' => '/member-list',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'member',
                'args' => [],
            ],
            [
                'command' => '/member-available',
                'description' => 'Thành viên sẵn sàng được gán.',
                'example' => '/member-available --month="08/2026"',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'member',
                'args' => [
                    ['flags' => ['--month'], 'key' => 'month', 'label' => 'Tháng', 'required' => false, 'type' => 'month'],
                ],
            ],

            // --- Keywords ---
            [
                'command' => '/keyword-suggest',
                'description' => 'Gợi ý keyword theo site hiện tại (Site MCP).',
                'example' => '/keyword-suggest --keyword="" --limit="10" --use-site-mcp="yes"',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'keyword',
                'requires_site' => true,
                'args' => [
                    ['flags' => ['--keyword'], 'key' => 'keyword', 'label' => 'Seed keyword', 'required' => false, 'type' => 'string'],
                    ['flags' => ['--limit'], 'key' => 'limit', 'label' => 'Limit', 'required' => false, 'type' => 'string'],
                    ['flags' => ['--use-site-mcp'], 'key' => 'use_site_mcp', 'label' => 'Use Site MCP', 'required' => false, 'type' => 'string'],
                ],
            ],
            [
                'command' => '/keyword-add-to-project',
                'description' => 'Thêm keyword vào Content Project (index hoặc nhập tay).',
                'example' => '/keyword-add-to-project --project-id=31 1,3,"keyword mới"',
                'skill_key' => 'content_project.add_items',
                'capability_key' => 'content_project.add_items',
                'local_only' => false,
                'category' => 'keyword',
                'args' => [
                    self::projectArg(),
                    ['flags' => [], 'key' => 'keywords_tokens', 'label' => 'Keywords', 'required' => true, 'type' => 'keywords', 'positional' => true],
                ],
            ],

            // --- SEO Audit (site-level read — reuse Articles Optimal query) ---
            [
                'command' => '/audit-list',
                'description' => 'Liệt kê bài bị SEO audit đánh dấu.',
                'example' => '/audit-list --post-type=""',
                'skill_key' => 'seo_audit.list',
                'capability_key' => 'seo_audit.list',
                'local_only' => false,
                'category' => 'audit',
                'args' => [
                    ['flags' => ['--post-type'], 'key' => 'post_type', 'label' => 'Post type', 'required' => false, 'type' => 'text', 'placeholder' => 'all'],
                    ['flags' => ['--limit'], 'key' => 'limit', 'label' => 'Limit', 'required' => false, 'type' => 'number', 'placeholder' => '50'],
                ],
            ],
            [
                'command' => '/audit-keyword-suggest',
                'description' => 'Gợi ý focus keyword cho bài audit.',
                'example' => '/audit-keyword-suggest',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'audit',
                'args' => [],
            ],
            [
                'command' => '/audit-add-to-project',
                'description' => 'Thêm bài/keyword audit vào Content Project.',
                'example' => '/audit-add-to-project --project-id=31 1,3',
                'skill_key' => 'content_project.add_items',
                'capability_key' => 'content_project.add_items',
                'local_only' => false,
                'category' => 'audit',
                'args' => [
                    self::projectArg(),
                    ['flags' => [], 'key' => 'keywords_tokens', 'label' => 'Items', 'required' => true, 'type' => 'keywords', 'positional' => true],
                ],
            ],

            // --- Site ---
            [
                'command' => '/site-list',
                'description' => 'Liệt kê site trong phạm vi tài khoản.',
                'example' => '/site-list',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'site',
                'args' => [],
            ],
            [
                'command' => '/site-switch',
                'description' => 'Chuyển context sang site khác.',
                'example' => '/site-switch --domain="example.com"',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'site',
                'args' => [
                    ['flags' => ['--site-id', '--site'], 'key' => 'site_id', 'label' => 'Site ID', 'required' => false, 'type' => 'string'],
                    ['flags' => ['--domain'], 'key' => 'domain', 'label' => 'Domain', 'required' => false, 'type' => 'string'],
                ],
            ],
            [
                'command' => '/site-info',
                'description' => 'Xem thông tin site hiện tại.',
                'example' => '/site-info',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'site',
                'args' => [],
            ],
            [
                'command' => '/site-health',
                'description' => 'Kiểm tra sức khỏe website hiện tại.',
                'example' => '/site-health',
                'skill_key' => 'operations.site_health',
                'capability_key' => 'content_project.get_site_health',
                'local_only' => false,
                'category' => 'site',
                'args' => [
                    ['flags' => ['--refresh'], 'key' => 'refresh', 'label' => 'Refresh live', 'required' => false, 'type' => 'boolean'],
                ],
            ],
            [
                'command' => '/site-sync',
                'description' => 'Đồng bộ WordPress site hiện tại.',
                'example' => '/site-sync',
                'skill_key' => 'site.sync',
                'capability_key' => 'site.sync',
                'local_only' => false,
                'category' => 'site',
                'args' => [
                    ['flags' => ['--force'], 'key' => 'force_snapshot', 'label' => 'Force snapshot', 'required' => false, 'type' => 'boolean'],
                ],
            ],
            [
                'command' => '/site-sync-keywords',
                'description' => 'Đồng bộ keywords từ WordPress.',
                'example' => '/site-sync-keywords',
                'skill_key' => 'site.sync_keywords',
                'capability_key' => 'site.sync_keywords',
                'local_only' => false,
                'category' => 'site',
                'args' => [],
            ],
            [
                'command' => '/site-sync-links',
                'description' => 'Đồng bộ URL catalog / links.',
                'example' => '/site-sync-links',
                'skill_key' => 'site.sync_links',
                'capability_key' => 'site.sync_links',
                'local_only' => false,
                'category' => 'site',
                'args' => [],
            ],
            [
                'command' => '/site-refresh-snapshot',
                'description' => 'Force full snapshot sync.',
                'example' => '/site-refresh-snapshot',
                'skill_key' => 'site.refresh_snapshot',
                'capability_key' => 'site.refresh_snapshot',
                'local_only' => false,
                'category' => 'site',
                'args' => [],
            ],

            // --- Core ---
            [
                'command' => '/help',
                'description' => 'Xem các lệnh Agent theo nhóm.',
                'example' => '/help',
                'skill_key' => 'general.help',
                'capability_key' => 'agent.help',
                'local_only' => true,
                'category' => 'core',
                'args' => [],
            ],
            [
                'command' => '/new-chat',
                'description' => 'Tạo cuộc hội thoại mới.',
                'example' => '/new-chat',
                'skill_key' => 'general.new_chat',
                'capability_key' => 'agent.new_chat',
                'local_only' => true,
                'category' => 'core',
                'args' => [],
            ],
            [
                'command' => '/context',
                'description' => 'Xem context site/project hiện tại.',
                'example' => '/context',
                'skill_key' => null,
                'capability_key' => null,
                'local_only' => true,
                'category' => 'core',
                'args' => [],
            ],
            [
                'command' => '/daily-report',
                'description' => 'Báo cáo vận hành hôm nay.',
                'example' => '/daily-report',
                'skill_key' => 'operations.daily_report',
                'capability_key' => 'content_project.get_daily_report',
                'local_only' => false,
                'category' => 'operation',
                'args' => [],
            ],
            [
                'command' => '/operation-status',
                'description' => 'Xem trạng thái một operation.',
                'example' => '/operation-status --operation=""',
                'skill_key' => 'operations.operation_status',
                'capability_key' => 'content_project.get_operation',
                'local_only' => false,
                'category' => 'operation',
                'args' => [
                    ['flags' => ['--operation'], 'key' => 'operation_ref', 'label' => 'Operation ref', 'required' => true, 'type' => 'string'],
                ],
            ],
        ];
    }

    /**
     * Frontend command palette payload (static, no Livewire round-trip).
     *
     * @return list<array{
     *   name: string,
     *   description: string,
     *   example: string,
     *   template: string,
     *   arguments: list<array{name: string, required: bool, type: string}>
     * }>
     */
    public static function toFrontendCatalog(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $arguments = [];
            foreach ($row['args'] as $arg) {
                $flag = $arg['flags'][0] ?? ('--'.(string) ($arg['key'] ?? ''));
                $name = ltrim((string) $flag, '-');
                if ($name === '' && (bool) ($arg['positional'] ?? false)) {
                    $name = (string) ($arg['key'] ?? 'positional');
                }
                $arguments[] = [
                    'name' => $name,
                    'required' => (bool) ($arg['required'] ?? false),
                    'type' => (string) ($arg['type'] ?? 'string'),
                ];
            }

            $out[] = [
                'name' => $row['command'],
                'description' => $row['description'],
                'example' => $row['example'],
                'template' => self::buildTemplate($row),
                'arguments' => $arguments,
                'local_only' => (bool) ($row['local_only'] ?? false),
                'capability_key' => $row['capability_key'] ?? null,
                'group' => self::groupLabel((string) ($row['category'] ?? 'core')),
                'category' => (string) ($row['category'] ?? 'core'),
            ];
        }

        return $out;
    }

    public static function groupLabel(string $category): string
    {
        return match ($category) {
            'core' => 'Core',
            'site' => 'Site',
            'project' => 'Project',
            'member' => 'Member',
            'keyword' => 'Keyword',
            'audit' => 'Audit',
            'publishing' => 'Publishing',
            'operation' => 'Operation',
            default => ucfirst($category),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function indexByCommand(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $out[$row['command']] = $row;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function search(string $query): array
    {
        $q = strtolower(trim($query));
        $all = self::all();

        if ($q === '' || $q === '/') {
            return $all;
        }

        $needle = ltrim($q, '/');

        return array_values(array_filter(
            $all,
            static function (array $row) use ($needle): bool {
                $cmd = ltrim($row['command'], '/');
                if (str_starts_with($cmd, $needle)) {
                    return true;
                }
                if (str_contains(strtolower($row['description']), $needle)) {
                    return true;
                }
                if (str_contains(strtolower($row['category']), $needle)) {
                    return true;
                }

                return false;
            },
        ));
    }

    public static function get(string $command): ?array
    {
        $command = self::normalizeCommand($command);

        return self::indexByCommand()[$command] ?? null;
    }

    public static function normalizeCommand(string $raw): string
    {
        $t = trim($raw);
        if ($t === '') {
            return '';
        }
        if (! str_starts_with($t, '/')) {
            $t = '/'.$t;
        }

        return strtolower($t);
    }

    /**
     * Build composer template with empty placeholders for Tab navigation.
     */
    public static function buildTemplate(array $definition): string
    {
        $parts = [$definition['command']];

        $required = [];
        $optional = [];
        foreach ($definition['args'] as $arg) {
            if ((bool) ($arg['positional'] ?? false)) {
                continue;
            }
            if ((string) ($arg['type'] ?? '') === 'boolean') {
                // Boolean flags are opt-in at runtime (--refresh / --force), not placeholders.
                continue;
            }
            $flag = $arg['flags'][0] ?? ('--'.$arg['key']);
            $segment = $flag.'=""';
            if ((bool) ($arg['required'] ?? false)) {
                $required[] = $segment;
            } else {
                $optional[] = $segment;
            }
        }

        foreach ($required as $segment) {
            $parts[] = $segment;
        }
        foreach ($optional as $segment) {
            $parts[] = $segment;
        }

        // Positional args hint at end for keyword commands.
        foreach ($definition['args'] as $arg) {
            if ((bool) ($arg['positional'] ?? false)) {
                $parts[] = '1,3,"keyword mới"';
                break;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{flags: list<string>, key: string, label: string, required: bool, type: string}
     */
    private static function projectArg(): array
    {
        return [
            'flags' => ['--project-id', '-p'],
            'key' => 'project_ref',
            'label' => 'Content Project',
            'required' => true,
            'type' => 'project',
        ];
    }
}
