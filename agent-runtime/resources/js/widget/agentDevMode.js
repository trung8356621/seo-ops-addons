export const DEV_MODE_NORMAL = 'normal';
export const DEV_MODE_DEBUG = 'debug';
export const DEV_MODE_DIAG = 'diag';

const VALID_MODES = new Set([DEV_MODE_NORMAL, DEV_MODE_DEBUG, DEV_MODE_DIAG]);

export function getDeveloperModeStorageKey(appKey = 'seo-ops') {
    return `agent-runtime:developer-mode:${appKey || 'seo-ops'}`;
}

export function getStoredDeveloperMode(appKey = 'seo-ops') {
    try {
        if (typeof window === 'undefined' || !window.localStorage) {
            return DEV_MODE_NORMAL;
        }
        const stored = window.localStorage.getItem(getDeveloperModeStorageKey(appKey));
        return VALID_MODES.has(stored) ? stored : DEV_MODE_NORMAL;
    } catch {
        return DEV_MODE_NORMAL;
    }
}

export function setStoredDeveloperMode(appKey = 'seo-ops', mode = DEV_MODE_NORMAL) {
    try {
        if (typeof window === 'undefined' || !window.localStorage) {
            return;
        }
        if (VALID_MODES.has(mode)) {
            window.localStorage.setItem(getDeveloperModeStorageKey(appKey), mode);
        } else {
            window.localStorage.setItem(getDeveloperModeStorageKey(appKey), DEV_MODE_NORMAL);
        }
    } catch {
        // Ignore storage access errors
    }
}
