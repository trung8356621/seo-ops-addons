/**
 * Canonical Article Editor session state event + writable mutation guard.
 * Producer: React session owner only. Shell/Alpine consume — never write back.
 */

export const ARTICLE_EDITOR_SESSION_STATE_EVENT = 'article-editor-session-state-changed';

export const EDITOR_SESSION_STATUS = Object.freeze({
    ACQUIRING: 'acquiring',
    ACTIVE: 'active',
    LOCKED: 'locked',
    READ_ONLY: 'read_only',
    EXPIRED: 'expired',
    REVOKED: 'revoked',
    TAKEN_OVER: 'taken_over',
    CONFLICT: 'conflict',
    CLOSING: 'closing',
    RELEASED: 'released',
    NETWORK_DEGRADED: 'network_degraded',
});

/**
 * @typedef {{
 *   article_id: number,
 *   session_id: string|null,
 *   status: string,
 *   writable: boolean,
 *   read_only: boolean,
 *   document_version: number,
 *   reason_code: string|null,
 *   lock?: object|null,
 * }} ArticleEditorSessionStatePayload
 */

/** @type {ArticleEditorSessionStatePayload} */
let lastState = {
    article_id: 0,
    session_id: null,
    status: EDITOR_SESSION_STATUS.ACQUIRING,
    writable: false,
    read_only: true,
    document_version: 1,
    reason_code: null,
    lock: null,
};

/**
 * @param {Partial<ArticleEditorSessionStatePayload>} partial
 * @returns {ArticleEditorSessionStatePayload}
 */
export function emitArticleEditorSessionState(partial = {}) {
    const writable = Boolean(partial.writable);
    const nextArticleId = Number(partial.article_id ?? lastState.article_id ?? 0) || 0;
    const articleChanged = nextArticleId !== lastState.article_id;
    const incomingVersion = Math.max(1, Number(partial.document_version ?? lastState.document_version) || 1);
    lastState = {
        article_id: nextArticleId,
        session_id: partial.session_id !== undefined ? partial.session_id : lastState.session_id,
        status: String(partial.status ?? lastState.status ?? EDITOR_SESSION_STATUS.READ_ONLY),
        writable,
        read_only: partial.read_only !== undefined ? Boolean(partial.read_only) : !writable,
        document_version: articleChanged
            ? incomingVersion
            : Math.max(lastState.document_version, incomingVersion),
        reason_code: partial.reason_code !== undefined ? partial.reason_code : lastState.reason_code,
        lock: partial.lock !== undefined ? partial.lock : lastState.lock,
    };

    window.__SEO_EDITOR_READ_ONLY__ = !lastState.writable || Boolean(lastState.read_only);
    window.__SEO_EDITOR_SESSION_STATUS__ = lastState.status;
    window.__SEO_EDITOR_DOCUMENT_VERSION__ = articleChanged
        ? lastState.document_version
        : (Math.max(
            lastState.document_version,
            Number(window.__SEO_EDITOR_DOCUMENT_VERSION__) || 0,
        ) || lastState.document_version);
    window.__SEO_EDITOR_SESSION_ID__ = lastState.session_id;
    window.__SEO_EDITOR_SESSION_STATE__ = lastState;

    window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_SESSION_STATE_EVENT, {
        detail: { ...lastState },
    }));

    // Compat alias for Phase 1 listeners.
    window.dispatchEvent(new CustomEvent('seo-editor-session-changed', {
        detail: { ...lastState },
    }));

    return lastState;
}

export function getArticleEditorSessionState() {
    return { ...lastState };
}

export function canMutateEditor() {
    if (window.__SEO_EDITOR_EXITING__) {
        return false;
    }
    if (window.__SEO_EDITOR_READ_ONLY__) {
        return false;
    }
    const lifecycle = window.__SEO_EDITOR_CONTENT_LIFECYCLE__;
    const lifecycleState = lifecycle && typeof lifecycle === 'object'
        ? String(lifecycle.state || '')
        : '';
    if (
        lifecycleState === 'SYNC_REQUIRED'
        || lifecycleState === 'CONTENT_LOADING'
        || lifecycleState === 'ERROR'
    ) {
        return false;
    }
    const state = window.__SEO_EDITOR_SESSION_STATE__ || lastState;
    return Boolean(state?.writable) && state?.status === EDITOR_SESSION_STATUS.ACTIVE;
}

export function assertWritableEditorSession(reasonCode = 'editor_not_writable') {
    if (canMutateEditor()) {
        return true;
    }

    window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
        detail: {
            title: 'Chỉ đọc',
            body: 'Phiên chỉnh sửa không còn quyền ghi. Không thể thay đổi nội dung.',
            status: 'warning',
            reason_code: reasonCode,
        },
    }));

    return false;
}

/**
 * @template T
 * @param {() => T} mutation
 * @param {string} [reasonCode]
 * @returns {T|undefined}
 */
export function runEditorMutation(mutation, reasonCode = 'editor_not_writable') {
    if (!assertWritableEditorSession(reasonCode)) {
        return undefined;
    }

    return mutation();
}

/**
 * Map client lock/error code → session status.
 * @param {string|null|undefined} code
 * @param {{ readOnly?: boolean, sessionId?: string|null }} [opts]
 */
export function resolveSessionStatusFromClient(code, opts = {}) {
    const normalized = String(code ?? '').trim();
    if (opts.readOnly === false && opts.sessionId) {
        return EDITOR_SESSION_STATUS.ACTIVE;
    }

    switch (normalized) {
        case 'article_editor_locked':
        case 'locked':
            return EDITOR_SESSION_STATUS.LOCKED;
        case 'article_editor_session_expired':
            return EDITOR_SESSION_STATUS.EXPIRED;
        case 'article_editor_session_revoked':
        case 'content_project_archived':
        case 'article_not_editable':
            return EDITOR_SESSION_STATUS.REVOKED;
        case 'article_editor_session_taken_over':
            return EDITOR_SESSION_STATUS.TAKEN_OVER;
        case 'article_document_version_conflict':
        case 'article_content_hash_conflict':
        case 'conflict':
            return EDITOR_SESSION_STATUS.CONFLICT;
        case 'network_error':
            return EDITOR_SESSION_STATUS.NETWORK_DEGRADED;
        case 'article_editor_session_unavailable':
        case 'unknown_error':
        case 'lost':
        case 'error':
            return EDITOR_SESSION_STATUS.NETWORK_DEGRADED;
        case 'released':
            return EDITOR_SESSION_STATUS.RELEASED;
        case 'owned':
            return opts.sessionId
                ? EDITOR_SESSION_STATUS.ACTIVE
                : EDITOR_SESSION_STATUS.READ_ONLY;
        default:
            return opts.readOnly === false
                ? EDITOR_SESSION_STATUS.ACTIVE
                : EDITOR_SESSION_STATUS.READ_ONLY;
    }
}

export default {
    ARTICLE_EDITOR_SESSION_STATE_EVENT,
    EDITOR_SESSION_STATUS,
    emitArticleEditorSessionState,
    getArticleEditorSessionState,
    canMutateEditor,
    assertWritableEditorSession,
    runEditorMutation,
    resolveSessionStatusFromClient,
};
