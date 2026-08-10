/**
 * Shared Article Editor operation lock + polling (WordPress sync, media slug fix).
 * Lease-aware: timeout overlay, terminal cancelled/stale, retry.
 */

import { setArticleAutosaveLock } from './articleAutosaveLock';

const POLL_MS = 2500;
const MAX_POLL_ERRORS = 8;
const CLIENT_TIMEOUT_MS = 5 * 60 * 1000;

/** @type {ReturnType<typeof setInterval>|null} */
let pollTimer = null;
/** @type {number} */
let trackedArticleId = 0;
/** @type {boolean} */
let reloadScheduled = false;
/** @type {number} */
let pollErrorCount = 0;
/** @type {number} */
let pollStartedAt = 0;
/** @type {object|null} */
let lastOperation = null;

const TERMINAL_STATUSES = new Set(['success', 'failed', 'cancelled', 'stale', 'completed']);

function csrfToken() {
    return (
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
        || window.Livewire?.find?.(window.__SEO_EDIT_ARTICLE_LIVEWIRE_ID__)?.csrf
        || ''
    );
}

function elapsedLabel(startedAtIso) {
    if (!startedAtIso) {
        if (!pollStartedAt) {
            return '';
        }
        const sec = Math.max(0, Math.floor((Date.now() - pollStartedAt) / 1000));
        return `${sec}s`;
    }
    const started = Date.parse(String(startedAtIso));
    if (!Number.isFinite(started)) {
        return '';
    }
    const sec = Math.max(0, Math.floor((Date.now() - started) / 1000));
    return `${sec}s`;
}

/**
 * @param {'queued'|'processing'|'success'|'failed'|'cancelled'|'stale'|string} status
 * @param {'wordpress_sync'|'media_slug_fix'|string} operation
 * @param {{ stage?: string, error_message?: string, attempts?: number, worker_id?: string, started_at?: string, queued_at?: string }} [extra]
 */
export function showArticleOperationOverlay(status, operation = 'wordpress_sync', extra = {}) {
    if (window.__SEO_EDITOR_EXITING__) {
        return;
    }

    const overlay = window.__seoArticleHeavyActionOverlay;
    if (!overlay?.show) {
        return;
    }

    const copy = operationCopy(operation, status, extra);
    const persist =
        status === 'queued'
        || status === 'processing'
        || status === 'success';

    overlay.show(operation === 'media_slug_fix' ? 'sync' : 'sync', {
        persistUntilUnload: persist,
        title: copy.title,
        message: copy.message,
    });

    const el = document.getElementById(overlay.id);
    const titleEl = el?.querySelector?.('[data-heavy-action-title]');
    const messageEl = el?.querySelector?.('[data-heavy-action-message]');
    if (titleEl && copy.title) {
        titleEl.textContent = copy.title;
    }
    if (messageEl && copy.message) {
        messageEl.textContent = copy.message;
    }

    ensureRetryButton(el, status, operation);

    setArticleAutosaveLock('article-operation', status === 'queued' || status === 'processing');
    window.dispatchEvent(
        new CustomEvent('article-wordpress-sync-lock', {
            detail: { action: operation, status },
        }),
    );
}

/**
 * @param {Element|null|undefined} overlayEl
 * @param {string} status
 * @param {string} operation
 */
function ensureRetryButton(overlayEl, status, operation) {
    if (!overlayEl || operation !== 'wordpress_sync') {
        return;
    }

    let btn = overlayEl.querySelector('[data-wp-sync-retry]');
    if (status !== 'failed' && status !== 'stale' && status !== 'cancelled') {
        btn?.remove();
        return;
    }

    if (!btn) {
        btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('data-wp-sync-retry', '1');
        btn.className = 'mt-4 inline-flex items-center rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-primary-500';
        btn.textContent = 'Retry Sync WP';
        btn.addEventListener('click', () => {
            window.__seoEndArticleHeavyActionClient?.();
            setArticleAutosaveLock('article-operation', false);
            stopArticleOperationPolling();
            window.dispatchEvent(new CustomEvent('article-wordpress-sync-retry'));
            const syncBtn = document.querySelector('[data-seo-sync-wp], [wire\\:click*="requestSyncToWordPress"]');
            if (syncBtn instanceof HTMLElement) {
                syncBtn.click();
            }
        });
        const messageEl = overlayEl.querySelector('[data-heavy-action-message]');
        (messageEl?.parentElement || overlayEl).appendChild(btn);
    }
}

