/**
 * Legacy FAQ extraction → canonical FAQ snapshot transfer.
 * Pending = detected in editor, not yet accepted via "Kiểm tra FAQ".
 */

import { selectEditorScroller } from './ctaSectionNavigator.js';

/** @type {{ articleId: number, faqs: object[], sectionId: string|null }|null} */
let pendingLegacyFaq = null;
/** @type {boolean} */
let commitInFlight = false;

const FAQ_ROOT_ID = 'seo-article-faq-root';
const FAQ_SHORTCODE_SELECTOR = '.seo-faq-shortcode-block';
const SCROLL_OFFSET = 88;
const SCROLL_HIGHLIGHT_CLASS = 'seo-faq-scroll-highlight';

/**
 * Dev / opt-in diagnostics. Never logs FAQ bodies, cookies, or tokens.
 *
 * @param {string} step
 * @param {Record<string, unknown>} [detail]
 */
export function legacyFaqTransferDiag(step, detail = {}) {
    const enabled = Boolean(
        (typeof import.meta !== 'undefined' && import.meta.env?.DEV)
        || (typeof window !== 'undefined' && window.__SEO_LEGACY_FAQ_DIAG__),
    );
    if (!enabled) {
        return;
    }
    const safe = { ...detail };
    delete safe.faqs;
    delete safe.items;
    delete safe.token;
    delete safe.cookie;
    delete safe.sessionId;
    // eslint-disable-next-line no-console
    console.info('[legacy-faq-transfer]', step, safe);
}

/**
 * @param {{ articleId: number|string, faqs: unknown[], sectionId?: string|null }} detail
 */
export function setLegacyFaqPending(detail) {
    const articleId = Number(detail?.articleId) || 0;
    const faqs = Array.isArray(detail?.faqs) ? detail.faqs : [];
    if (!articleId || faqs.length === 0) {
        pendingLegacyFaq = null;
        legacyFaqTransferDiag('pending.clear', { reason: 'invalid_detail', articleId });
        return null;
    }
    pendingLegacyFaq = {
        articleId,
        faqs,
        sectionId: detail?.sectionId != null ? String(detail.sectionId) : null,
    };
    legacyFaqTransferDiag('pending.set', {
        articleId,
        count: faqs.length,
        sectionId: pendingLegacyFaq.sectionId,
    });
    return pendingLegacyFaq;
}

export function getLegacyFaqPending() {
    return pendingLegacyFaq;
}

