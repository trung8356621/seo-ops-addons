/**
 * Thread state persistence helper using sessionStorage.
 * Stores the active thread ULID scoped by appKey and scopeRef.
 * Does not replace the DB as source of truth for message transcripts.
 */

const STORAGE_PREFIX = 'agent_runtime_thread:';

export function getStoredThreadUlid(appKey = 'seo-ops', scopeRef = 'global') {
    if (typeof window === 'undefined' || !window.sessionStorage) {
        return null;
    }
    try {
        return window.sessionStorage.getItem(`${STORAGE_PREFIX}${appKey}:${scopeRef}`) || null;
    } catch {
        return null;
    }
}

export function setStoredThreadUlid(appKey = 'seo-ops', scopeRef = 'global', ulid = null) {
    if (typeof window === 'undefined' || !window.sessionStorage) {
        return;
    }
    try {
        const key = `${STORAGE_PREFIX}${appKey}:${scopeRef}`;
        if (ulid) {
            window.sessionStorage.setItem(key, ulid);
        } else {
            window.sessionStorage.removeItem(key);
        }
    } catch {
        // Ignore storage errors in private browsing/restricted environments
    }
}

export function clearStoredThreadUlid(appKey = 'seo-ops', scopeRef = 'global') {
    setStoredThreadUlid(appKey, scopeRef, null);
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
