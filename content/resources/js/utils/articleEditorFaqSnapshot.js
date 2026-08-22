/**
 * Phase 2C — FAQ snapshot client (Laravel canonical; React draft UI only).
 */

import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { acknowledgeDocumentVersion } from './editorDocumentRevision.js';

/** @type {Map<number, { snapshot_version: number, updatedAt: number }>} */
const snapshotMeta = new Map();

export function getFaqSnapshotVersion(articleId) {
    const id = Number(articleId) || 0;
    return snapshotMeta.get(id)?.snapshot_version ?? 1;
}

export function rememberFaqSnapshot(articleId, snapshot) {
    const id = Number(articleId) || 0;
    if (!id || !snapshot || typeof snapshot !== 'object') {
        return null;
    }
    const version = Math.max(1, Number(snapshot.snapshot_version) || 1);
    const prev = snapshotMeta.get(id);
    if (prev && version < prev.snapshot_version) {
        return null; // stale
    }
    snapshotMeta.set(id, { snapshot_version: version, updatedAt: Date.now() });
    return snapshot;
}

export function itemsFromFaqSnapshot(snapshot) {
    const items = Array.isArray(snapshot?.items) ? snapshot.items : [];
    return items.map((row, index) => ({
        id: row?.id ?? null,
        client_key: String(row?.client_key ?? '').trim()
            || (row?.id != null ? `faq-id-${row.id}` : ''),
        question: String(row?.question ?? ''),
        answer: String(row?.answer ?? '<p></p>'),
        more: String(row?.more ?? ''),
        sort_order: Number(row?.sort_order ?? row?.position ?? index) || index,
        duplicate: Boolean(row?.duplicate),
        duplicate_scope: row?.duplicate_scope ?? null,
    }));
}

function editorSessionHeaders() {
    const sessionId = String(window.__SEO_EDITOR_SESSION_ID__ ?? '').trim();
    const headers = {};
    if (sessionId !== '') {
        headers['X-Editor-Session-Id'] = sessionId;
    }
    return headers;
}

function faqSnapshotUrl(articleId, suffix = '') {
    const id = Number(articleId) || 0;
    const base = `/api/seo/articles/${id}/editor/faq-snapshot`;
    return suffix ? `${base}${suffix}` : base;
}

export async function fetchFaqSnapshot(articleId, { signal } = {}) {
    const { response, data } = await seoArticleApiFetch(faqSnapshotUrl(articleId), {
        signal,
        headers: editorSessionHeaders(),
    });
    if (!response.ok || data?.success === false) {
        throw new Error(data?.error || data?.message || 'faq_snapshot_load_failed');
    }
    const snap = rememberFaqSnapshot(articleId, data?.faq_snapshot);
    if (!snap) {
        throw new Error('faq_snapshot_stale');
    }
    return snap;
}

export async function replaceFaqSnapshot(articleId, items, { signal } = {}) {
    const expected = getFaqSnapshotVersion(articleId);
    const sessionId = String(window.__SEO_EDITOR_SESSION_ID__ ?? '').trim();
    const { response, data } = await seoArticleApiFetch(faqSnapshotUrl(articleId), {
        method: 'PUT',
        signal,
        headers: {
            ...editorSessionHeaders(),
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            items,
            faqs: items,
            expected_snapshot_version: expected,
            editor_session_id: sessionId || null,
        }),
    });
    if (!response.ok || data?.success === false) {
        const err = new Error(data?.error || data?.message || 'faq_snapshot_save_failed');
        err.code = data?.error || 'faq_snapshot_save_failed';
        err.payload = data;
        throw err;
    }
    const snap = rememberFaqSnapshot(articleId, data?.faq_snapshot);
    if (!snap) {
        throw new Error('faq_snapshot_stale');
    }
    return snap;
}

export async function generateFaqPreview(articleId, editorHtml = '', { signal } = {}) {
    const { response, data } = await seoArticleApiFetch(faqSnapshotUrl(articleId, '/generate-preview'), {
        method: 'POST',
        signal,
        headers: {
            ...editorSessionHeaders(),
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ editor_html: editorHtml }),
    });
    if (!response.ok || data?.success === false) {
        const err = new Error(data?.error || data?.message || 'faq_generation_failed');
        err.code = data?.error || 'faq_generation_failed';
        throw err;
    }
    return data;
}

