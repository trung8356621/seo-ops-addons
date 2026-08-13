/**
 * Phase 6C.1 — React-owned widget health for navigation chips.
 *
 * Diagnostic fields (status / issue_count / item_count) stay last-stable until a
 * COMPLETE result arrives. refresh_status is orthogonal and must never invent errors.
 */

/** @type {number} */
let scopedArticleId = 0;
/** @type {number} */
let diagnosticsGeneration = 0;
/** @type {Record<string, object>} */
let healthByWidgetId = {};
/** @type {Record<string, number|string|null>} */
let navigatorBadges = {};
/** @type {Record<string, 'idle'|'refreshing'|'failed'>} */
let refreshStatusByWidgetId = {};
/** @type {Set<(snapshot: { health: object, badges: object, generation: number, articleId: number }) => void>} */
const listeners = new Set();

function snapshot() {
    return {
        health: healthByWidgetId,
        badges: navigatorBadges,
        generation: diagnosticsGeneration,
        articleId: scopedArticleId,
    };
}

function emit() {
    const next = snapshot();
    listeners.forEach((listener) => {
        try {
            listener(next);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[editor-runtime-health-store] listener failed', error);
        }
    });
}

/**
 * Bind diagnostics to current article editor session. Cross-article leakage cleared.
 * @param {number|string|null|undefined} articleId
 */
export function bindDiagnosticsArticleScope(articleId) {
    const id = Math.max(0, Number(articleId) || 0);
    if (id !== scopedArticleId) {
        scopedArticleId = id;
        diagnosticsGeneration = 0;
        healthByWidgetId = {};
        navigatorBadges = {};
        refreshStatusByWidgetId = {};
        emit();
    }

    return scopedArticleId;
}

export function getDiagnosticsArticleScope() {
    return scopedArticleId;
}

export function getDiagnosticsGeneration() {
    return diagnosticsGeneration;
}

/**
 * Mark widgets refreshing without mutating diagnostic severity/issue counts.
 * @param {number|string|null|undefined} articleId
 * @param {string[]|null} [widgetIds]
 * @returns {number} generation token for stale-response guard
 */
export function beginDiagnosticsRefresh(articleId, widgetIds = null) {
    bindDiagnosticsArticleScope(articleId);
    diagnosticsGeneration += 1;
    const ids = Array.isArray(widgetIds) && widgetIds.length > 0
        ? widgetIds
        : Object.keys(healthByWidgetId);
    ids.forEach((widgetId) => {
        refreshStatusByWidgetId[widgetId] = 'refreshing';
        const prev = healthByWidgetId[widgetId];
        if (prev && typeof prev === 'object') {
            healthByWidgetId[widgetId] = {
                ...prev,
                refresh_status: 'refreshing',
            };
        }
    });
    emit();

    return diagnosticsGeneration;
}

/**
 * Mark incomplete widgets as refreshing only — do not invent issue_count/severity.
 * @param {string[]} widgetIds
 */
export function markDiagnosticsRefreshing(widgetIds = []) {
    const ids = (Array.isArray(widgetIds) ? widgetIds : [])
        .map((id) => String(id || '').trim())
        .filter(Boolean);
    if (ids.length === 0) {
        return;
    }
    let changed = false;
    ids.forEach((widgetId) => {
        refreshStatusByWidgetId[widgetId] = 'refreshing';
        const prev = healthByWidgetId[widgetId];
        if (prev && typeof prev === 'object' && prev.refresh_status !== 'refreshing') {
            healthByWidgetId[widgetId] = {
                ...prev,
                refresh_status: 'refreshing',
            };
            changed = true;
        } else if (!prev) {
            // First load / unknown: neutral placeholder — never error.
            healthByWidgetId[widgetId] = {
                key: widgetId,
                item_count: 0,
                issue_count: 0,
                error_count: 0,
                warning_count: 0,
                status: 'neutral',
                reasons: [],
                refresh_status: 'refreshing',
            };
            changed = true;
        }
    });
    if (changed) {
        healthByWidgetId = { ...healthByWidgetId };
        emit();
    }
}

/**
 * @param {{ articleId?: number|null, generation?: number|null }} meta
 */
function isStaleOrWrongArticle(meta = {}) {
    if (meta.articleId != null) {
        const id = Math.max(0, Number(meta.articleId) || 0);
        if (id > 0 && scopedArticleId > 0 && id !== scopedArticleId) {
            return true;
        }
    }
    if (meta.generation != null && Number(meta.generation) < diagnosticsGeneration) {
        return true;
    }

    return false;
}

/**
 * Apply extras (reviews badge) without touching health map.
 * @param {{ reviewsBadge?: number|null }} extras
 */
