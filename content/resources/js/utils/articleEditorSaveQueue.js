import {
    buildArticleEditorApiPayload,
    buildCoordinatedArticleSavePayload,
    finishArticleSaveFromApi,
    saveArticleViaApi,
} from './articleEditorApi';

/** @typedef {'autosave' | 'explicit' | 'save-close'} SavePriority */

const PRIORITY_RANK = {
    autosave: 1,
    explicit: 2,
    'save-close': 3,
};

/**
 * Single write queue for autosave / explicit save / save-close.
 * At most one in-flight request; coalesce pending round to highest priority + latest payload.
 */
let activeSavePromise = null;
let pendingRoundPromise = null;
/** @type {null | (() => Record<string, unknown> | Promise<Record<string, unknown>>)} */
let latestPayloadFactory = null;
let latestArticleId = null;
/** @type {SavePriority} */
let latestPriority = 'autosave';
let suppressAutosaveUntil = 0;

/**
 * Cancel pending debounce timer + suppress new autosave briefly while explicit save runs.
 */
export function cancelPendingServerAutosave() {
    if (typeof window !== 'undefined') {
        window.clearTimeout(window.__seoServerAutosaveTimer);
        window.__seoServerAutosaveTimer = null;
    }
}

export function beginExplicitEditorSave() {
    cancelPendingServerAutosave();
    suppressAutosaveUntil = Date.now() + 15_000;
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('article-editor-save-started', {
            detail: { articleId: Number(window.__SEO_ARTICLE_ID__ ?? 0) || 0 },
        }));
    }
}

export function endExplicitEditorSave() {
    suppressAutosaveUntil = 0;
}

export function shouldSuppressServerAutosave() {
    return Date.now() < suppressAutosaveUntil || isArticleSaveInFlight();
}

async function runSingleFlightSave() {
    const articleId = latestArticleId;
    const factory = latestPayloadFactory;
    const priority = latestPriority;
    const payload = typeof factory === 'function' ? await factory() : {};

    if (typeof window !== 'undefined' && payload?.html) {
        window.__SEO_EDITOR_LAST_SAVE_HTML__ = String(payload.html);
    }

    if (priority !== 'autosave' && payload && typeof payload === 'object') {
        payload.save_mode = payload.save_mode === 'autosave' ? 'explicit' : (payload.save_mode || 'explicit');
    }

    activeSavePromise = saveArticleViaApiWithBusyRetry(articleId, payload).finally(() => {
        activeSavePromise = null;
        if (typeof window !== 'undefined') {
            window.__seoEditorSaveInFlight = pendingRoundPromise != null;
        }
    });
    if (typeof window !== 'undefined') {
        window.__seoEditorSaveInFlight = true;
    }

    return activeSavePromise;
}

/**
 * @param {number} articleId
 * @param {Record<string, unknown>} payload
 */
async function saveArticleViaApiWithBusyRetry(articleId, payload) {
    try {
        return await saveArticleViaApi(articleId, payload);
    } catch (error) {
        const code = String(error?.sessionError?.code ?? error?.data?.code ?? error?.code ?? '');
        const message = String(error?.message ?? '');
        const busy = code === 'article_write_busy'
            || message.includes('article_write_busy')
            || message.includes('được lưu bởi request khác');
        if (!busy) {
            throw error;
        }
        // One short retry — do not loop forever.
        await new Promise((resolve) => setTimeout(resolve, 400));
        return saveArticleViaApi(articleId, payload);
    }
}

/**
 * @param {number} articleId
 * @param {() => Record<string, unknown> | Promise<Record<string, unknown>>} payloadFactory
 * @param {{ priority?: SavePriority }} [options]
 * @returns {Promise<Record<string, unknown>>}
 */
