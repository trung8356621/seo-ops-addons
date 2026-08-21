/**
 * Article Editor network connectivity — runtime only (no storage / offline queue).
 *
 * States:
 * - available
 * - unavailable (browser offline or backend unreachable)
 * - recovering (backend reachable again; optional one-shot autosave)
 */

export const ARTICLE_EDITOR_NETWORK_STATUS_EVENT = 'article-editor:network-status';
export const ARTICLE_EDITOR_NETWORK_FAILURE_EVENT = 'article-editor:network-failure';
export const ARTICLE_EDITOR_NETWORK_HTTP_OK_EVENT = 'article-editor:network-http-ok';

/** @typedef {'available' | 'unavailable' | 'recovering'} ArticleEditorNetworkStatus */

/**
 * True only when request never reached server (no HTTP response).
 * HTTP 4xx/5xx must return false — keep app error flows.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
export function isArticleEditorNetworkError(error) {
    if (error == null || typeof error !== 'object') {
        return false;
    }

    const err = /** @type {Record<string, unknown>} */ (error);

    if (err.name === 'AbortError') {
        return false;
    }

    const sessionStatus = Number(
        /** @type {{ status?: unknown }} */ (err.sessionError)?.status ?? 0,
    );
    if (Number.isFinite(sessionStatus) && sessionStatus > 0) {
        return false;
    }

    const status = Number(err.status ?? 0);
    if (Number.isFinite(status) && status > 0) {
        return false;
    }

    if (err.response != null && typeof err.response === 'object') {
        const httpStatus = Number(/** @type {{ status?: unknown }} */ (err.response).status ?? 0);
        if (Number.isFinite(httpStatus) && httpStatus > 0) {
            return false;
        }
        // Axios: response present even without status → still HTTP-layer.
        if ('status' in /** @type {object} */ (err.response) || 'data' in /** @type {object} */ (err.response)) {
            return false;
        }
    }

    if (err.isAxiosError === true && err.response == null) {
        return true;
    }

    if (err.code === 'network_error' || err.code === 'ERR_NETWORK' || err.code === 'ECONNABORTED') {
        // ECONNABORTED may be timeout — treat as unreachable.
        return true;
    }

    const message = String(err.message ?? '').toLowerCase();
    if (
        message.includes('failed to fetch')
        || message.includes('networkerror')
        || message.includes('network error')
        || message.includes('load failed')
        || message.includes('fetch failed')
        || (message.includes('connection') && message.includes('refused'))
    ) {
        return true;
    }

    if (err.name === 'TypeError' && message !== '') {
        // Browser fetch network failure is typically TypeError("Failed to fetch").
        return message.includes('fetch') || message.includes('network');
    }

    return false;
}

/**
 * @param {ArticleEditorNetworkStatus} status
 * @param {{ phase?: string, message?: string }} [extra]
 */
export function emitArticleEditorNetworkStatus(status, extra = {}) {
    const available = status === 'available';
    const detail = {
        status,
        available,
        unavailable: status === 'unavailable',
        recovering: status === 'recovering',
        ...extra,
    };

    if (typeof window !== 'undefined') {
        window.__SEO_EDITOR_NETWORK_STATUS__ = detail;
        window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_NETWORK_STATUS_EVENT, { detail }));
    }

    return detail;
}

/**
 * @param {unknown} error
 */
export function emitArticleEditorNetworkFailure(error) {
    if (!isArticleEditorNetworkError(error)) {
        return false;
    }

    if (typeof window !== 'undefined') {
        window.dispatchEvent(
            new CustomEvent(ARTICLE_EDITOR_NETWORK_FAILURE_EVENT, {
                detail: { error },
            }),
        );
    }

    return true;
}

export function emitArticleEditorNetworkHttpOk() {
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_NETWORK_HTTP_OK_EVENT, { detail: {} }));
    }
}

/**
 * @param {{
 *   articleId: number,
 *   verifyReachability: () => Promise<boolean>,
 *   onRecoveringAutosave?: (revisionKey: string) => void,
 *   getDirtyRevisionKey?: () => string | null,
 *   onStatusChange?: (detail: ReturnType<typeof emitArticleEditorNetworkStatus>) => void,
 * }} options
 */