function applyExtras(extras = {}) {
    if (Object.prototype.hasOwnProperty.call(extras, 'reviewsBadge')) {
        navigatorBadges = {
            ...navigatorBadges,
            reviews: extras.reviewsBadge,
        };
    }
}

/**
 * @param {Record<string, object>} health
 * @param {{ reviewsBadge?: number|null }} [extras]
 * @param {{ articleId?: number|null, generation?: number|null, incompleteWidgets?: Record<string, boolean> }} [meta]
 */
export function setRuntimeWidgetHealth(health, extras = {}, meta = {}) {
    if (isStaleOrWrongArticle(meta)) {
        return;
    }

    const incoming = health && typeof health === 'object' ? { ...health } : {};
    const incompleteWidgets = meta.incompleteWidgets && typeof meta.incompleteWidgets === 'object'
        ? meta.incompleteWidgets
        : {};

    const next = {};
    const allKeys = new Set([
        ...Object.keys(healthByWidgetId),
        ...Object.keys(incoming),
    ]);

    allKeys.forEach((widgetId) => {
        const prev = healthByWidgetId[widgetId];
        const candidate = incoming[widgetId];
        if (incompleteWidgets[widgetId] === true) {
            // Keep last stable diagnostics; refresh only.
            if (prev) {
                refreshStatusByWidgetId[widgetId] = 'refreshing';
                next[widgetId] = {
                    ...prev,
                    refresh_status: 'refreshing',
                };
            } else if (candidate) {
                // No stable yet — unknown is neutral, never error.
                refreshStatusByWidgetId[widgetId] = 'refreshing';
                next[widgetId] = {
                    key: widgetId,
                    item_count: Number(candidate.item_count ?? 0) || 0,
                    issue_count: 0,
                    error_count: 0,
                    warning_count: 0,
                    status: 'neutral',
                    reasons: [],
                    refresh_status: 'refreshing',
                };
            }
            return;
        }
        if (!candidate) {
            if (prev) {
                next[widgetId] = prev;
            }
            return;
        }
        refreshStatusByWidgetId[widgetId] = 'idle';
        next[widgetId] = {
            ...candidate,
            refresh_status: 'idle',
        };
    });

    healthByWidgetId = next;
    applyExtras(extras);
    emit();
}

/**
 * Merge widget health entries without wiping unrelated widgets.
 *
 * @param {Record<string, object>} partial
 * @param {{ reviewsBadge?: number|null }} [extras]
 * @param {{ articleId?: number|null, generation?: number|null, incompleteWidgets?: Record<string, boolean> }} [meta]
 */
export function patchRuntimeWidgetHealth(partial, extras = {}, meta = {}) {
    if (!partial || typeof partial !== 'object') {
        return;
    }
    if (isStaleOrWrongArticle(meta)) {
        return;
    }

    const incompleteWidgets = meta.incompleteWidgets && typeof meta.incompleteWidgets === 'object'
        ? meta.incompleteWidgets
        : {};
    const next = { ...healthByWidgetId };

    Object.keys(partial).forEach((widgetId) => {
        const candidate = partial[widgetId];
        if (!candidate || typeof candidate !== 'object') {
            return;
        }
        const prev = next[widgetId];
        if (incompleteWidgets[widgetId] === true) {
            if (prev) {
                refreshStatusByWidgetId[widgetId] = 'refreshing';
                next[widgetId] = {
                    ...prev,
                    refresh_status: 'refreshing',
                };
            } else {
                refreshStatusByWidgetId[widgetId] = 'refreshing';
                next[widgetId] = {
                    key: widgetId,
                    item_count: Number(candidate.item_count ?? 0) || 0,
                    issue_count: 0,
                    error_count: 0,
                    warning_count: 0,
                    status: 'neutral',
                    reasons: [],
                    refresh_status: 'refreshing',
                };
            }
            return;
        }
        refreshStatusByWidgetId[widgetId] = 'idle';
        next[widgetId] = {
            ...candidate,
            refresh_status: 'idle',
        };
    });

    healthByWidgetId = next;
    applyExtras(extras);
    emit();
}

/**
 * Partial badge patch (Links/CTA counts during 6C.2 transition).
 * Null/zero must not wipe a last-stable positive count during refresh.
 *
 * @param {Record<string, number|string|null|undefined>} partial
 * @param {{ articleId?: number|null, generation?: number|null, force?: boolean }} [meta]
 */
