/** Keyboard shortcuts for the article editor page. */
export const ARTICLE_EDITOR_SHORTCUT_GROUPS = [
    {
        id: 'article',
        label: 'Bài viết',
        items: [
            { keys: ['Ctrl', 'S'], desc: 'Lưu bài viết' },
            { keys: ['Ctrl', 'Shift', 'S'], desc: 'Đồng bộ WordPress' },
            { keys: ['Ctrl', 'Shift', 'P'], desc: 'Xem trước bài viết' },
            { keys: ['Ctrl', 'Shift', 'E'], desc: 'Mở / ẩn mô tả SEO' },
            { keys: ['Ctrl', 'Shift', 'A'], desc: 'Phân tích SEO' },
        ],
    },
    {
        id: 'edit',
        label: 'Chỉnh sửa nội dung',
        items: [
            { keys: ['Ctrl', 'Z'], desc: 'Hoàn tác' },
            { keys: ['Ctrl', 'Y'], desc: 'Làm lại' },
            { keys: ['Ctrl', 'Shift', 'Z'], desc: 'Làm lại (thay thế)' },
        ],
    },
];

/**
 * @param {KeyboardEvent} event
 * @returns {'save'|'sync'|'preview'|'toggle-seo'|'analyze'|null}
 */
export function articleShortcutActionFromEvent(event) {
    const mod = event.ctrlKey || event.metaKey;
    if (!mod || event.altKey) {
        return null;
    }

    const key = String(event.key || '').toLowerCase();

    if (key === 's') {
        return event.shiftKey ? 'sync' : 'save';
    }
    if (key === 'p' && event.shiftKey) {
        return 'preview';
    }
    if (key === 'e' && event.shiftKey) {
        return 'toggle-seo';
    }
    if (key === 'a' && event.shiftKey) {
        return 'analyze';
    }

    return null;
}
