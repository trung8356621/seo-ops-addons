/**
 * Article Editor content lifecycle — WP cache / auto-fetch vs empty new article.
 * Server SoT: core bootstrap `contentLifecycle`.
 * WP-backed + no body/cache → CONTENT_LOADING (client auto-fetches).
 * SYNC_REQUIRED is retired from the happy path (normalized to CONTENT_LOADING).
 */

export const CONTENT_LIFECYCLE = Object.freeze({
    CONTENT_LOADING: 'CONTENT_LOADING',
    EDITABLE: 'EDITABLE',
    SYNC_REQUIRED: 'SYNC_REQUIRED',
    NEW_EMPTY_ARTICLE: 'NEW_EMPTY_ARTICLE',
    ERROR: 'ERROR',
});

export const ARTICLE_EDITOR_CONTENT_LIFECYCLE_EVENT = 'article-editor-content-lifecycle-changed';

function canonicalizeState(state) {
    const value = String(state ?? '').trim();
    if (value === CONTENT_LIFECYCLE.SYNC_REQUIRED) {
        return CONTENT_LIFECYCLE.CONTENT_LOADING;
    }

    return value || CONTENT_LIFECYCLE.CONTENT_LOADING;
}

/**
 * @param {unknown} raw
 * @returns {{
 *   state: string,
 *   wordpress_linked: boolean,
 *   local_content_present: boolean,
 *   wp_post_id: number,
 *   observed_permalink: string|null,
 *   allow_fetch_from_wordpress: boolean,
 * }}
 */
export function normalizeContentLifecyclePayload(raw) {
    const src = raw && typeof raw === 'object' ? raw : {};
    const state = canonicalizeState(src.state);

    return {
        state,
        wordpress_linked: Boolean(src.wordpress_linked ?? src.wordpressLinked),
        local_content_present: Boolean(src.local_content_present ?? src.localContentPresent),
        wp_post_id: Math.max(0, Number(src.wp_post_id ?? src.wpPostId ?? 0) || 0),
        observed_permalink: (() => {
            const value = String(src.observed_permalink ?? src.observedPermalink ?? '').trim();
            return value !== '' ? value : null;
        })(),
        allow_fetch_from_wordpress: Boolean(
            src.allow_fetch_from_wordpress ?? src.allowFetchFromWordPress,
        ),
    };
}

/**
 * @param {Partial<ReturnType<typeof normalizeContentLifecyclePayload>>} partial
 * @returns {ReturnType<typeof normalizeContentLifecyclePayload>}
 */
export function emitContentLifecycle(partial = {}) {
    const current = window.__SEO_EDITOR_CONTENT_LIFECYCLE__ && typeof window.__SEO_EDITOR_CONTENT_LIFECYCLE__ === 'object'
        ? window.__SEO_EDITOR_CONTENT_LIFECYCLE__
        : normalizeContentLifecyclePayload({});
    const next = normalizeContentLifecyclePayload({ ...current, ...partial });
    window.__SEO_EDITOR_CONTENT_LIFECYCLE__ = next;
    window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_CONTENT_LIFECYCLE_EVENT, {
        detail: { ...next },
    }));

    return next;
}

/**
 * @returns {ReturnType<typeof normalizeContentLifecyclePayload>}
 */
export function getContentLifecycle() {
    if (window.__SEO_EDITOR_CONTENT_LIFECYCLE__ && typeof window.__SEO_EDITOR_CONTENT_LIFECYCLE__ === 'object') {
        return normalizeContentLifecyclePayload(window.__SEO_EDITOR_CONTENT_LIFECYCLE__);
    }

    return normalizeContentLifecyclePayload({ state: CONTENT_LIFECYCLE.CONTENT_LOADING });
}

/**
 * @param {string} [state]
 * @returns {boolean}
 */
export function isContentLifecycleEditable(state = getContentLifecycle().state) {
    const canonical = canonicalizeState(state);

    return canonical === CONTENT_LIFECYCLE.EDITABLE
        || canonical === CONTENT_LIFECYCLE.NEW_EMPTY_ARTICLE;
}

/**
 * Retired happy-path blocker. Always false after SYNC_REQUIRED → CONTENT_LOADING map.
 *
 * @param {string} [state]
 * @returns {boolean}
 */
export function isContentSyncRequired(state = getContentLifecycle().state) {
    return canonicalizeState(state) === CONTENT_LIFECYCLE.SYNC_REQUIRED;
}

/**
 * Pure client resolver — only when bootstrap facts are known (load completed).
 *
 * @param {{
 *   loadCompleted?: boolean,
 *   error?: boolean,
 *   wordpressLinked?: boolean,
 *   localContentPresent?: boolean,
 * }} facts
 * @returns {string}
 */
export function resolveContentLifecycleFromFacts(facts = {}) {
    if (facts.error === true) {
        return CONTENT_LIFECYCLE.ERROR;
    }
    if (facts.loadCompleted !== true) {
        return CONTENT_LIFECYCLE.CONTENT_LOADING;
    }
    if (facts.wordpressLinked === true && facts.localContentPresent !== true) {
        return CONTENT_LIFECYCLE.CONTENT_LOADING;
    }
    if (facts.wordpressLinked !== true && facts.localContentPresent !== true) {
        return CONTENT_LIFECYCLE.NEW_EMPTY_ARTICLE;
    }

    return CONTENT_LIFECYCLE.EDITABLE;
}
