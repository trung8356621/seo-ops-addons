/**
 * Legacy FAQ extraction → canonical FAQ snapshot transfer.
 * Pending = detected in editor, not yet accepted via "Kiểm tra FAQ".
 */

import { selectEditorScroller } from './ctaSectionNavigator.js';

/** @type {{ articleId: number, faqs: object[], sectionId: string|null }|null} */
let pendingLegacyFaq = null;

const FAQ_ROOT_ID = 'seo-article-faq-root';
const SCROLL_OFFSET = 88;

/**
 * @param {{ articleId: number|string, faqs: unknown[], sectionId?: string|null }} detail
 */
export function setLegacyFaqPending(detail) {
    const articleId = Number(detail?.articleId) || 0;
    const faqs = Array.isArray(detail?.faqs) ? detail.faqs : [];
    if (!articleId || faqs.length === 0) {
        pendingLegacyFaq = null;
        return null;
    }
    pendingLegacyFaq = {
        articleId,
        faqs,
        sectionId: detail?.sectionId != null ? String(detail.sectionId) : null,
    };
    return pendingLegacyFaq;
}

export function getLegacyFaqPending() {
    return pendingLegacyFaq;
}

export function clearLegacyFaqPending() {
    pendingLegacyFaq = null;
}

/**
 * @param {number|string} [articleId]
 * @returns {boolean}
 */
export function isLegacyFaqPending(articleId) {
    if (!pendingLegacyFaq) {
        return false;
    }
    const id = Number(articleId) || 0;
    if (id > 0 && pendingLegacyFaq.articleId !== id) {
        return false;
    }
    return pendingLegacyFaq.faqs.length > 0;
}

/**
 * Content-save helper: while FAQ editor collector is closed, still persist
 * pending extraction rows so [omi_faq] is never saved without Q/A.
 *
 * @param {number|string} [articleId]
 * @returns {unknown[]|null}
 */
export function resolvePendingFaqsForContentSave(articleId) {
    if (!isLegacyFaqPending(articleId)) {
        return null;
    }
    return pendingLegacyFaq?.faqs ?? null;
}

/**
 * Ignore empty FAQ-editor sync that would wipe pending extraction rows.
 *
 * @param {{ rows: unknown, articleId?: number|string }} args
 * @returns {boolean}
 */
export function shouldIgnoreEmptyFaqEditorSync({ rows, articleId } = {}) {
    if (!Array.isArray(rows) || rows.length > 0) {
        return false;
    }
    return isLegacyFaqPending(articleId);
}

/**
 * @param {unknown[]} faqs
 * @returns {object[]}
 */
export function normalizeLegacyFaqItemsForSnapshot(faqs) {
    const rows = Array.isArray(faqs) ? faqs : [];
    return rows.map((row, index) => {
        const question = String(row?.question ?? '').trim();
        const answer = String(row?.answer ?? '').trim() || '<p></p>';
        const existingKey = String(row?.client_key ?? '').trim();
        return {
            id: row?.id ?? null,
            client_key: existingKey || `legacy-faq-${index}-${simpleKey(question, index)}`,
            question,
            answer,
            more: String(row?.more ?? ''),
            sort_order: Number.isFinite(Number(row?.sort_order))
                ? Number(row.sort_order)
                : index,
        };
    }).filter((row) => row.question !== '' && row.answer !== '<p></p>');
}

function simpleKey(question, index) {
    const slug = String(question)
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 24);
    return `${slug || 'q'}-${index}`;
}

/**
 * Persist pending/extracted FAQ rows via canonical snapshot API.
 *
 * @param {number|string} articleId
 * @param {unknown[]} faqs
 * @param {{ signal?: AbortSignal }} [options]
 * @returns {Promise<{ items: object[], snapshot: object }>}
 */
export async function commitLegacyFaqToCanonical(articleId, faqs, { signal } = {}) {
    const items = normalizeLegacyFaqItemsForSnapshot(faqs);
    if (items.length === 0) {
        const err = new Error('legacy_faq_empty');
        err.code = 'legacy_faq_empty';
        throw err;
    }
    // Dynamic import keeps Node selftests free of Vite `@seo-addon` aliases.
    const {
        itemsFromFaqSnapshot,
        replaceFaqSnapshot,
    } = await import('./articleEditorFaqSnapshot.js');
    const snapshot = await replaceFaqSnapshot(articleId, items, { signal });
    return {
        items: itemsFromFaqSnapshot(snapshot),
        snapshot,
    };
}

function stickyOffset(pane) {
    const toolbar = pane?.querySelector?.('.seo-editor-toolbar');
    const height = toolbar ? toolbar.getBoundingClientRect().height : 0;
    return Math.max(SCROLL_OFFSET, Math.ceil(height) + 12);
}

/**
 * Scroll main editor column to FAQ root (never the right sidebar).
 *
 * @param {HTMLElement|null} root
 */
export function scrollFaqEditorRootIntoView(root) {
    const target = root ?? document.getElementById(FAQ_ROOT_ID);
    if (!target) {
        return false;
    }
    const pane = target.closest?.('.seo-article-editor-mainpane')
        ?? document.querySelector('.seo-article-editor-mainpane');
    const container = selectEditorScroller(target);
    if (!container) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return true;
    }
    const top = target.getBoundingClientRect().top
        - container.getBoundingClientRect().top
        + container.scrollTop
        - stickyOffset(pane);
    container.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
    return true;
}

/**
 * Wait until FAQ editor has painted rows (or root exists), then scroll.
 * Uses rAF polling — no fixed sleep.
 *
 * @param {{ expectedCount?: number, signal?: AbortSignal, maxFrames?: number }} [options]
 * @returns {Promise<{ ready: boolean, rowCount: number }>}
 */
export function revealFaqEditorAfterTransfer({
    expectedCount = 0,
    signal = null,
    maxFrames = 90,
} = {}) {
    return new Promise((resolve) => {
        let frames = 0;

        const finish = (ready, rowCount) => {
            scrollFaqEditorRootIntoView(document.getElementById(FAQ_ROOT_ID));
            resolve({ ready, rowCount });
        };

        const tick = () => {
            if (signal?.aborted) {
                resolve({ ready: false, rowCount: 0 });
                return;
            }
            const root = document.getElementById(FAQ_ROOT_ID);
            const items = root
                ? root.querySelectorAll('.seo-faq-item, [data-seo-faq-index]')
                : [];
            const rowCount = items.length;
            const hasEditor = Boolean(
                root?.querySelector?.('.seo-faq-editor, .seo-article-faq-editor, [data-seo-faq-editor]'),
            );
            const enough = expectedCount > 0
                ? rowCount >= expectedCount
                : rowCount > 0 || hasEditor;

            if (root && enough) {
                finish(true, rowCount);
                return;
            }

            frames += 1;
            if (frames >= maxFrames) {
                finish(Boolean(root), rowCount);
                return;
            }
            window.requestAnimationFrame(tick);
        };

        window.requestAnimationFrame(tick);
    });
}

/** @internal test helper */
export function __resetLegacyFaqPendingForTests() {
    pendingLegacyFaq = null;
}
