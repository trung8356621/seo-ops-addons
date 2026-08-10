/**
 * Server-authoritative Article Editor session client.
 * React owns session state — Livewire must not duplicate.
 */
import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';

export const EDITOR_SESSION_ERROR = Object.freeze({
    LOCKED: 'article_editor_locked',
    SESSION_NOT_FOUND: 'article_editor_session_not_found',
    SESSION_EXPIRED: 'article_editor_session_expired',
    SESSION_REVOKED: 'article_editor_session_revoked',
    SESSION_TAKEN_OVER: 'article_editor_session_taken_over',
    SESSION_UNAVAILABLE: 'article_editor_session_unavailable',
    LOCK_NOT_OWNED: 'article_editor_lock_not_owned',
    DOCUMENT_VERSION_CONFLICT: 'article_document_version_conflict',
    CONTENT_HASH_CONFLICT: 'article_content_hash_conflict',
    NOT_EDITABLE: 'article_not_editable',
    CONTENT_PROJECT_ARCHIVED: 'content_project_archived',
    TAKEOVER_FORBIDDEN: 'takeover_forbidden',
});

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

function newUuid() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

function isUuid(value) {
    return typeof value === 'string' && UUID_RE.test(value.trim());
}

function clientInstanceStorageKey(articleId) {
    return `seo-editor:client-instance:${articleId}`;
}

/**
 * Stable per-tab client instance id (sessionStorage).
 * Must be a real UUID — backend Str::isUuid() rejects anything else with 422.
 */
export function getOrCreateClientInstanceId(articleId) {
    const key = clientInstanceStorageKey(articleId);
    try {
        const existing = String(sessionStorage.getItem(key) ?? '').trim();
        if (isUuid(existing)) {
            return existing.toLowerCase();
        }
        const id = newUuid().toLowerCase();
        sessionStorage.setItem(key, id);
        return id;
    } catch {
        return newUuid().toLowerCase();
    }
}

/** In-flight acquire dedupe: one POST per article+client_instance_id. */
const acquireInFlight = new Map();

/**
 * @param {string} key
 * @param {() => Promise<unknown>} factory
 */
export function runExclusiveAcquire(key, factory) {
    const existing = acquireInFlight.get(key);
    if (existing) {
        return existing;
    }
    const promise = Promise.resolve()
        .then(factory)
        .finally(() => {
            if (acquireInFlight.get(key) === promise) {
                acquireInFlight.delete(key);
            }
        });
    acquireInFlight.set(key, promise);
    return promise;
}

export function __resetAcquireInFlightForTests() {
    acquireInFlight.clear();
}

export function normalizeSessionError(data, status) {
    const code = String(data?.error ?? data?.conflict?.code ?? '').trim();
    const httpStatus = Number(status) || 0;

    if (httpStatus >= 500) {
        return {
            code: EDITOR_SESSION_ERROR.SESSION_UNAVAILABLE,
            message: String(
                data?.message
                ?? 'Editor session server error. Reload the page and try again.',
            ),
            lock: data?.lock ?? null,
            status: httpStatus,
            data,
        };
    }

    if (code) {
        return {
            code,
            message: String(data?.message ?? data?.conflict?.message ?? code),
            lock: data?.lock ?? null,
            status,
            data,
        };
    }

    if (status === 423) {
        return {
            code: EDITOR_SESSION_ERROR.LOCKED,
            message: String(data?.message ?? 'Article locked'),
            lock: data?.lock ?? null,
            status,
            data,
        };
    }

    return {
        code: 'unknown_error',
        message: String(data?.message ?? `HTTP ${status}`),
        lock: data?.lock ?? null,
        status,
        data,
    };
}

const RECOVERABLE_SESSION_LOSS = new Set([
    EDITOR_SESSION_ERROR.SESSION_EXPIRED,
    EDITOR_SESSION_ERROR.SESSION_NOT_FOUND,
]);

export class EditorSessionClient {
    /**
     * @param {{ articleId: number|string, heartbeatSeconds?: number, onStateChange?: Function }} options
     */
    constructor(options) {
        this.articleId = Number(options.articleId) || 0;
        this.heartbeatSeconds = Math.max(10, Number(options.heartbeatSeconds) || 30);
        this.clientInstanceId = getOrCreateClientInstanceId(this.articleId);
        this.sessionId = null;
        this.documentVersion = Math.max(1, Number(options.documentVersion) || 1);
        this.lockStatus = 'unknown';
        this.readOnly = true;
        this.lockInfo = null;
        this.heartbeatTimer = null;
        this.offline = false;
        this.onStateChange = typeof options.onStateChange === 'function' ? options.onStateChange : null;
        this.destroyed = false;
        this.recovering = false;
        this._visibilityHandler = null;
        this.bindVisibility();
    }

