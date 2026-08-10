/**
 * Phase 6A — canonical slot names (existing UI positions only).
 */

export const EDITOR_RUNTIME_SLOTS = Object.freeze([
    'toolbar.primary',
    'toolbar.formatting',
    'toolbar.insert',
    'toolbar.media',
    'sidebar.main',
    'sidebar.secondary',
    'inspector.node',
    'inspector.document',
    'bubble.link',
    'bubble.image',
    'context.block',
    'footer.status',
    'footer.actions',
    'header.actions',
    'modal.registry',
]);

/**
 * @param {string} name
 * @returns {boolean}
 */
export function isKnownEditorRuntimeSlot(name) {
    return EDITOR_RUNTIME_SLOTS.includes(String(name ?? ''));
}