export function createArticleEditorNetworkMonitor(options) {
    const articleId = Number(options.articleId) || 0;
    /** @type {ArticleEditorNetworkStatus} */
    let status = 'available';
    /** @type {Promise<boolean> | null} */
    let verifyPromise = null;
    /** @type {string | null} */
    let reconnectAutosaveRevision = null;
    let destroyed = false;

    const publish = (next, extra = {}) => {
        if (destroyed) {
            return status;
        }
        status = next;
        const detail = emitArticleEditorNetworkStatus(next, extra);
        options.onStatusChange?.(detail);
        return next;
    };

    const markUnavailable = (reason = 'network') => {
        if (destroyed) {
            return;
        }
        reconnectAutosaveRevision = null;
        if (status === 'unavailable') {
            // Keep single persistent warning — re-emit so late listeners sync.
            publish('unavailable', { reason, deduped: true });
            return;
        }
        publish('unavailable', { reason });
    };

    const beginRecoveringIfDirty = () => {
        if (destroyed) {
            return;
        }
        const revisionKey = options.getDirtyRevisionKey?.() ?? null;
        if (!revisionKey) {
            reconnectAutosaveRevision = null;
            publish('available', { reason: 'reachable_clean' });
            return;
        }

        publish('recovering', { reason: 'reachable_dirty', revisionKey });

        if (reconnectAutosaveRevision === revisionKey) {
            return;
        }
        reconnectAutosaveRevision = revisionKey;
        options.onRecoveringAutosave?.(revisionKey);
    };

    const markReachable = (reason = 'http_ok') => {
        if (destroyed) {
            return;
        }
        if (status === 'available') {
            return;
        }
        beginRecoveringIfDirty();
        if (status === 'available') {
            publish('available', { reason });
        }
    };

    const verifyOnce = async () => {
        if (destroyed) {
            return false;
        }
        if (verifyPromise) {
            return verifyPromise;
        }

        verifyPromise = (async () => {
            try {
                const ok = await options.verifyReachability();
                if (destroyed) {
                    return false;
                }
                if (ok) {
                    beginRecoveringIfDirty();
                    return true;
                }
                markUnavailable('verify_failed');
                return false;
            } catch (error) {
                if (isArticleEditorNetworkError(error)) {
                    markUnavailable('verify_threw');
                    return false;
                }
                // Got a non-network application error → backend reachable.
                beginRecoveringIfDirty();
                return true;
            } finally {
                verifyPromise = null;
            }
        })();

        return verifyPromise;
    };

    const onBrowserOffline = () => {
        markUnavailable('browser_offline');
    };

    const onBrowserOnline = () => {
        // Browser online ≠ backend reachable.
        void verifyOnce();
    };

    const onNetworkFailure = (event) => {
        const error = event?.detail?.error;
        if (isArticleEditorNetworkError(error) || error == null) {
            markUnavailable('api_network_failure');
        }
    };

    const onHttpOk = () => {
        if (status === 'unavailable') {
            markReachable('http_ok');
        }
    };

    if (typeof window !== 'undefined') {
        window.addEventListener('offline', onBrowserOffline);
        window.addEventListener('online', onBrowserOnline);
        window.addEventListener(ARTICLE_EDITOR_NETWORK_FAILURE_EVENT, onNetworkFailure);
        window.addEventListener(ARTICLE_EDITOR_NETWORK_HTTP_OK_EVENT, onHttpOk);

        if (typeof navigator !== 'undefined' && navigator.onLine === false) {
            markUnavailable('browser_offline_initial');
        } else {
            publish('available', { reason: 'init', articleId });
        }
    }

    return {
        getStatus: () => status,
        isUnavailable: () => status === 'unavailable',
        isRecovering: () => status === 'recovering',
        isAvailable: () => status === 'available',
        markUnavailable,
        markRecoveringClear: () => {
            if (destroyed) {
                return;
            }
            reconnectAutosaveRevision = null;
            publish('available', { reason: 'autosave_ok' });
        },
        /**
         * Content changed after reconnect save started — allow normal autosave for new revision.
         * Does not clear unavailable.
         */
        noteLocalRevisionChanged: () => {
            // New dirty revision must not be blocked by previous reconnect key.
            reconnectAutosaveRevision = null;
        },
        verifyOnce,
        destroy: () => {
            destroyed = true;
            verifyPromise = null;
            if (typeof window !== 'undefined') {
                window.removeEventListener('offline', onBrowserOffline);
                window.removeEventListener('online', onBrowserOnline);
                window.removeEventListener(ARTICLE_EDITOR_NETWORK_FAILURE_EVENT, onNetworkFailure);
                window.removeEventListener(ARTICLE_EDITOR_NETWORK_HTTP_OK_EVENT, onHttpOk);
            }
        },
    };
}

/**
 * Lightweight reachability check — any HTTP response means backend reachable.
 * Prefer edit-lease renew; fallback to lightweight settings GET.
 *
 * @param {number} articleId
 * @param {(url: string, options?: RequestInit) => Promise<{ response: Response, data: unknown }>} apiFetch
 * @returns {Promise<boolean>}
 */
export async function verifyArticleEditorBackendReachable(articleId, apiFetch) {
    const id = Number(articleId) || 0;
    if (id <= 0 || typeof apiFetch !== 'function') {
        return false;
    }

    const client = typeof window !== 'undefined' ? window.__seoEditorSessionClient : null;
    const sessionId = client?.sessionId ? String(client.sessionId) : '';

    try {
        if (sessionId !== '') {
            const { data } = await apiFetch(`/api/seo/articles/${id}/edit-lease/${sessionId}`, {
                method: 'PUT',
                body: JSON.stringify({}),
            });
            client?.markLeaseRenewed?.(data?.expires_at);
            return true;
        }

        await apiFetch(`/api/seo/articles/${id}/editor/settings`, {
            method: 'GET',
        });
        return true;
    } catch (error) {
        if (isArticleEditorNetworkError(error)) {
            return false;
        }
        // Application/HTTP error still proves TCP+HTTP path works.
        return true;
    }
}