/**
 * @param {'wordpress_sync'|'media_slug_fix'|string} operation
 * @param {string} status
 * @param {{ stage?: string, error_message?: string, attempts?: number, worker_id?: string, started_at?: string, queued_at?: string }} extra
 */
function operationCopy(operation, status, extra = {}) {
    if (operation === 'media_slug_fix') {
        if (status === 'success') {
            return {
                title: 'Đã cập nhật slug ảnh',
                message: 'Đang tải lại dữ liệu…',
            };
        }
        if (status === 'failed') {
            return {
                title: 'Không cập nhật được slug ảnh',
                message: String(extra.error_message || 'Vui lòng tải lại trang.'),
            };
        }

        return {
            title: 'Đang sửa slug ảnh',
            message: 'Vui lòng không chỉnh sửa bài viết trong lúc đổi slug.',
        };
    }

    const attempt = Number(extra.attempts || 0);
    const worker = String(extra.worker_id || '').trim();
    const elapsed = elapsedLabel(extra.started_at || extra.queued_at);
    const metaBits = [
        attempt > 0 ? `Attempt ${attempt}` : '',
        worker ? `Worker ${worker.slice(0, 8)}` : '',
        elapsed ? `Elapsed ${elapsed}` : '',
    ].filter(Boolean);
    const metaLine = metaBits.length > 0 ? `\n${metaBits.join(' · ')}` : '';

    if (status === 'queued') {
        return {
            title: 'Đang chờ đồng bộ WordPress',
            message: `Yêu cầu đã vào hàng đợi. Không chỉnh sửa trong lúc đồng bộ.${metaLine}`,
        };
    }
    if (status === 'processing') {
        const stage = String(extra.stage || '').trim();
        return {
            title: 'Đang đồng bộ bài viết lên WordPress',
            message: `${stage !== '' && stage !== 'processing' && stage !== 'queued'
                ? stage
                : 'Hệ thống đang xử lý nội dung và hình ảnh.'}${metaLine}`,
        };
    }
    if (status === 'success') {
        return {
            title: 'Đồng bộ WordPress thành công',
            message: 'Đang tải lại dữ liệu…',
        };
    }
    if (status === 'failed') {
        return {
            title: 'Đồng bộ WordPress thất bại',
            message: String(extra.error_message || 'Có lỗi khi đồng bộ. Bấm Retry để thử lại.'),
        };
    }
    if (status === 'cancelled') {
        return {
            title: 'Đã hủy đồng bộ WordPress',
            message: String(extra.error_message || 'Job đã bị cancel. Trang sẽ tải lại.'),
        };
    }
    if (status === 'stale') {
        return {
            title: 'Đồng bộ WordPress bị gián đoạn',
            message: String(extra.error_message || 'Worker mất heartbeat. Có thể Retry.'),
        };
    }

    return {
        title: 'Đang đồng bộ với WordPress',
        message: `Vui lòng chờ — không chỉnh sửa cho đến khi hoàn tất.${metaLine}`,
    };
}

export function stopArticleOperationPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    pollStartedAt = 0;
}

function resolveSyncQueueExitUrl() {
    const configured = typeof window.__SEO_ARTICLES_SYNC_QUEUE_URL__ === 'string'
        ? window.__SEO_ARTICLES_SYNC_QUEUE_URL__.trim()
        : '';

    return configured !== '' ? configured : '/seo/articles?tab=queue';
}

function isWordpressSyncOperationType(type) {
    const normalized = String(type || '').trim();

    return normalized === 'wordpress_sync'
        || normalized.includes('wordpress')
        || normalized.includes('wp_sync');
}

/**
 * Enqueue WP sync xong — đóng editor ngay, không poll/elapsed.
 */
