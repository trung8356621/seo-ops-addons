/**
 * Phase 6C.1 — React-owned widget health for navigation chips.
 * Not Alpine SoT. Shell may receive one-way summary only.
 */

/** @type {Record<string, object>} */
let healthByWidgetId = {};
/** @type {Record<string, number|string|null>} */
let navigatorBadges = {};
/** @type {Set<(snapshot: { health: object, badges: object }) => void>} */
const listeners = new Set();

function snapshot() {
    return {
        health: healthByWidgetId,
        badges: navigatorBadges,
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
 * @param {Record<string, object>} health
 * @param {{ reviewsBadge?: number|null }} [extras]
 */
export function setRuntimeWidgetHealth(health, extras = {}) {
    healthByWidgetId = health && typeof health === 'object' ? { ...health } : {};
    if (Object.prototype.hasOwnProperty.call(extras, 'reviewsBadge')) {
        navigatorBadges = {
            ...navigatorBadges,
            reviews: extras.reviewsBadge,
        };
    }
    emit();
}

/**
 * Merge widget health entries without wiping unrelated widgets.
 * Used so typing (blocks) can refresh images/seo/links without rebuilding featured/gallery.
 *
 * @param {Record<string, object>} partial
 * @param {{ reviewsBadge?: number|null }} [extras]
 */
export function patchRuntimeWidgetHealth(partial, extras = {}) {
    if (!partial || typeof partial !== 'object') {
        return;
    }
    healthByWidgetId = {
        ...healthByWidgetId,
        ...partial,
    };
    if (Object.prototype.hasOwnProperty.call(extras, 'reviewsBadge')) {
        navigatorBadges = {
            ...navigatorBadges,
            reviews: extras.reviewsBadge,
        };
    }
    emit();
}

/**
 * Partial badge patch (Links/CTA counts during 6C.2 transition).
 * @param {Record<string, number|string|null|undefined>} partial
 */
export function patchRuntimeNavigatorBadges(partial) {
    if (!partial || typeof partial !== 'object') {
        return;
    }
    const next = { ...navigatorBadges };
    Object.keys(partial).forEach((key) => {
        if (partial[key] !== undefined) {
            next[key] = partial[key];
        }
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

/**
 * @param {(snapshot: { health: object, badges: object }) => void} listener
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
        const status = String(entry?.status || 'neutral');
        if (status === 'error') {
            blocking += 1;
            overall = 'error';
        } else if (status === 'warning' && overall !== 'error') {
            overall = 'warning';
            blocking += Number(entry?.issue_count ?? entry?.warning_count ?? 0) > 0 ? 1 : 0;
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
        patchRuntimeNavigatorBadges(event?.detail || {});
    };
    window.addEventListener('seo-assistant-navigator-badges', onBadges);
    return () => {
        window.removeEventListener('seo-assistant-navigator-badges', onBadges);
        window[BADGE_BRIDGE_FLAG] = false;
    };
}