export function clearLegacyFaqPending() {
    pendingLegacyFaq = null;
    legacyFaqTransferDiag('pending.clear', { reason: 'explicit' });
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
 * Prefer pending store (by articleId), then panel ref, then restore backup.
 *
 * @param {{
 *   articleId: number|string,
 *   panelFaqs?: unknown,
 *   restoreExtractedFaqs?: unknown,
 * }} args
 * @returns {{ faqs: object[], source: 'pending'|'panel'|'restore'|'none' }}
 */
export function resolveFaqsForLegacyCommit({
    articleId,
    panelFaqs = null,
    restoreExtractedFaqs = null,
} = {}) {
    const id = Number(articleId) || 0;
    const pending = getLegacyFaqPending();
    if (
        pending
        && pending.faqs.length > 0
        && (id <= 0 || pending.articleId === id)
    ) {
        return { faqs: pending.faqs, source: 'pending' };
    }
    if (Array.isArray(panelFaqs) && panelFaqs.length > 0) {
        return { faqs: panelFaqs, source: 'panel' };
    }
    if (Array.isArray(restoreExtractedFaqs) && restoreExtractedFaqs.length > 0) {
        return { faqs: restoreExtractedFaqs, source: 'restore' };
    }
    return { faqs: [], source: 'none' };
}

/**
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

export function isLegacyFaqCommitInFlight() {
    return commitInFlight;
}

/**
 * @returns {boolean} true if lock acquired
 */
export function beginLegacyFaqCommit() {
    if (commitInFlight) {
        legacyFaqTransferDiag('commit.blocked_double_click');
        return false;
    }
    commitInFlight = true;
    legacyFaqTransferDiag('commit.begin');
    return true;
}

export function endLegacyFaqCommit() {
    commitInFlight = false;
    legacyFaqTransferDiag('commit.end');
}

function isFaqSnapshotConflict(error) {
    const code = String(error?.code || error?.payload?.error || error?.message || '');
    return code.includes('faq_snapshot_conflict');
}

/**
 * Persist pending/extracted FAQ rows via canonical snapshot API.
 *
 * @param {number|string} articleId
 * @param {unknown[]} faqs
 * @param {{ signal?: AbortSignal }} [options]
 * @returns {Promise<{ items: object[], snapshot: object, reusedExisting?: boolean }>}
 */
export async function commitLegacyFaqToCanonical(articleId, faqs, { signal } = {}) {
    const items = normalizeLegacyFaqItemsForSnapshot(faqs);
    if (items.length === 0) {
        const err = new Error('legacy_faq_empty');
        err.code = 'legacy_faq_empty';
        throw err;
    }

    legacyFaqTransferDiag('commit.import_start', { articleId: Number(articleId) || 0, count: items.length });
    let snapshotApi;
    try {
        snapshotApi = await import('./articleEditorFaqSnapshot.js');
    } catch (error) {
        legacyFaqTransferDiag('commit.import_failed', {
            message: String(error?.message || error),
        });
        const err = new Error(String(error?.message || 'legacy_faq_import_failed'));
        err.code = 'legacy_faq_import_failed';
        err.cause = error;
        throw err;
    }
    legacyFaqTransferDiag('commit.import_ok');

    const {
        fetchFaqSnapshot,
        itemsFromFaqSnapshot,
        replaceFaqSnapshot,
        rememberFaqSnapshot,
        getFaqSnapshotVersion,
    } = snapshotApi;

    const putOnce = async () => {
        legacyFaqTransferDiag('commit.api_request', {
            articleId: Number(articleId) || 0,
            count: items.length,
            expectedVersion: getFaqSnapshotVersion(articleId),
        });
        const snapshot = await replaceFaqSnapshot(articleId, items, { signal });
        legacyFaqTransferDiag('commit.api_ok', {
            articleId: Number(articleId) || 0,
            count: itemsFromFaqSnapshot(snapshot).length,
            version: snapshot?.snapshot_version ?? null,
        });
        return {
            items: itemsFromFaqSnapshot(snapshot),
            snapshot,
            reusedExisting: false,
        };
    };

    try {
        return await putOnce();
    } catch (error) {
        if (!isFaqSnapshotConflict(error)) {
            legacyFaqTransferDiag('commit.api_failed', {
                code: String(error?.code || ''),
                message: String(error?.message || ''),
            });
            throw error;
        }

        legacyFaqTransferDiag('commit.conflict_refresh', {
            code: String(error?.code || 'faq_snapshot_conflict'),
        });
        const live = await fetchFaqSnapshot(articleId, { signal });
        rememberFaqSnapshot(articleId, live);
        const existing = itemsFromFaqSnapshot(live);
        if (existing.length > 0) {
            // Server already has canonical rows — do not blind overwrite.
            legacyFaqTransferDiag('commit.reuse_existing', { count: existing.length });
            return { items: existing, snapshot: live, reusedExisting: true };
        }
        return putOnce();
    }
}

function stickyOffset(pane) {
    const toolbar = pane?.querySelector?.('.seo-editor-toolbar');
    const height = toolbar ? toolbar.getBoundingClientRect().height : 0;
    return Math.max(SCROLL_OFFSET, Math.ceil(height) + 12);
}

/**
 * Scroll main editor column to a target (never the right sidebar).
 *
 * @param {HTMLElement|null} target
 * @param {{ highlight?: boolean, reason?: string }} [options]
 * @returns {boolean}
 */
export function scrollFaqTargetIntoView(target, { highlight = false, reason = 'target' } = {}) {
    if (!target) {
        legacyFaqTransferDiag('scroll.missing_target', { reason });
        return false;
    }
    const pane = target.closest?.('.seo-article-editor-mainpane')
        ?? document.querySelector('.seo-article-editor-mainpane');
    const container = selectEditorScroller(target);
    if (!container) {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        legacyFaqTransferDiag('scroll.fallback_into_view', { reason });
    } else {
        const top = target.getBoundingClientRect().top
            - container.getBoundingClientRect().top
            + container.scrollTop
            - stickyOffset(pane);
        container.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
        legacyFaqTransferDiag('scroll.done', { reason });
    }

    if (highlight) {
        target.classList.add(SCROLL_HIGHLIGHT_CLASS);
        window.setTimeout(() => {
            target.classList.remove(SCROLL_HIGHLIGHT_CLASS);
        }, 2400);
    }
    return true;
}

/**
 * Prefer FAQ shortcode block in the article body; fallback to FAQ editor root.
 *
 * @param {HTMLElement|null} [preferred]
 * @returns {boolean}
 */
export function scrollFaqShortcodeIntoView(preferred = null) {
    const shortcode = preferred
        ?? document.querySelector(FAQ_SHORTCODE_SELECTOR);
    if (shortcode) {
        return scrollFaqTargetIntoView(shortcode, {
            highlight: true,
            reason: 'shortcode',
        });
    }
    return scrollFaqTargetIntoView(document.getElementById(FAQ_ROOT_ID), {
        highlight: false,
        reason: 'faq_root_fallback',
    });
}

/** @deprecated use scrollFaqShortcodeIntoView / scrollFaqTargetIntoView */
export function scrollFaqEditorRootIntoView(root) {
    return scrollFaqTargetIntoView(root ?? document.getElementById(FAQ_ROOT_ID), {
        reason: 'faq_root',
    });
}

/**
 * Wait until shortcode (or FAQ root) is in the DOM, then scroll once.
 * Bounded rAF — does not keep scrolling after later FAQ lazy mounts.
 *
 * @param {{ signal?: AbortSignal, maxFrames?: number }} [options]
 * @returns {Promise<{ scrolled: boolean, anchor: 'shortcode'|'faq_root'|'none' }>}
 */
export function revealFaqShortcodeAfterTransfer({
    signal = null,
    maxFrames = 90,
} = {}) {
    return new Promise((resolve) => {
        let frames = 0;
        const tick = () => {
            if (signal?.aborted) {
                resolve({ scrolled: false, anchor: 'none' });
                return;
            }
            const shortcode = document.querySelector(FAQ_SHORTCODE_SELECTOR);
            if (shortcode) {
                const scrolled = scrollFaqShortcodeIntoView(shortcode);
                resolve({ scrolled, anchor: 'shortcode' });
                return;
            }
            frames += 1;
            if (frames >= maxFrames) {
                const root = document.getElementById(FAQ_ROOT_ID);
                if (root) {
                    const scrolled = scrollFaqTargetIntoView(root, {
                        highlight: false,
                        reason: 'faq_root_fallback',
                    });
                    resolve({ scrolled, anchor: scrolled ? 'faq_root' : 'none' });
                    return;
                }
                resolve({ scrolled: false, anchor: 'none' });
                return;
            }
            window.requestAnimationFrame(tick);
        };
        window.requestAnimationFrame(tick);
    });
}

/**
 * Wait until FAQ editor collector is mounted, then seed rows (lazy-safe).
 * Does not scroll — caller scrolls to shortcode separately so lazy mount cannot steal focus.
 *
 * @param {object[]} items
 * @param {{ expectedCount?: number, signal?: AbortSignal, maxFrames?: number }} [options]
 * @returns {Promise<{ seeded: boolean, rowCount: number }>}
 */
export function seedFaqEditorWhenReady(items, {
    expectedCount = 0,
    signal = null,
    maxFrames = 90,
} = {}) {
    const rows = Array.isArray(items) ? items : [];
    return new Promise((resolve) => {
        let frames = 0;
        let seeded = false;

        const tick = () => {
            if (signal?.aborted) {
                resolve({ seeded: false, rowCount: 0 });
                return;
            }

            const collectorReady = typeof window.__seoCollectArticleFaqs === 'function';
            const root = document.getElementById(FAQ_ROOT_ID);
            const painted = root
                ? root.querySelectorAll('.seo-faq-item, [data-seo-faq-index]').length
                : 0;

            if (collectorReady && !seeded && rows.length > 0) {
                window.dispatchEvent(new CustomEvent('article-faqs-extracted', {
                    detail: {
                        faqs: rows,
                        source: 'legacy-faq-commit',
                    },
                }));
                seeded = true;
                legacyFaqTransferDiag('seed.dispatched', { count: rows.length });
            }

            const enough = expectedCount > 0
                ? painted >= expectedCount
                : painted > 0 || (seeded && collectorReady);

            if (root && enough) {
                resolve({ seeded, rowCount: painted });
                return;
            }

            frames += 1;
            if (frames >= maxFrames) {
                if (!seeded && rows.length > 0) {
                    window.dispatchEvent(new CustomEvent('article-faqs-extracted', {
                        detail: {
                            faqs: rows,
                            source: 'legacy-faq-commit-fallback',
                        },
                    }));
                    seeded = true;
                    legacyFaqTransferDiag('seed.fallback_dispatch', { count: rows.length });
                }
                resolve({ seeded, rowCount: painted });
                return;
            }
            window.requestAnimationFrame(tick);
        };

        window.requestAnimationFrame(tick);
    });
}

/**
 * @param {{ expectedCount?: number, signal?: AbortSignal, maxFrames?: number }} [options]
 * @returns {Promise<{ ready: boolean, rowCount: number }>}
 */
export function revealFaqEditorAfterTransfer(options = {}) {
    return revealFaqShortcodeAfterTransfer(options).then((result) => ({
        ready: result.scrolled,
        rowCount: result.anchor === 'none' ? 0 : 1,
    }));
}

/** @internal test helper */
export function __resetLegacyFaqPendingForTests() {
    pendingLegacyFaq = null;
    commitInFlight = false;
}
