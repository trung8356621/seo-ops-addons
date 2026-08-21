/**
 * Phase 6C.1 — shell-owned dock items (NOT runtime sidebar registry).
 * Publishing / Article info stay Laravel/Alpine panels.
 */

/** @type {ReadonlyArray<object>} */
export const SHELL_BOUNDARY_NAV_ITEMS = Object.freeze([
    {
        id: 'publishing',
        panelId: 'publishing',
        label: 'Publishing',
        fullLabel: 'Publishing Assistant',
        order: 900,
        shell: true,
        keywords: ['publish', 'publishing', 'sync', 'wordpress', 'schedule'],
    },
    {
        id: 'article',
        panelId: 'article',
        label: 'Trạng thái',
        fullLabel: 'Trạng thái bài viết',
        order: 910,
        shell: true,
        keywords: ['article', 'info', 'slug', 'status', 'trạng thái', 'schedule', 'author'],
    },
]);
