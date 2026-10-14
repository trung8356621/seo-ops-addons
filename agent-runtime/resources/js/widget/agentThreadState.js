/**
 * Thread state persistence helper using sessionStorage.
 * The active ULID is scoped by authenticated principal, appKey and scopeRef.
 * Keys from another account are never read. The database remains the transcript source.
 */

const STORAGE_PREFIX = 'agent_runtime_thread:';

export function threadStorageKey(appKey = 'seo-ops', scopeRef = 'global', principalId = '') {
    const principal = String(principalId || '').trim();
    if (principal === '') {
        return null;
    }

    return `${STORAGE_PREFIX}${principal}:${appKey}:${scopeRef}`;
}

export function getStoredThreadUlid(appKey = 'seo-ops', scopeRef = 'global', principalId = '') {
    const key = threadStorageKey(appKey, scopeRef, principalId);
    if (key === null || typeof window === 'undefined' || !window.sessionStorage) {
        return null;
    }
    try {
        return window.sessionStorage.getItem(key) || null;
    } catch {
        return null;
    }
}

export function setStoredThreadUlid(appKey = 'seo-ops', scopeRef = 'global', ulid = null, principalId = '') {
    const key = threadStorageKey(appKey, scopeRef, principalId);
    if (key === null || typeof window === 'undefined' || !window.sessionStorage) {
        return;
    }
    try {
        if (ulid) {
            window.sessionStorage.setItem(key, ulid);
        } else {
            window.sessionStorage.removeItem(key);
        }
    } catch {
        // Ignore storage errors in private browsing/restricted environments
    }
}

export function clearStoredThreadUlid(appKey = 'seo-ops', scopeRef = 'global', principalId = '') {
    setStoredThreadUlid(appKey, scopeRef, null, principalId);
}

/**
 * Client recovery after a 404 Thread not found.
 * Clears the stale selection and keeps unsent text. Does not resubmit.
 */
export function recoverMissingThread({ draft = '', pendingText = '' } = {}) {
    const kept = String(pendingText || '').trim() !== '' ? pendingText : draft;

    return {
        activeThreadUlid: null,
        viewingThreadUlid: null,
        isViewingArchived: false,
        messages: [],
        threadLoadError: '',
        draft: kept,
        resubmit: false,
    };
}

/**
 * Formats an ISO date string into a compact, human-readable relative or short date label.
 *
 * @param {string|null} dateString
 * @returns {string}
 */
export function formatTimeAgo(dateString) {
    if (!dateString) {
        return '';
    }
    try {
        const date = new Date(dateString);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        const now = new Date();
        const diffSec = Math.floor((now.getTime() - date.getTime()) / 1000);
        if (diffSec < 45) {
            return 'just now';
        }
        const diffMin = Math.floor(diffSec / 60);
        if (diffMin < 60) {
            return `${diffMin}m ago`;
        }
        const diffHour = Math.floor(diffMin / 60);
        if (diffHour < 24) {
            return `${diffHour}h ago`;
        }
        const diffDay = Math.floor(diffHour / 24);
        if (diffDay < 7) {
            return `${diffDay}d ago`;
        }
        return date.toLocaleDateString();
    } catch {
        return '';
    }
}
