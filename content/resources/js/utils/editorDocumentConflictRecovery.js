/**
 * Central document version conflict recovery for Article Editor.
 *
 * Recoverable: local content hash === server actual content hash → rebase
 * version tokens and allow one silent retry (no toast).
 *
 * Unrecoverable: divergent content or missing proof → BLOCKED overlay,
 * preserve local recovery draft, stop mutations.
 */

import { hashContent } from './articleEditorStorage';
import {
    acknowledgeDocumentVersion,
    getConfirmedDocumentVersion,
    logEditorDocumentRevision,
} from './editorDocumentRevision';

export const DOCUMENT_CONFLICT_STATE = Object.freeze({
    IDLE: 'idle',
    RECOVERING: 'recovering',
    BLOCKED: 'blocked',
});

export const DOCUMENT_CONFLICT_EVENT = 'seo-article-document-conflict-state';

/** @type {string} */
let conflictState = DOCUMENT_CONFLICT_STATE.IDLE;
/** @type {Record<string, unknown>} */
let blockedDetail = {};
/** @type {Promise<{ recovered: boolean, blocked: boolean }>|null} */
let inFlightRecovery = null;
let silentRetryUsed = false;

export const BOOTSTRAP_DIVERGENT_DRAFT_REASON = 'bootstrap_divergent_draft';
export const BOOTSTRAP_RECOVERY_ACTION_EVENT = 'seo-article-bootstrap-recovery-action';

/**
 * @returns {string}
 */
export function getDocumentConflictState() {
    return conflictState;
}

/**
 * @returns {boolean}
 */
export function isDocumentConflictBlocked() {
    return conflictState === DOCUMENT_CONFLICT_STATE.BLOCKED;
}

/**
 * @returns {boolean}
 */
export function isDocumentConflictBusy() {
    return conflictState === DOCUMENT_CONFLICT_STATE.RECOVERING
        || conflictState === DOCUMENT_CONFLICT_STATE.BLOCKED;
}

/**
 * @param {string} next
 * @param {Record<string, unknown>} [detail]
 */
function setConflictState(next, detail = {}) {
    conflictState = next;
    if (next === DOCUMENT_CONFLICT_STATE.BLOCKED) {
        blockedDetail = { ...detail };
    } else if (next === DOCUMENT_CONFLICT_STATE.IDLE) {
        blockedDetail = {};
    }
    if (typeof window !== 'undefined') {
        window.__SEO_EDITOR_DOCUMENT_CONFLICT__ = next === DOCUMENT_CONFLICT_STATE.BLOCKED
            || next === DOCUMENT_CONFLICT_STATE.RECOVERING;
        window.__SEO_EDITOR_DOCUMENT_CONFLICT_STATE__ = next;
        window.__SEO_EDITOR_DOCUMENT_CONFLICT_DETAIL__ = { ...blockedDetail };
        window.dispatchEvent(new CustomEvent(DOCUMENT_CONFLICT_EVENT, {
            detail: { state: next, ...detail },
        }));
    }
}

/**
 * @returns {Record<string, unknown>}
 */
export function getDocumentConflictBlockedDetail() {
    return { ...blockedDetail };
}

/**
 * Normalize 409 payloads — server historically flattens context; newer nests under conflict.
 *
 * @param {Record<string, unknown>|null|undefined} data
 * @returns {{
 *   actualVersion: number,
 *   expectedVersion: number,
 *   actualContentHash: string,
 *   expectedContentHash: string,
 * }}
 */
export function extractDocumentConflictMeta(data) {
    const nested = data && typeof data.conflict === 'object' && data.conflict !== null
        ? data.conflict
        : null;

    const actualVersion = Number(
        nested?.actual_document_version
        ?? data?.actual_document_version
        ?? data?.document_version
        ?? 0,
    ) || 0;

    const expectedVersion = Number(
        nested?.expected_document_version
        ?? data?.expected_document_version
        ?? 0,
    ) || 0;

    const actualContentHash = String(
        nested?.actual_content_hash
        ?? data?.actual_content_hash
        ?? data?.content_hash
        ?? '',
    ).trim();

    const expectedContentHash = String(
        nested?.expected_content_hash
        ?? data?.expected_content_hash
        ?? '',
    ).trim();

    return {
        actualVersion,
        expectedVersion,
        actualContentHash,
        expectedContentHash,
    };
}

/**
 * @param {{
 *   localHtmlHash: string,
 *   baseContentHash: string,
 *   actualContentHash: string,
 *   actualVersion: number,
 *   confirmedVersion: number,
 * }} input
 * @returns {{ action: 'rebase_retry'|'block', reason: string }}
 */
