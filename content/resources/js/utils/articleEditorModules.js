/**
 * Phase 3 — sidebar heavy-module ids + helpers.
 * Phase 6A — panel ids also declared on built-in runtime modules (sidebar registry).
 * Publishing remains shell boundary. AI chat is runtime module (Phase 6C.4).
 * Core editor keeps only a lightweight activeModule scalar; payloads live in modules.
 *
 * IMPORTANT: do NOT statically import defaultArticleEditorRuntime here.
 * That module imports ../modules, and module panels import this file → TDZ cycle.
 */

/** Compat freeze — includes shell panels outside document runtime. */
export const HEAVY_SIDEBAR_MODULES = Object.freeze([
    'seo',
    'images',
    'reviews',
    'links',
    'vocabulary',
    'faq',
    'cta',
    'publishing',
    'ai-chat',
]);

/** Modules hosted outside SeoArticleEditor portal host (none after 6C.4 AI cutover). */
export const EXTERNAL_HOSTED_MODULES = Object.freeze([]);

/** Modules portal-mounted from runtime EditorSidebarPortalHost. */
export const EDITOR_HOSTED_MODULES = Object.freeze([
    'seo',
    'images',
    'reviews',
    'links',
    'vocabulary',
    'faq',
    'cta',
    'featured',
    'ai-chat',
]);

/**
 * Sidebar panel ids (editor-hosted + shell publishing).
 * Kept free of runtime singleton import to avoid circular init.
 *
 * @param {object} [context]
 * @returns {string[]}
 */
export function listRuntimeSidebarPanelIds(context = null) {
    void context;
    const merged = [...EDITOR_HOSTED_MODULES];
    if (!merged.includes('publishing')) {
        merged.push('publishing');
    }
    return merged;
}

export const MODULE_EVENT_ACTIVE = 'seo-editor-active-module';
export const MODULE_EVENT_SWITCH = 'seo-assistant-switch-panel';
/** Canonical open contract — FAQ shortcode / Help goto / widgets. */
export const MODULE_EVENT_OPEN = 'article-editor:module-open';
/** Links sidebar mount → ask editor to republish client existing-link scan. */
export const LINKS_RESCAN_REQUEST_EVENT = 'seo-editor-links-rescan-request';

/**
 * @param {unknown} raw
 * @returns {string|null}
 */
export function normalizeHeavyModuleId(raw) {
    const panel = String(raw ?? '').trim().toLowerCase();
    if (!panel) {
        return null;
    }

    if (panel === 'image') {
        return 'images';
    }
    if (panel === 'ai' || panel === 'ai-chat' || panel === 'aichat') {
        return 'ai-chat';
    }
    if (panel === 'publish' || panel === 'publishing') {
        return 'publishing';
    }
    // Phase 6C.3 — Featured chip hosts Featured + product Gallery UI.
    if (panel === 'featured' || panel === 'product-album' || panel === 'gallery') {
        return 'featured';
    }

    if (HEAVY_SIDEBAR_MODULES.includes(panel) || EDITOR_HOSTED_MODULES.includes(panel)) {
        return panel;
    }

    return null;
}

/**
 * @param {string|null} moduleId
 * @returns {boolean}
 */
export function isExternalHostedModule(moduleId) {
    return EXTERNAL_HOSTED_MODULES.includes(String(moduleId ?? ''));
}

/**
 * @param {string|null} moduleId
 * @returns {boolean}
 */
export function isEditorHostedModule(moduleId) {
    return EDITOR_HOSTED_MODULES.includes(String(moduleId ?? ''));
}

/**
 * Broadcast active heavy module (one at a time). Null = none.
 * @param {string|null} moduleId
 * @param {Record<string, unknown>} [extra]
 */
export function dispatchActiveModule(moduleId, extra = {}) {
    window.dispatchEvent(
        new CustomEvent(MODULE_EVENT_ACTIVE, {
            detail: { module: moduleId, ...extra },
        }),
    );
}

/**
 * @param {unknown} error
 * @returns {boolean}
 */
export function isAbortError(error) {
    if (!error || typeof error !== 'object') {
        return false;
    }
    const name = String(error.name ?? '');
    return name === 'AbortError' || name === 'CanceledError';
}
