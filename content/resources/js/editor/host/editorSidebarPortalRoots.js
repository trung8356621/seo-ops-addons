/**
 * Live DOM portal targets for editor-hosted sidebar panels.
 * Stale React state can point at detached Livewire/Alpine nodes — always prefer
 * a connected element, and recreate the mount node inside the panel slot if lost.
 */

/** @type {Readonly<Record<string, string>>} */
export const EDITOR_SIDEBAR_PORTAL_ROOT_IDS = Object.freeze({
    seo: 'seo-article-seo-assistant-root',
    image: 'seo-article-image-assistant-root',
    images: 'seo-article-image-assistant-root',
    reviews: 'seo-article-reviews-assistant-root',
    links: 'seo-article-links-root',
    vocabulary: 'seo-article-vocabulary-root',
    faq: 'seo-article-faq-root',
    featured: 'seo-article-featured-root',
    aiChat: 'seo-article-ai-chat-root',
    'ai-chat': 'seo-article-ai-chat-root',
});

/**
 * @param {string} rootKey
 * @param {string} [panelId]
 * @returns {string|null}
 */
export function resolvePortalRootElementId(rootKey, panelId = '') {
    const key = String(rootKey || '').trim();
    const panel = String(panelId || '').trim();
    return EDITOR_SIDEBAR_PORTAL_ROOT_IDS[key]
        || EDITOR_SIDEBAR_PORTAL_ROOT_IDS[panel]
        || null;
}

/**
 * @param {string} rootKey
 * @param {string} [panelId]
 * @param {Element|null} [preferred]
 * @returns {HTMLElement|null}
 */
export function ensureEditorSidebarPortalRoot(rootKey, panelId = '', preferred = null) {
    if (typeof document === 'undefined') {
        return null;
    }

    if (preferred instanceof HTMLElement && preferred.isConnected) {
        return preferred;
    }

    const elementId = resolvePortalRootElementId(rootKey, panelId);
    if (!elementId) {
        return null;
    }

    const existing = document.getElementById(elementId);
    if (existing instanceof HTMLElement && existing.isConnected) {
        return existing;
    }

    const slotPanelId = String(panelId || rootKey || '').trim();
    const slot = slotPanelId
        ? document.querySelector(`[data-assistant-panel-root="${slotPanelId}"]`)
        : null;
    if (!(slot instanceof HTMLElement)) {
        return null;
    }

    const mount = document.createElement('div');
    mount.id = elementId;
    mount.setAttribute('wire:ignore', '');
    slot.appendChild(mount);
    return mount;
}
