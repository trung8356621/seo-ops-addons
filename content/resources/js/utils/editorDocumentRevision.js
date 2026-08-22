/**
 * Canonical client document_version authority.
 *
 * Server SoT remains articles.document_version. This module only tracks the
 * last version this Editor tab has acknowledged from its own successful
 * document-domain mutations. Lease/heartbeat must observe, never adopt.
 *
 * Revisions are comparable positive integers — application is monotonic.
 */

/** @type {number} */
let boundArticleId = 0;
/** @type {number} */
let confirmedVersion = 0;
/** @type {{ source: string|null, requestId: string|null, at: number, version: number }} */
let lastAck = { source: null, requestId: null, at: 0, version: 0 };
/** @type {{ source: string|null, at: number, version: number }} */
let lastObserved = { source: null, at: 0, version: 0 };

function nowIso() {
    return new Date().toISOString();
}

function readWindowVersion() {
    if (typeof window === 'undefined') {
        return 0;
    }

    return Math.max(
        0,
        Number(window.__SEO_EDITOR_DOCUMENT_VERSION__) || 0,
        Number(window.__seoEditorSessionClient?.documentVersion) || 0,
    );
}

function memoryVersion() {
    return Math.max(confirmedVersion, readWindowVersion(), 0);
}

/**
 * @param {string} event
 * @param {Record<string, unknown>} [fields]
 */
export function logEditorDocumentRevision(event, fields = {}) {
    const payload = {
        event,
        article_id: boundArticleId || Number(
            typeof window !== 'undefined' ? window.__SEO_ARTICLE_ID__ : 0,
        ) || 0,
        confirmed_revision: memoryVersion() || null,
        last_ack_source: lastAck.source,
        last_ack_request_id: lastAck.requestId,
        timestamp: nowIso(),
        ...fields,
    };
    if (typeof window !== 'undefined') {
        payload.editor_session_id = String(window.__SEO_EDITOR_SESSION_ID__ ?? '').slice(0, 36) || null;
    }
    const isConflict = event.includes('conflict') || event === 'external_version_observed';
    if (isConflict) {
        // Always emit conflict evidence — no article body.
        // eslint-disable-next-line no-console
        console.warn('[article-editor-revision]', payload);
        return;
    }
    if (typeof window !== 'undefined' && (window.__SEO_EDITOR_VERSION_DEBUG__ || window.__SEO_APP_DEBUG__)) {
        // eslint-disable-next-line no-console
        console.debug('[article-editor-revision]', payload);
    }
}

/**
 * Bind revision tracking to the open article (resets across article navigation).
 *
 * @param {number|string} articleId
 * @param {number|string|null} [initialVersion]
 * @returns {number}
 */
export function bindEditorDocumentRevision(articleId, initialVersion = 1) {
    const id = Number(articleId) || 0;
    const incoming = Math.max(1, Number(initialVersion) || 1);
    if (id !== boundArticleId) {
        boundArticleId = id;
        confirmedVersion = incoming;
        lastAck = {
            source: 'bind',
            requestId: null,
            at: Date.now(),
            version: confirmedVersion,
        };
        if (typeof window !== 'undefined') {
            window.__SEO_EDITOR_DOCUMENT_VERSION__ = confirmedVersion;
        }

        return confirmedVersion;
    }

    acknowledgeDocumentVersion(incoming, { source: 'bind' });

    return confirmedVersion;
}

/**
 * @returns {number|null} Confirmed revision, or null when unbound.
 */
export function getConfirmedDocumentVersion() {
    const current = memoryVersion();

    return current > 0 ? current : null;
}

/**
 * @returns {{ source: string|null, requestId: string|null, at: number, version: number }}
 */
export function getLastDocumentVersionAck() {
    return { ...lastAck };
}

