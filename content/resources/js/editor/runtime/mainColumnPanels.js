/**
 * Panels that mount outside the assistant sidebar rail (e.g. main-column FAQ root).
 * Opening them must NOT blank sidebar widgets / unmount SEO bodies.
 */
export const MAIN_COLUMN_ONLY_PANELS = Object.freeze(['faq']);

/**
 * @param {string|null|undefined} panelId
 * @returns {boolean}
 */
export function isMainColumnOnlyPanel(panelId) {
    return MAIN_COLUMN_ONLY_PANELS.includes(String(panelId || ''));
}
