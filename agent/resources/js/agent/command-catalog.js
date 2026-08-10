/**
 * Static Agent CLI command catalog — client-side only.
 * Source of truth for slash palette filter (no Livewire round-trip).
 * Keep in sync with AgentCliCommandCatalog::toFrontendCatalog().
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'agent.command-catalog.v1';

    var CATALOG = [
        {
            name: '/project-list',
            description: 'Liệt kê Content Project của site hiện tại.',
            example: '/project-list',
            template: '/project-list',
            arguments: [],
            local_only: false,
            capability_key: 'content_project.list_projects',
            group: 'Project',
            category: 'project',
        },
        {
            name: '/project-view',
            description: 'Xem trạng thái và tiến độ Content Project.',
            example: '/project-view --project-id=31',
            template: '/project-view --project-id=""',
            arguments: [{ name: 'project-id', required: true, type: 'project' }],
            local_only: false,
            capability_key: 'content_project.get_status',
            group: 'Project',
            category: 'project',
        },
        {
            name: '/project-create',
            description: 'Tạo Content Project mới.',
            example: '/project-create --name="Kế hoạch tháng 8" --month="08/2026" --member-id=""',
            template: '/project-create --name="" --month="" --member-id=""',
            arguments: [
                { name: 'name', required: true, type: 'string' },
                { name: 'month', required: true, type: 'month' },
                { name: 'member-id', required: false, type: 'member' },
            ],
            local_only: false,
            capability_key: 'content_project.create',
            group: 'Project',
            category: 'project',
        },
        {
            name: '/project-edit',
            description: 'Sửa metadata Content Project.',
            example: '/project-edit --project-id=31 --name="Tên mới" --member-id=""',
            template: '/project-edit --project-id="" --name="" --member-id=""',
            arguments: [
                { name: 'project-id', required: true, type: 'project' },
                { name: 'name', required: false, type: 'string' },
                { name: 'member-id', required: false, type: 'member' },
            ],
            local_only: false,
            capability_key: 'content_project.update',
            group: 'Project',
            category: 'project',
        },
        {
            name: '/project-run',
            description: 'Chạy generate cho một Content Project.',
            example: '/project-run --project-id=31',
            template: '/project-run --project-id=""',
            arguments: [{ name: 'project-id', required: true, type: 'project' }],
            local_only: false,
            capability_key: 'content_project.generate',
            group: 'Project',
            category: 'project',
        },
        {
            name: '/project-review',
            description: 'Bắt đầu review các item của project.',
            example: '/project-review --project-id=31',
            template: '/project-review --project-id=""',
            arguments: [{ name: 'project-id', required: true, type: 'project' }],
            local_only: false,
            capability_key: 'content_project.start_review',
            group: 'Project',
            category: 'project',
        },
        {
            name: '/project-archive',
            description: 'Lưu trữ (archive) Content Project.',
            example: '/project-archive --project-id=31',
            template: '/project-archive --project-id=""',
            arguments: [{ name: 'project-id', required: true, type: 'project' }],
            local_only: false,
            capability_key: 'content_project.archive',
            group: 'Project',
            category: 'project',
        },
        {
            name: '/member-list',
            description: 'Danh sách thành viên trên tài khoản.',
            example: '/member-list',
            template: '/member-list',
            arguments: [],
            local_only: true,
            capability_key: null,
            group: 'Member',
            category: 'member',
        },
        {
            name: '/member-available',
            description: 'Thành viên sẵn sàng được gán.',
            example: '/member-available --month="08/2026"',
            template: '/member-available --month=""',
            arguments: [{ name: 'month', required: false, type: 'month' }],
            local_only: true,
            capability_key: null,
            group: 'Member',
            category: 'member',
        },
        {
            name: '/keyword-suggest',
            description: 'Gợi ý keyword theo site hiện tại (Site MCP).',
            example: '/keyword-suggest --keyword="" --limit="10" --use-site-mcp="yes"',
            template: '/keyword-suggest --keyword="" --limit="10" --use-site-mcp="yes"',
            arguments: [
                { name: 'keyword', required: false, type: 'string' },
                { name: 'limit', required: false, type: 'string' },
                { name: 'use-site-mcp', required: false, type: 'string' },
            ],
            local_only: true,
            capability_key: null,
            group: 'Keyword',
            category: 'keyword',
        },
        {
            name: '/keyword-add-to-project',
            description: 'Thêm keyword vào Content Project (index hoặc nhập tay).',
            example: '/keyword-add-to-project --project-id=31 1,3,"keyword mới"',
            template: '/keyword-add-to-project --project-id="" 1,3,"keyword mới"',
            arguments: [
                { name: 'project-id', required: true, type: 'project' },
                { name: 'keywords_tokens', required: true, type: 'keywords' },
            ],
            local_only: false,
            capability_key: 'content_project.add_items',
            group: 'Keyword',
            category: 'keyword',
        },
        {
            name: '/audit-list',
            description: 'Liệt kê bài bị SEO audit đánh dấu.',
            example: '/audit-list --post-type=""',
            template: '/audit-list --post-type=""',
            arguments: [
                { key: 'post_type', flags: ['--post-type'], label: 'Post type', required: false, type: 'text', placeholder: 'all' },
                { key: 'limit', flags: ['--limit'], label: 'Limit', required: false, type: 'number', placeholder: '50' },
            ],
            local_only: false,
            capability_key: 'seo_audit.list',
            skill_key: 'seo_audit.list',
            group: 'Audit',
            category: 'audit',
        },
        {
            name: '/audit-keyword-suggest',
            description: 'Gợi ý focus keyword cho bài audit.',
            example: '/audit-keyword-suggest',
            template: '/audit-keyword-suggest',
            arguments: [],
            local_only: true,
            capability_key: null,
            group: 'Audit',
            category: 'audit',
        },
        {
            name: '/audit-add-to-project',
            description: 'Thêm bài/keyword audit vào Content Project.',
            example: '/audit-add-to-project --project-id=31 1,3',
            template: '/audit-add-to-project --project-id="" 1,3,"keyword mới"',
            arguments: [
                { name: 'project-id', required: true, type: 'project' },
                { name: 'keywords_tokens', required: true, type: 'keywords' },
            ],
            local_only: false,
            capability_key: 'content_project.add_items',
            group: 'Audit',
            category: 'audit',
        },
        {
            name: '/site-list',
            description: 'Liệt kê site trong phạm vi tài khoản.',
            example: '/site-list',
            template: '/site-list',
            arguments: [],
            local_only: true,
            capability_key: null,
            group: 'Site',
            category: 'site',
        },
        {
            name: '/site-switch',
            description: 'Chuyển context sang site khác.',
            example: '/site-switch --domain="example.com"',
            template: '/site-switch --site-id="" --domain=""',
            arguments: [
                { name: 'site-id', required: false, type: 'string' },
                { name: 'domain', required: false, type: 'string' },
            ],
            local_only: true,
            capability_key: null,
            group: 'Site',
            category: 'site',
        },
        {
            name: '/site-info',
            description: 'Xem thông tin site hiện tại.',
            example: '/site-info',
            template: '/site-info',
            arguments: [],
            local_only: true,
            capability_key: null,
            group: 'Site',
            category: 'site',
        },
        {
            name: '/site-health',
            description: 'Kiểm tra sức khỏe website hiện tại.',
            example: '/site-health',
            template: '/site-health',
            arguments: [{ name: 'refresh', required: false, type: 'boolean' }],
            local_only: false,
            capability_key: 'content_project.get_site_health',
            group: 'Site',
            category: 'site',
        },
        {
            name: '/site-sync',
            description: 'Đồng bộ WordPress site hiện tại.',
            example: '/site-sync',
            template: '/site-sync',
            arguments: [{ name: 'force', required: false, type: 'boolean' }],
            local_only: false,
            capability_key: 'site.sync',
            group: 'Site',
            category: 'site',
        },
        {
            name: '/site-sync-keywords',
            description: 'Đồng bộ keywords từ WordPress.',
            example: '/site-sync-keywords',
            template: '/site-sync-keywords',
            arguments: [],
            local_only: false,
            capability_key: 'site.sync_keywords',
            group: 'Site',
            category: 'site',
        },
        {
            name: '/site-sync-links',
            description: 'Đồng bộ URL catalog / links.',
            example: '/site-sync-links',
            template: '/site-sync-links',
            arguments: [],
            local_only: false,
            capability_key: 'site.sync_links',
            group: 'Site',
            category: 'site',
        },
        {
            name: '/site-refresh-snapshot',
            description: 'Force full snapshot sync.',
            example: '/site-refresh-snapshot',
            template: '/site-refresh-snapshot',
            arguments: [],
            local_only: false,
            capability_key: 'site.refresh_snapshot',
            group: 'Site',
            category: 'site',
        },
        {
            name: '/help',
            description: 'Xem các lệnh Agent theo nhóm.',
            example: '/help',
            template: '/help',
            arguments: [],
            local_only: true,
            capability_key: 'agent.help',
            group: 'Core',
            category: 'core',
        },
        {
            name: '/new-chat',
            description: 'Tạo cuộc hội thoại mới.',
            example: '/new-chat',
            template: '/new-chat',
            arguments: [],
            local_only: true,
            capability_key: 'agent.new_chat',
            group: 'Core',
            category: 'core',
        },
        {
            name: '/context',
            description: 'Xem context site/project hiện tại.',
            example: '/context',
            template: '/context',
            arguments: [],
            local_only: true,
            capability_key: null,
            group: 'Core',
            category: 'core',
        },
        {
            name: '/daily-report',
            description: 'Báo cáo vận hành hôm nay.',
            example: '/daily-report',
            template: '/daily-report',
            arguments: [],
            local_only: false,
            capability_key: 'content_project.get_daily_report',
            group: 'Operation',
            category: 'operation',
        },
        {
            name: '/operation-status',
            description: 'Xem trạng thái một operation.',
            example: '/operation-status --operation=""',
            template: '/operation-status --operation=""',
            arguments: [{ name: 'operation', required: true, type: 'string' }],
            local_only: false,
            capability_key: 'content_project.get_operation',
            group: 'Operation',
            category: 'operation',
        },
    ];

    function getCatalog() {
        try {
            var cached = global.localStorage && global.localStorage.getItem(STORAGE_KEY);
            if (cached) {
                var parsed = JSON.parse(cached);
                if (Array.isArray(parsed) && parsed.length > 0 && parsed[0].name) {
                    return parsed;
                }
            }
        } catch (e) {
            // ignore storage errors
        }
        return CATALOG;
    }

    function persistCatalog(rows) {
        try {
            if (global.localStorage) {
                global.localStorage.setItem(STORAGE_KEY, JSON.stringify(rows));
            }
        } catch (e) {
            // ignore
        }
    }

    function filterCommands(query) {
        var rows = getCatalog();
        var q = String(query || '').trim().toLowerCase();
        if (q === '' || q === '/') {
            return rows.slice();
        }
        var needle = q.replace(/^\//, '');
        return rows.filter(function (row) {
            var name = String(row.name || '').toLowerCase().replace(/^\//, '');
            var desc = String(row.description || '').toLowerCase();
            return name.indexOf(needle) === 0 || name.indexOf(needle) !== -1 || desc.indexOf(needle) !== -1;
        });
    }

    function findCommand(name) {
        var normalized = String(name || '').trim().toLowerCase();
        if (normalized && normalized.charAt(0) !== '/') {
            normalized = '/' + normalized;
        }
        var rows = getCatalog();
        for (var i = 0; i < rows.length; i++) {
            if (String(rows[i].name || '').toLowerCase() === normalized) {
                return rows[i];
            }
        }
        return null;
    }

    persistCatalog(CATALOG);

    global.AgentCommandCatalog = CATALOG;
    global.AgentCommandCatalogApi = {
        storageKey: STORAGE_KEY,
        all: getCatalog,
        filter: filterCommands,
        find: findCommand,
        persist: persistCatalog,
    };
})(typeof window !== 'undefined' ? window : globalThis);