/**
 * Apply a version returned by a successful document-domain mutation from this tab.
 * Never moves backwards.
 *
 * @param {number|string|null|undefined} version
 * @param {{ source?: string, requestId?: string|null, articleId?: number }} [meta]
 * @returns {{ version: number, applied: boolean, stale: boolean }}
 */
export function acknowledgeDocumentVersion(version, meta = {}) {
    const incoming = Number(version);
    const current = memoryVersion();
    if (!Number.isFinite(incoming) || incoming < 1) {
        return { version: current, applied: false, stale: false };
    }

    if (current > 0 && incoming < current) {
        logEditorDocumentRevision('stale_ack_ignored', {
            incoming_revision: incoming,
            confirmed_revision: current,
            source: meta.source ?? 'ack',
            request_id: meta.requestId ?? null,
        });

        return { version: current, applied: false, stale: true };
    }

    const applied = incoming !== current;
    confirmedVersion = incoming;
    lastAck = {
        source: String(meta.source ?? 'ack'),
        requestId: meta.requestId ?? null,
        at: Date.now(),
        version: incoming,
    };
    if (typeof window !== 'undefined') {
        window.__SEO_EDITOR_DOCUMENT_VERSION__ = incoming;
    }
    if (applied) {
        logEditorDocumentRevision('ack_version', {
            incoming_revision: incoming,
            source: lastAck.source,
            request_id: lastAck.requestId,
        });
    }

    return { version: incoming, applied, stale: false };
}

/**
 * Lease/heartbeat/read observation. Must not become the save base.
 * A higher observed version is an unexplained external advance — do not adopt.
 *
 * @param {number|string|null|undefined} version
 * @param {{ source?: string, requestId?: string|null }} [meta]
 * @returns {number} confirmed revision (unchanged)
 */
export function observeServerDocumentVersion(version, meta = {}) {
    const incoming = Number(version);
    const current = memoryVersion();
    lastObserved = {
        source: String(meta.source ?? 'observe'),
        at: Date.now(),
        version: Number.isFinite(incoming) ? incoming : 0,
    };

    if (!Number.isFinite(incoming) || incoming < 1) {
        return current;
    }

    if (current > 0 && incoming < current) {
        logEditorDocumentRevision('stale_observe_ignored', {
            incoming_revision: incoming,
            confirmed_revision: current,
            source: lastObserved.source,
            request_id: meta.requestId ?? null,
        });

        return current;
    }

    if (current > 0 && incoming > current) {
        logEditorDocumentRevision('external_version_observed', {
            incoming_revision: incoming,
            confirmed_revision: current,
            source: lastObserved.source,
            request_id: meta.requestId ?? null,
        });
    }

    return current;
}

/**
 * Attach the latest confirmed revision to the next document write.
 * Does not lower a factory value that is already ahead of memory (should not happen).
 *
 * @param {Record<string, unknown>} payload
 * @returns {Record<string, unknown>}
 */
export function stampExpectedDocumentVersion(payload) {
    if (!payload || typeof payload !== 'object') {
        return payload;
    }
    const confirmed = getConfirmedDocumentVersion();
    if (confirmed == null) {
        return payload;
    }
    const sent = Number(payload.expected_document_version) || 0;
    payload.expected_document_version = Math.max(sent, confirmed);

    return payload;
}

export function resetEditorDocumentRevisionForTests() {
    boundArticleId = 0;
    confirmedVersion = 0;
    lastAck = { source: null, requestId: null, at: 0, version: 0 };
    lastObserved = { source: null, at: 0, version: 0 };
    if (typeof window !== 'undefined') {
        delete window.__SEO_EDITOR_DOCUMENT_VERSION__;
    }
}

export default {
    bindEditorDocumentRevision,
    getConfirmedDocumentVersion,
    getLastDocumentVersionAck,
    acknowledgeDocumentVersion,
    observeServerDocumentVersion,
    stampExpectedDocumentVersion,
    logEditorDocumentRevision,
    resetEditorDocumentRevisionForTests,
};