/**
 * Editor-canonical FAQ extract (replaces Livewire extractFaqsFromSelection for editor callers).
 *
 * @param {number|string} articleId
 * @param {string} html
 * @param {string} [articleHtml]
 * @param {{ signal?: AbortSignal }} [options]
 */
export async function extractFaqFromSelection(articleId, html, articleHtml = '', { signal } = {}) {
    const sessionId = String(window.__SEO_EDITOR_SESSION_ID__ ?? '').trim();
    const docVersion = Number(window.__SEO_EDITOR_DOCUMENT_VERSION__ ?? 0) || null;
    const { response, data } = await seoArticleApiFetch(faqSnapshotUrl(articleId, '/extract'), {
        method: 'POST',
        signal,
        headers: {
            ...editorSessionHeaders(),
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            html,
            article_html: articleHtml,
            editor_session_id: sessionId || null,
            expected_document_version: docVersion,
        }),
    });
    if (!response.ok || data?.success === false) {
        const err = new Error(data?.error || data?.message || 'faq_extract_failed');
        err.code = data?.error || 'faq_extract_failed';
        err.payload = data;
        throw err;
    }
    const snap = data?.faq_snapshot ? rememberFaqSnapshot(articleId, data.faq_snapshot) : null;
    const nextVersion = Number(
        data?.document_version
        ?? snap?.document_version
        ?? 0,
    );
    if (Number.isFinite(nextVersion) && nextVersion > 0) {
        acknowledgeDocumentVersion(nextVersion, { source: 'faq_extract' });
        window.__seoEditorSessionClient?.setDocumentVersion?.(nextVersion, { source: 'faq_extract' });
    }
    return {
        faqs: Array.isArray(data?.faqs) ? data.faqs : itemsFromFaqSnapshot(snap),
        editor_html: String(data?.editor_html ?? ''),
        faq_snapshot: snap,
        document_version: nextVersion || null,
    };
}

export async function applyFaqSnapshot(articleId, items, editorHtml = '', { signal } = {}) {
    const expected = getFaqSnapshotVersion(articleId);
    const sessionId = String(window.__SEO_EDITOR_SESSION_ID__ ?? '').trim();
    const docVersion = Number(window.__SEO_EDITOR_DOCUMENT_VERSION__ ?? 0) || null;
    const { response, data } = await seoArticleApiFetch(faqSnapshotUrl(articleId, '/apply'), {
        method: 'POST',
        signal,
        headers: {
            ...editorSessionHeaders(),
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            items,
            faqs: items,
            editor_html: editorHtml,
            expected_snapshot_version: expected,
            expected_document_version: docVersion,
            editor_session_id: sessionId || null,
        }),
    });
    if (!response.ok || data?.success === false) {
        const err = new Error(data?.error || data?.message || 'faq_apply_failed');
        err.code = data?.error || 'faq_apply_failed';
        err.payload = data;
        throw err;
    }
    const snap = rememberFaqSnapshot(articleId, data?.faq_snapshot);
    const nextVersion = Number(
        data?.document_version
        ?? snap?.document_version
        ?? 0,
    );
    if (Number.isFinite(nextVersion) && nextVersion > 0) {
        acknowledgeDocumentVersion(nextVersion, { source: 'faq_apply' });
        window.__seoEditorSessionClient?.setDocumentVersion?.(nextVersion, { source: 'faq_apply' });
    }
    return {
        faq_snapshot: snap,
        editor_html: String(data?.editor_html ?? ''),
        document_version: nextVersion || null,
    };
}

export default {
    fetchFaqSnapshot,
    replaceFaqSnapshot,
    generateFaqPreview,
    applyFaqSnapshot,
    extractFaqFromSelection,
    itemsFromFaqSnapshot,
    rememberFaqSnapshot,
    getFaqSnapshotVersion,
};