export function exitEditorAfterWordpressSyncQueued() {
    window.__SEO_EDITOR_EXITING__ = true;
    stopArticleOperationPolling();
    reloadScheduled = true;

    if (window.__seoArticleHeavyActionOverlay) {
        window.__seoArticleHeavyActionOverlay.persistUntilUnload = false;
        window.__seoArticleHeavyActionOverlay.locked = false;
    }
    window.__seoArticleHeavyActionOverlay?.hide?.();
    setArticleAutosaveLock('article-operation', false);
    setArticleAutosaveLock('article-heavy-action', false);

    const url = resolveSyncQueueExitUrl();
    try {
        window.close();
    } catch {
        // ignore
    }

    try {
        if (!window.closed) {
            window.location.replace(url);
        }
    } catch {
        window.location.href = url;
    }

    window.setTimeout(() => {
        try {
            if (!window.closed) {
                window.location.replace(url);
            }
        } catch {
            window.location.href = url;
        }
    }, 50);
}

/**
 * @param {number} articleId
 * @returns {Promise<{success: boolean, operation?: object|null, has_active_operation?: boolean}>}
 */
export async function fetchArticleOperationStatus(articleId) {
    const id = Number(articleId) || 0;
    if (id <= 0) {
        return { success: false, operation: null, has_active_operation: false };
    }

    const response = await fetch(`/api/seo/articles/${id}/operation-status`, {
        method: 'GET',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        return { success: false, operation: null, has_active_operation: false };
    }

    return data;
}

/**
 * @param {number} articleId
 * @param {{ delayMs?: number }} [options]
 */
export function scheduleArticleEditorReload(articleId, options = {}) {
    if (reloadScheduled) {
        return;
    }
    reloadScheduled = true;
    trackedArticleId = Number(articleId) || trackedArticleId;
    const delay = Number(options.delayMs ?? 500);

    window.__seoArticleHeavyActionOverlay && (window.__seoArticleHeavyActionOverlay.persistUntilUnload = true);
    setTimeout(() => {
        window.location.reload();
    }, delay);
}

function unlockOverlayClient() {
    window.__seoEndArticleHeavyActionClient?.();
    setArticleAutosaveLock('article-operation', false);
}

/**
 * @param {number} articleId
 * @param {object|null|undefined} operation
 * @param {{ allowTerminalReload?: boolean }} [options]
 */
export function applyArticleOperationState(articleId, operation, options = {}) {
    if (window.__SEO_EDITOR_EXITING__) {
        stopArticleOperationPolling();

        return;
    }

    const op = operation && typeof operation === 'object' ? operation : null;
    if (!op) {
        return;
    }

    lastOperation = op;
    const status = String(op.status || op.raw_status || '').trim();
    const type = String(op.type || op.operation || 'wordpress_sync').trim() || 'wordpress_sync';
    const publicStatus =
        status === 'pending' ? 'queued'
            : status === 'completed' ? 'success'
                : status;
    const allowTerminalReload = options.allowTerminalReload !== false;

    // WP sync queued/processing: thoát editor ngay — không đếm Elapsed.
    if (
        isWordpressSyncOperationType(type)
        && (publicStatus === 'queued' || publicStatus === 'processing')
    ) {
        exitEditorAfterWordpressSyncQueued();

        return;
    }

    if (publicStatus === 'queued' || publicStatus === 'processing') {
        showArticleOperationOverlay(publicStatus, type, {
            stage: String(op.stage || ''),
            error_message: String(op.error_message || ''),
            attempts: Number(op.attempts || 0),
            worker_id: String(op.worker_id || ''),
            started_at: String(op.started_at || ''),
            queued_at: String(op.queued_at || ''),
        });
        startArticleOperationPolling(articleId);

        return;
    }

    if (!allowTerminalReload) {
        return;
    }

    if (publicStatus === 'success') {
        showArticleOperationOverlay('success', type);
        scheduleArticleEditorReload(articleId, { delayMs: 600 });

        return;
    }

    if (publicStatus === 'failed') {
        showArticleOperationOverlay('failed', type, {
            error_message: String(op.error_message || ''),
            attempts: Number(op.attempts || 0),
            worker_id: String(op.worker_id || ''),
        });
        unlockOverlayClient();
        stopArticleOperationPolling();

        return;
    }

    if (publicStatus === 'cancelled' || publicStatus === 'stale') {
        showArticleOperationOverlay(publicStatus, type, {
            error_message: String(op.error_message || ''),
        });
        scheduleArticleEditorReload(articleId, { delayMs: 1200 });
    }
}

/**
 * @param {number} articleId
 */
export function startArticleOperationPolling(articleId) {
    if (window.__SEO_EDITOR_EXITING__) {
        return;
    }

    const id = Number(articleId) || 0;
    if (id <= 0) {
        return;
    }

    trackedArticleId = id;
    stopArticleOperationPolling();
    pollErrorCount = 0;
    if (!pollStartedAt) {
        pollStartedAt = Date.now();
    }

    const tick = async () => {
        if (window.__SEO_EDITOR_EXITING__ || reloadScheduled || trackedArticleId !== id) {
            stopArticleOperationPolling();

            return;
        }

        if (Date.now() - pollStartedAt > CLIENT_TIMEOUT_MS) {
            stopArticleOperationPolling();
            showArticleOperationOverlay('failed', 'wordpress_sync', {
                error_message: 'Hết thời gian chờ đồng bộ (5 phút). Bấm Retry hoặc Reset queue.',
                attempts: Number(lastOperation?.attempts || 0),
                worker_id: String(lastOperation?.worker_id || ''),
            });
            unlockOverlayClient();

            return;
        }

        try {
            const data = await fetchArticleOperationStatus(id);
            pollErrorCount = 0;
            const op = data.operation ?? null;
            if (!op) {
                return;
            }

            lastOperation = op;
            const status = String(op.status || '').trim();
            if (status === 'queued' || status === 'processing') {
                const type = String(op.type || 'wordpress_sync');
                if (isWordpressSyncOperationType(type)) {
                    exitEditorAfterWordpressSyncQueued();

                    return;
                }

                showArticleOperationOverlay(status, type, {
                    stage: String(op.stage || ''),
                    error_message: String(op.error_message || ''),
                    attempts: Number(op.attempts || 0),
                    worker_id: String(op.worker_id || ''),
                    started_at: String(op.started_at || ''),
                    queued_at: String(op.queued_at || ''),
                });

                return;
            }

            stopArticleOperationPolling();
            applyArticleOperationState(id, op);
        } catch {
            pollErrorCount += 1;
            if (pollErrorCount >= MAX_POLL_ERRORS) {
                stopArticleOperationPolling();
                showArticleOperationOverlay('failed', 'wordpress_sync', {
                    error_message: 'Mất kết nối khi theo dõi đồng bộ. Bấm Retry.',
                });
                unlockOverlayClient();
            }
        }
    };

    void tick();
    pollTimer = setInterval(() => {
        void tick();
    }, POLL_MS);
}

/**
 * Bootstrap on Edit Article page load — restore overlay after F5.
 * @param {number} articleId
 */
export async function bootstrapArticleOperationLock(articleId) {
    if (window.__SEO_EDITOR_EXITING__) {
        return;
    }

    const id = Number(articleId) || 0;
    if (id <= 0) {
        return;
    }

    try {
        const data = await fetchArticleOperationStatus(id);
        if (!data.has_active_operation || !data.operation) {
            return;
        }

        pollStartedAt = Date.now();
        applyArticleOperationState(id, data.operation);
    } catch {
        // ignore bootstrap failures
    }
}

/**
 * @param {number} articleId
 * @param {object} operation
 */
export function apply(articleId, operation) {
    applyArticleOperationState(articleId, operation);
}

/**
 * @param {number} articleId
 */
export function poll(articleId) {
    startArticleOperationPolling(articleId);
}

export function installArticleOperationTracker() {
    window.__seoArticleOperationTracker = {
        apply: applyArticleOperationState,
        poll: startArticleOperationPolling,
        bootstrap: bootstrapArticleOperationLock,
        stop: stopArticleOperationPolling,
        show: showArticleOperationOverlay,
        exitAfterQueued: exitEditorAfterWordpressSyncQueued,
    };

    const bootId = Number(window.__SEO_ACTIVE_ARTICLE_OPERATION__?.article_id || 0);
    if (bootId > 0) {
        void bootstrapArticleOperationLock(bootId);
    }
}