export function classifyDocumentConflictRecovery(input) {
    const localHtmlHash = String(input.localHtmlHash || '').trim();
    const baseContentHash = String(input.baseContentHash || '').trim();
    const actualContentHash = String(input.actualContentHash || '').trim();
    const actualVersion = Number(input.actualVersion) || 0;
    const confirmedVersion = Number(input.confirmedVersion) || 0;

    // Local body already matches server body — version skew only (lost ACK / side bump).
    if (actualContentHash !== '' && localHtmlHash !== '' && localHtmlHash === actualContentHash) {
        return { action: 'rebase_retry', reason: 'content_identical' };
    }

    if (actualVersion < 1) {
        return { action: 'block', reason: 'missing_server_version' };
    }

    if (actualContentHash === '') {
        // Without server body hash we cannot prove a safe rebase.
        return { action: 'block', reason: 'missing_server_content_hash' };
    }

    // No local edits vs last ACK, but server content advanced → reload (no draft loss).
    if (
        baseContentHash !== ''
        && localHtmlHash === baseContentHash
        && localHtmlHash !== actualContentHash
    ) {
        return { action: 'block', reason: 'server_advanced_clean_local' };
    }

    // Divergent: local dirty and differs from server.
    if (
        localHtmlHash !== ''
        && localHtmlHash !== actualContentHash
        && (baseContentHash === '' || localHtmlHash !== baseContentHash)
    ) {
        return { action: 'block', reason: 'divergent_edits' };
    }

    if (confirmedVersion > 0 && actualVersion <= confirmedVersion) {
        return { action: 'block', reason: 'stale_or_equal_server_version' };
    }

    return { action: 'block', reason: 'unclassified' };
}

/**
 * Apply server version + hash without writing body (rebase base only).
 *
 * @param {{ actualVersion: number, actualContentHash: string }} meta
 * @param {{ source?: string, requestId?: string|null }} [options]
 */
export function rebaseDocumentConflictTokens(meta, options = {}) {
    const version = Number(meta.actualVersion) || 0;
    if (version > 0) {
        acknowledgeDocumentVersion(version, {
            source: options.source ?? 'conflict_rebase',
            requestId: options.requestId ?? null,
        });
        if (typeof window !== 'undefined') {
            window.__seoEditorSessionClient?.setDocumentVersion?.(version, {
                source: options.source ?? 'conflict_rebase',
                requestId: options.requestId ?? null,
            });
            window.__SEO_EDITOR_DOCUMENT_VERSION__ = version;
        }
    }

    const hash = String(meta.actualContentHash || '').trim();
    if (hash !== '' && typeof window !== 'undefined') {
        const tokens = window.__SEO_EDITOR_CONFLICT__ && typeof window.__SEO_EDITOR_CONFLICT__ === 'object'
            ? window.__SEO_EDITOR_CONFLICT__
            : {};
        window.__SEO_EDITOR_CONFLICT__ = {
            ...tokens,
            expected_content_hash: hash,
        };
    }
}

/**
 * Enter BLOCKED: preserve draft (caller), freeze mutations, show overlay.
 *
 * @param {Record<string, unknown>} [detail]
 */
export function enterDocumentConflictBlocked(detail = {}) {
    setConflictState(DOCUMENT_CONFLICT_STATE.BLOCKED, detail);
    logEditorDocumentRevision('document_conflict_blocked', detail);
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('article-editor-save-finished', {
            detail: { conflict: true, blocked: true },
        }));
    }
}

/**
 * Clear recovering/blocked after successful silent rebase (+ optional retry).
 */
export function clearDocumentConflictRecovery() {
    silentRetryUsed = false;
    inFlightRecovery = null;
    setConflictState(DOCUMENT_CONFLICT_STATE.IDLE, { cleared: true });
}

/**
 * @param {{
 *   error: Error & { data?: Record<string, unknown>, conflict?: boolean, code?: string },
 *   getExportHtml?: () => string,
 *   persistLocalRecovery?: () => void,
 *   setSaveStatus?: (status: string) => void,
 *   retrySave?: () => Promise<unknown>,
 *   requestId?: string|null,
 * }} options
 * @returns {Promise<{ recovered: boolean, blocked: boolean, reason?: string }>}
 */