    emit() {
        if (this.onStateChange) {
            this.onStateChange(this.snapshot());
        }
    }

    snapshot() {
        return {
            sessionId: this.sessionId,
            clientInstanceId: this.clientInstanceId,
            documentVersion: this.documentVersion,
            lockStatus: this.lockStatus,
            readOnly: this.readOnly,
            lockInfo: this.lockInfo,
            offline: this.offline,
        };
    }

    setDocumentVersion(version) {
        const next = Math.max(1, Number(version) || this.documentVersion);
        if (next !== this.documentVersion) {
            this.documentVersion = next;
            this.emit();
        }
    }

    async acquire(knownDocumentVersion = null) {
        if (this.articleId <= 0) {
            this.lockStatus = 'error';
            this.readOnly = true;
            this.emit();
            return { ok: false, error: normalizeSessionError({ error: 'article_not_editable' }, 422) };
        }

        if (!isUuid(this.clientInstanceId)) {
            this.clientInstanceId = getOrCreateClientInstanceId(this.articleId);
        }

        const dedupeKey = `${this.articleId}:${this.clientInstanceId}`;

        return runExclusiveAcquire(dedupeKey, async () => {
            try {
                const { response, data } = await seoArticleApiFetch(
                    `/api/seo/articles/${this.articleId}/editor-sessions`,
                    {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            client_instance_id: this.clientInstanceId,
                            known_document_version: knownDocumentVersion ?? this.documentVersion,
                        }),
                    },
                );

                if (!response.ok) {
                    const error = normalizeSessionError(data, response.status);
                    this.sessionId = null;
                    this.lockStatus = error.code === EDITOR_SESSION_ERROR.LOCKED ? 'locked' : 'error';
                    this.readOnly = true;
                    this.lockInfo = error.lock;
                    this.offline = false;
                    this.stopHeartbeat();
                    this.emit();
                    return { ok: false, error, data };
                }

                this.sessionId = String(data?.session?.id ?? '');
                this.documentVersion = Math.max(
                    1,
                    Number(data?.article?.document_version ?? this.documentVersion) || 1,
                );
                this.lockStatus = 'owned';
                this.readOnly = false;
                this.lockInfo = null;
                this.offline = false;
                if (data?.session?.heartbeat_interval_seconds) {
                    this.heartbeatSeconds = Math.max(10, Number(data.session.heartbeat_interval_seconds) || 30);
                }
                this.startHeartbeat();
                this.emit();
                return { ok: true, data };
            } catch (error) {
                this.lockStatus = 'network_error';
                this.readOnly = true;
                this.offline = true;
                this.stopHeartbeat();
                this.emit();
                return {
                    ok: false,
                    error: {
                        code: 'network_error',
                        message: error?.message || 'Network error',
                        lock: null,
                        status: 0,
                        data: null,
                    },
                };
            }
        });
    }

    async takeover(knownDocumentVersion = null) {
        // @deprecated Exclusive lock UI does not call takeover; keep for admin/API escape hatch.
        const { response, data } = await seoArticleApiFetch(
            `/api/seo/articles/${this.articleId}/editor-sessions/takeover`,
            {
                method: 'POST',
                body: JSON.stringify({
                    client_instance_id: this.clientInstanceId,
                    known_document_version: knownDocumentVersion ?? this.documentVersion,
                    confirmation: true,
                }),
            },
        );

        if (!response.ok) {
            const error = normalizeSessionError(data, response.status);
            this.emit();
            return { ok: false, error, data };
        }

        this.sessionId = String(data?.session?.id ?? '');
        this.documentVersion = Math.max(
            1,
            Number(data?.article?.document_version ?? this.documentVersion) || 1,
        );
        this.lockStatus = 'owned';
        this.readOnly = false;
        this.lockInfo = null;
        this.startHeartbeat();
        this.emit();
        return { ok: true, data };
    }

    bindVisibility() {
        if (typeof document === 'undefined' || this._visibilityHandler) {
            return;
        }
        this._visibilityHandler = () => {
            if (this.destroyed || document.visibilityState !== 'visible') {
                return;
            }
            // Tab trở lại: thử heartbeat ngay, hoặc reclaim nếu session đã mất recoverable.
            if (this.readOnly && RECOVERABLE_SESSION_LOSS.has(String(this.lockStatus || ''))) {
                void this.recoverSession();
                return;
            }
            if (!this.readOnly && this.sessionId) {
                void this.heartbeatOnce();
            }
        };
        document.addEventListener('visibilitychange', this._visibilityHandler);
    }

    unbindVisibility() {
        if (typeof document === 'undefined' || !this._visibilityHandler) {
            return;
        }
        document.removeEventListener('visibilitychange', this._visibilityHandler);
        this._visibilityHandler = null;
    }

    startHeartbeat() {
        this.stopHeartbeat();
        if (this.readOnly || !this.sessionId || this.destroyed) {
            return;
        }

        // Keep lock alive even when tab hidden / briefly offline — skipping TTL expiry
        // was locking Save for solo editors after ~2 minutes in background.
        const tick = () => {
            void this.heartbeatOnce();
        };

        void this.heartbeatOnce();
        this.heartbeatTimer = window.setInterval(tick, this.heartbeatSeconds * 1000);
    }

    stopHeartbeat() {
        if (this.heartbeatTimer != null) {
            window.clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }

    async heartbeatOnce() {
        if (this.destroyed || this.readOnly || !this.sessionId) {
            return;
        }

        try {
            const { response, data } = await seoArticleApiFetch(
                `/api/seo/articles/${this.articleId}/editor-sessions/${this.sessionId}/heartbeat`,
                { method: 'PUT', body: JSON.stringify({}) },
            );

            if (!response.ok) {
                const error = normalizeSessionError(data, response.status);
                this.handleLostSession(error);
                return;
            }

            if (data?.document_version != null) {
                this.setDocumentVersion(data.document_version);
            }
            const wasOffline = this.offline;
            this.offline = false;
            if (wasOffline) {
                this.emit();
            }
        } catch {
            // Network blip: keep sessionId, retry on next interval (do not permanent-skip).
            this.offline = true;
            this.emit();
        }
    }

    handleLostSession(error) {
        this.stopHeartbeat();
        this.sessionId = null;
        this.readOnly = true;
        this.lockStatus = error?.code || 'lost';
        this.lockInfo = error?.lock ?? this.lockInfo;
        this.emit();

        if (String(error?.code || '') === EDITOR_SESSION_ERROR.SESSION_UNAVAILABLE
            || Number(error?.status) >= 500
            || String(error?.code || '') === 'unknown_error'
            || String(error?.code || '') === 'lost'
            || String(error?.code || '') === 'error') {
            try {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Phiên editor lỗi',
                        body: 'Máy chủ không giữ được phiên chỉnh sửa. Hãy tải lại trang rồi tiếp tục.',
                        status: 'danger',
                        reason_code: String(error?.code || EDITOR_SESSION_ERROR.SESSION_UNAVAILABLE),
                    },
                }));
            } catch {
                // ignore
            }
        }

        if (RECOVERABLE_SESSION_LOSS.has(String(error?.code || ''))) {
            void this.recoverSession();
        }
    }

    /**
     * Re-acquire after recoverable expiry/not-found. Foreign lock stays read-only.
     */
    async recoverSession() {
        if (this.destroyed || this.recovering) {
            return { ok: false, skipped: true };
        }
        this.recovering = true;
        try {
            return await this.acquire(this.documentVersion);
        } finally {
            this.recovering = false;
        }
    }

    /**
     * @param {Record<string, unknown>} bundle
     * @param {'autosave'|'explicit'} saveMode
     */
    async saveDocument(bundle, saveMode = 'explicit') {
        if (this.readOnly || !this.sessionId) {
            return {
                ok: false,
                error: {
                    code: EDITOR_SESSION_ERROR.LOCK_NOT_OWNED,
                    message: 'No writable editor session',
                    lock: this.lockInfo,
                    status: 409,
                    data: null,
                },
            };
        }

        const body = {
            ...bundle,
            expected_document_version: this.documentVersion,
            editor_session_id: this.sessionId,
            save_mode: saveMode,
        };

        const { response, data } = await seoArticleApiFetch(
            `/api/seo/articles/${this.articleId}/editor-sessions/${this.sessionId}/document`,
            {
                method: 'PUT',
                body: JSON.stringify(body),
            },
        );

        if (!response.ok) {
            const error = normalizeSessionError(data, response.status);
            if ([
                EDITOR_SESSION_ERROR.SESSION_EXPIRED,
                EDITOR_SESSION_ERROR.SESSION_REVOKED,
                EDITOR_SESSION_ERROR.SESSION_TAKEN_OVER,
                EDITOR_SESSION_ERROR.LOCK_NOT_OWNED,
            ].includes(error.code)) {
                this.handleLostSession(error);
            }
            if ([
                EDITOR_SESSION_ERROR.DOCUMENT_VERSION_CONFLICT,
                EDITOR_SESSION_ERROR.CONTENT_HASH_CONFLICT,
            ].includes(error.code)) {
                // Owning session still holds the lock — sync version/hash from conflict
                // payload when present so Fix Slug / autosave can recover without
                // unmounting the editor into ExclusiveLockScreen.
                const actualVersion = Number(
                    data?.conflict?.actual_document_version
                    ?? data?.document_version
                    ?? 0,
                );
                if (Number.isFinite(actualVersion) && actualVersion > 0) {
                    this.setDocumentVersion(actualVersion);
                }
                const actualHash = String(
                    data?.conflict?.actual_content_hash
                    ?? data?.content_hash
                    ?? '',
                ).trim();
                if (actualHash !== '' && typeof window !== 'undefined') {
                    const tokens = window.__SEO_EDITOR_CONFLICT__ || {};
                    window.__SEO_EDITOR_CONFLICT__ = {
                        ...tokens,
                        expected_content_hash: actualHash,
                    };
                }
                this.lockStatus = 'owned';
                this.readOnly = false;
                this.emit();
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Xung đột phiên bản',
                        body: 'Bài viết đã có phiên bản mới hơn. Bản đang sửa được giữ lại để phục hồi.',
                        status: 'warning',
                        reason_code: error.code,
                    },
                }));
            }
            return { ok: false, error, data, response };
        }

        if (data?.document_version != null) {
            this.setDocumentVersion(data.document_version);
        }
        if (typeof window !== 'undefined') {
            if (data?.document_version != null) {
                window.__SEO_EDITOR_DOCUMENT_VERSION__ = Math.max(
                    1,
                    Number(data.document_version) || this.documentVersion,
                );
            }
            const ackHash = String(data?.content_hash ?? '').trim();
            if (ackHash !== '') {
                const tokens = window.__SEO_EDITOR_CONFLICT__ || {};
                window.__SEO_EDITOR_CONFLICT__ = {
                    ...tokens,
                    expected_content_hash: ackHash,
                    expected_updated_at: data?.saved_at || tokens.expected_updated_at || null,
                };
            }
            const editorHash = String(data?.editor_document_hash ?? '').trim();
            if (editorHash !== '') {
                window.__SEO_EDITOR_DOCUMENT_HASH__ = editorHash;
            }
        }

        return { ok: true, data, response };
    }

    /**
     * Atomic save + release. Redirect only after ACK.
     */
    async close(bundle, closeReason = 'save_and_close') {
        if (this.readOnly || !this.sessionId) {
            return {
                ok: false,
                error: {
                    code: EDITOR_SESSION_ERROR.LOCK_NOT_OWNED,
                    message: 'No writable editor session',
                    lock: this.lockInfo,
                    status: 409,
                    data: null,
                },
            };
        }

        const body = {
            ...bundle,
            expected_document_version: this.documentVersion,
            editor_session_id: this.sessionId,
            close_reason: closeReason,
        };

        const { response, data } = await seoArticleApiFetch(
            `/api/seo/articles/${this.articleId}/editor-sessions/${this.sessionId}/close`,
            {
                method: 'POST',
                body: JSON.stringify(body),
            },
        );

        if (!response.ok) {
            const error = normalizeSessionError(data, response.status);
            return { ok: false, error, data, response };
        }

        this.stopHeartbeat();
        this.sessionId = null;
        this.lockStatus = 'released';
        this.readOnly = true;
        if (data?.document_version != null) {
            this.setDocumentVersion(data.document_version);
        }
        this.emit();

        return { ok: true, data, response };
    }

    async release() {
        if (!this.sessionId) {
            this.stopHeartbeat();
            return { ok: true };
        }

        const sessionId = this.sessionId;
        this.stopHeartbeat();
        this.sessionId = null;
        this.lockStatus = 'released';
        this.readOnly = true;
        this.emit();

        try {
            await seoArticleApiFetch(
                `/api/seo/articles/${this.articleId}/editor-sessions/${sessionId}`,
                { method: 'DELETE' },
            );
        } catch {
            // unload / best-effort
        }

        return { ok: true };
    }

    destroy() {
        this.destroyed = true;
        this.recovering = false;
        this.stopHeartbeat();
        this.unbindVisibility();
    }
}

export default EditorSessionClient;