export function patchRuntimeNavigatorBadges(partial, meta = {}) {
    if (!partial || typeof partial !== 'object') {
        return;
    }
    if (isStaleOrWrongArticle(meta)) {
        return;
    }

    const next = { ...navigatorBadges };
    Object.keys(partial).forEach((key) => {
        if (partial[key] === undefined) {
            return;
        }
        const incoming = partial[key];
        const prev = next[key];
        const prevNum = Number(prev);
        const incomingNullish = incoming === null || incoming === '';
        const incomingZero = Number(incoming) === 0 && !Number.isNaN(Number(incoming));

        if (
            meta.force !== true
            && Number.isFinite(prevNum)
            && prevNum > 0
            && (incomingNullish || incomingZero)
        ) {
            return;
        }

        next[key] = incoming;
    });
    navigatorBadges = next;
    emit();
}

export function getRuntimeWidgetHealth() {
    return healthByWidgetId;
}

export function getRuntimeNavigatorBadges() {
    return navigatorBadges;
}

export function getRuntimeWidgetRefreshStatus(widgetId) {
    return refreshStatusByWidgetId[String(widgetId || '')] || 'idle';
}

/**
 * @param {(snapshot: { health: object, badges: object, generation: number, articleId: number }) => void} listener
 * @returns {() => void}
 */
export function subscribeRuntimeWidgetHealth(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }
    listeners.add(listener);
    try {
        listener(snapshot());
    } catch {
        // ignore initial push errors
    }
    return () => listeners.delete(listener);
}

/**
 * One-way shell summary — not per-module health mirror.
 * @param {Record<string, object>} health
 * @param {{ dirty?: boolean }} [meta]
 */
export function publishEditorShellHealthSummary(health, meta = {}) {
    if (typeof window === 'undefined') {
        return;
    }

    let blocking = 0;
    let overall = 'neutral';
    Object.values(health || {}).forEach((entry) => {
        // refresh_status must never contribute to shell severity.
        const status = String(entry?.status || 'neutral');
        const issueCount = Number(entry?.issue_count ?? entry?.error_count ?? 0);
        if (status === 'error' && issueCount > 0) {
            blocking += 1;
            overall = 'error';
        } else if (status === 'warning' && overall !== 'error') {
            overall = 'warning';
            blocking += issueCount > 0 ? 1 : 0;
        }
    });

    window.dispatchEvent(new CustomEvent('seo-editor-shell-health-summary', {
        detail: {
            overall_status: overall,
            blocking_issue_count: blocking,
            dirty: Boolean(meta.dirty),
        },
    }));
}

const BADGE_BRIDGE_FLAG = '__SEO_EDITOR_HEALTH_BADGE_BRIDGE__';

/**
 * Temporary: Links/CTA still emit navigator-badges — store consumes, Alpine does not.
 * @returns {() => void}
 */
export function installRuntimeHealthBadgeBridge() {
    if (typeof window === 'undefined' || window[BADGE_BRIDGE_FLAG]) {
        return () => {};
    }
    window[BADGE_BRIDGE_FLAG] = true;
    const onBadges = (event) => {
        const detail = event?.detail || {};
        patchRuntimeNavigatorBadges(detail);
        // Keep CTA chip metric-only health in sync (never inherit Links diagnostics).
        if (Object.prototype.hasOwnProperty.call(detail, 'cta')) {
            const count = Number(detail.cta);
            const prev = healthByWidgetId.cta;
            const itemCount = Number.isFinite(count) && count > 0
                ? count
                : (count === 0 ? 0 : Number(prev?.item_count ?? 0));
            patchRuntimeWidgetHealth({
                cta: {
                    key: 'cta',
                    item_count: itemCount,
                    issue_count: 0,
                    error_count: 0,
                    warning_count: 0,
                    status: itemCount > 0 ? 'success' : 'neutral',
                    reasons: [],
                },
            }, {}, {
                articleId: scopedArticleId || null,
                generation: diagnosticsGeneration,
            });
        }
    };
    const onSaveStart = () => {
        const articleId = Number(window.__SEO_ARTICLE_ID__ ?? scopedArticleId ?? 0) || 0;
        if (articleId > 0) {
            beginDiagnosticsRefresh(articleId);
        }
    };
    window.addEventListener('seo-assistant-navigator-badges', onBadges);
    window.addEventListener('article-editor-save-started', onSaveStart);
    // Save already emits article-editor-save-started; do not also bump on every heavy action
    // (sync/restore) — that caused generation races + sticky incomplete patches.
    return () => {
        window.removeEventListener('seo-assistant-navigator-badges', onBadges);
        window.removeEventListener('article-editor-save-started', onSaveStart);
        window[BADGE_BRIDGE_FLAG] = false;
    };
}

/** @internal test helper */
export function __resetRuntimeHealthStoreForTests() {
    scopedArticleId = 0;
    diagnosticsGeneration = 0;
    healthByWidgetId = {};
    navigatorBadges = {};
    refreshStatusByWidgetId = {};
}