export async function recoverFromDocumentVersionConflict(options = {}) {
    if (conflictState === DOCUMENT_CONFLICT_STATE.BLOCKED) {
        options.persistLocalRecovery?.();
        return { recovered: false, blocked: true, reason: 'already_blocked' };
    }

    if (inFlightRecovery) {
        return inFlightRecovery;
    }

    inFlightRecovery = (async () => {
        setConflictState(DOCUMENT_CONFLICT_STATE.RECOVERING);
        options.persistLocalRecovery?.();
        options.setSaveStatus?.('conflict');

        const data = options.error?.data && typeof options.error.data === 'object'
            ? options.error.data
            : {};
        const meta = extractDocumentConflictMeta(data);
        const localHtml = typeof options.getExportHtml === 'function'
            ? String(options.getExportHtml() ?? '')
            : String(typeof window !== 'undefined' ? (window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? '') : '');
        const localHtmlHash = hashContent(localHtml);
        const baseContentHash = String(
            (typeof window !== 'undefined'
                ? (window.__SEO_EDITOR_CONFLICT__?.expected_content_hash ?? '')
                : '')
            || meta.expectedContentHash
            || '',
        ).trim();
        const confirmedVersion = getConfirmedDocumentVersion() || 0;

        const decision = classifyDocumentConflictRecovery({
            localHtmlHash,
            baseContentHash,
            actualContentHash: meta.actualContentHash,
            actualVersion: meta.actualVersion,
            confirmedVersion,
        });

        logEditorDocumentRevision('document_conflict_classified', {
            reason: decision.reason,
            action: decision.action,
            base_revision: confirmedVersion,
            server_revision: meta.actualVersion || null,
            local_hash: localHtmlHash.slice(0, 12) || null,
            server_hash: meta.actualContentHash.slice(0, 12) || null,
            request_id: options.requestId ?? null,
        });

        if (decision.action === 'rebase_retry') {
            rebaseDocumentConflictTokens(meta, {
                source: 'conflict_rebase',
                requestId: options.requestId ?? null,
            });

            if (!silentRetryUsed && typeof options.retrySave === 'function') {
                silentRetryUsed = true;
                try {
                    await options.retrySave();
                    clearDocumentConflictRecovery();
                    options.setSaveStatus?.('saved');
                    return { recovered: true, blocked: false, reason: decision.reason };
                } catch (retryError) {
                    const retryCode = String(
                        retryError?.code
                        ?? retryError?.sessionError?.code
                        ?? '',
                    );
                    const stillConflict = Boolean(retryError?.conflict)
                        || retryCode.includes('conflict')
                        || retryCode.includes('revision');
                    if (stillConflict) {
                        options.persistLocalRecovery?.();
                        enterDocumentConflictBlocked({
                            reason: 'retry_still_conflict',
                            prior_reason: decision.reason,
                        });
                        return { recovered: false, blocked: true, reason: 'retry_still_conflict' };
                    }
                    // Non-conflict failure: leave recovering cleared, surface as normal failure.
                    clearDocumentConflictRecovery();
                    options.setSaveStatus?.('failed');
                    throw retryError;
                }
            }

            clearDocumentConflictRecovery();
            options.setSaveStatus?.('pending');
            return { recovered: true, blocked: false, reason: decision.reason };
        }

        options.persistLocalRecovery?.();
        enterDocumentConflictBlocked({ reason: decision.reason });
        return { recovered: false, blocked: true, reason: decision.reason };
    })().finally(() => {
        inFlightRecovery = null;
    });

    return inFlightRecovery;
}

/**
 * Safe reload after BLOCKED — draft already persisted by recovery path.
 */
export function reloadArticleAfterDocumentConflict() {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        window.__seoFlushArticleRecoveryDraft?.();
    } catch {
        // ignore
    }
    window.location.reload();
}

export function resetDocumentConflictRecoveryForTests() {
    conflictState = DOCUMENT_CONFLICT_STATE.IDLE;
    blockedDetail = {};
    inFlightRecovery = null;
    silentRetryUsed = false;
    if (typeof window !== 'undefined') {
        delete window.__SEO_EDITOR_DOCUMENT_CONFLICT__;
        delete window.__SEO_EDITOR_DOCUMENT_CONFLICT_STATE__;
        delete window.__SEO_EDITOR_DOCUMENT_CONFLICT_DETAIL__;
    }
}

export default {
    DOCUMENT_CONFLICT_STATE,
    DOCUMENT_CONFLICT_EVENT,
    BOOTSTRAP_DIVERGENT_DRAFT_REASON,
    BOOTSTRAP_RECOVERY_ACTION_EVENT,
    getDocumentConflictState,
    getDocumentConflictBlockedDetail,
    isDocumentConflictBlocked,
    isDocumentConflictBusy,
    extractDocumentConflictMeta,
    classifyDocumentConflictRecovery,
    rebaseDocumentConflictTokens,
    enterDocumentConflictBlocked,
    clearDocumentConflictRecovery,
    recoverFromDocumentVersionConflict,
    reloadArticleAfterDocumentConflict,
    resetDocumentConflictRecoveryForTests,
};