export function saveArticleViaApiSingleFlight(articleId, payloadFactory, options = {}) {
    const priority = /** @type {SavePriority} */ (options.priority || 'explicit');

    if (priority === 'autosave' && shouldSuppressServerAutosave() && activeSavePromise) {
        // Explicit save owns the queue — drop autosave coalesce onto current flight result.
        return activeSavePromise.catch(() => null).then(() => ({ success: true, suppressed_autosave: true }));
    }

    const incomingRank = PRIORITY_RANK[priority] ?? 1;
    const currentPendingRank = PRIORITY_RANK[latestPriority] ?? 1;

    if (!activeSavePromise || incomingRank >= currentPendingRank || !latestPayloadFactory) {
        latestArticleId = articleId;
        latestPayloadFactory = payloadFactory;
        latestPriority = priority;
    } else if (incomingRank > currentPendingRank) {
        latestArticleId = articleId;
        latestPayloadFactory = payloadFactory;
        latestPriority = priority;
    } else if (incomingRank === currentPendingRank) {
        latestArticleId = articleId;
        latestPayloadFactory = payloadFactory;
        latestPriority = priority;
    }
    // Lower priority while higher pending: keep higher priority factory.

    if (priority !== 'autosave') {
        beginExplicitEditorSave();
    }

    if (activeSavePromise) {
        if (!pendingRoundPromise) {
            pendingRoundPromise = activeSavePromise
                .catch(() => null)
                .then(() => {
                    pendingRoundPromise = null;
                    return runSingleFlightSave();
                })
                .finally(() => {
                    if (priority !== 'autosave') {
                        endExplicitEditorSave();
                    }
                });
        }

        return pendingRoundPromise;
    }

    const promise = runSingleFlightSave();
    if (priority !== 'autosave') {
        return promise.finally(() => {
            endExplicitEditorSave();
        });
    }

    return promise;
}

/** True nếu có request save đang chạy (round hiện tại hoặc round kế đã xếp hàng). */
export function isArticleSaveInFlight() {
    return activeSavePromise !== null || pendingRoundPromise !== null;
}

/**
 * Save toàn bộ editor hiện tại (await) — dùng trước Fix slug all / action cần DB mới nhất.
 * Không bật/tắt heavy overlay (caller tự quản lý UI busy).
 *
 * @param {{ wire?: object|null, reason?: string, siteId?: number, keepOverlay?: boolean, silentNotification?: boolean }} [options]
 * @returns {Promise<Record<string, unknown>>}
 */
export async function saveCurrentArticleFromEditor(options = {}) {
    const collect = window.__seoCollectEditorHeavyBundle;
    if (typeof collect !== 'function') {
        throw new Error('Editor chưa sẵn sàng — tải lại trang rồi thử lại.');
    }

    const editorBundle = await collect({ renameImagesBeforeWpSync: false });
    const html = String(editorBundle?.html ?? '').trim();
    if (!html) {
        throw new Error('Không thu thập được nội dung bài viết.');
    }

    const articleId = Number(editorBundle?.articleId ?? 0);
    if (!Number.isFinite(articleId) || articleId <= 0) {
        throw new Error('Không xác định được ID bài viết.');
    }

    let siteId = Number(options.siteId ?? window.__SEO_ARTICLE_SITE_ID__ ?? 0) || 0;
    if (siteId <= 0) {
        try {
            const metaEl = document.getElementById('seo-article-meta');
            const meta = metaEl?.textContent?.trim() ? JSON.parse(metaEl.textContent) : {};
            siteId = Number(meta?.site_id ?? 0) || 0;
        } catch {
            siteId = 0;
        }
    }

    const result = await saveArticleViaApiSingleFlight(articleId, async () => {
        let bundle = editorBundle;
        try {
            const fresh = await collect({ renameImagesBeforeWpSync: false });
            if (fresh && String(fresh.html ?? '').trim() !== '') {
                bundle = fresh;
            }
        } catch {
            bundle = editorBundle;
        }

        return buildCoordinatedArticleSavePayload(bundle, options.wire ?? null);
    }, { priority: 'explicit' });

    finishArticleSaveFromApi(result, {
        articleId,
        siteId,
        connectionHash: window.__SEO_EDITOR_CONNECTION_HASH__ ?? '',
        savedHtml: String(window.__SEO_EDITOR_LAST_SAVE_HTML__ ?? editorBundle.html ?? ''),
        reason: options.reason ?? 'editor_action',
        keepOverlay: options.keepOverlay === true,
        silentNotification: options.silentNotification === true,
    });

    return result;
}
