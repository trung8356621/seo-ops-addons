export const ALL_KEY = 'all';
export const QUERY_KEY = 'domain';
export const HEADER_KEY = 'X-Seo-Domain-Context';
export const STORAGE_ACTIVE = 'seo_ops.active_domain';
export const STORAGE_LAST = 'seo_ops.last_domain';
export const EVENT_NAME = 'domain-context-changed';
export const LEGACY_EVENT_NAME = 'seoGlobalSiteChanged';

/**
 * @param {unknown} key
 * @returns {string}
 */
export function normalizeDomainKey(key) {
    const normalized = String(key ?? '').trim().toLowerCase();
    if (normalized === '' || normalized === '0' || normalized === '-1' || normalized === 'null') {
        return ALL_KEY;
    }

    return normalized;
}

/**
 * @param {unknown} key
 * @returns {boolean}
 */
export function isAllDomains(key) {
    return normalizeDomainKey(key) === ALL_KEY;
}

/**
 * @param {unknown} key
 * @param {string[]} accessible
 * @returns {string}
 */
export function sanitizeDomainKey(key, accessible = []) {
    const normalized = normalizeDomainKey(key);
    if (isAllDomains(normalized)) {
        return ALL_KEY;
    }

    const allowed = Array.isArray(accessible) ? accessible.map((item) => normalizeDomainKey(item)) : [];
    if (allowed.length > 0 && !allowed.includes(normalized)) {
        return ALL_KEY;
    }

    return normalized;
}

/**
 * Resolution: URL → sessionStorage (tab) → localStorage last-used → All domains.
 *
 * @param {{ urlDomain?: unknown, sessionDomain?: unknown, lastDomain?: unknown, accessible?: string[] }} sources
 * @returns {string}
 */
export function resolveDomainContext(sources = {}) {
    const accessible = Array.isArray(sources.accessible) ? sources.accessible : [];

    if (sources.urlDomain != null && String(sources.urlDomain).trim() !== '') {
        return sanitizeDomainKey(sources.urlDomain, accessible);
    }

    if (sources.sessionDomain != null && String(sources.sessionDomain).trim() !== '') {
        return sanitizeDomainKey(sources.sessionDomain, accessible);
    }

    if (sources.lastDomain != null && String(sources.lastDomain).trim() !== '') {
        return sanitizeDomainKey(sources.lastDomain, accessible);
    }

    return ALL_KEY;
}

/**
 * @param {string} href
 * @returns {string|null}
 */
export function readDomainFromUrl(href) {
    try {
        const url = new URL(href, 'http://local.test');
        const value = url.searchParams.get(QUERY_KEY);

        return value == null || String(value).trim() === '' ? null : String(value);
    } catch {
        return null;
    }
}

/**
 * @param {string} href
 * @param {string} domainKey
 * @returns {string}
 */
export function buildUrlWithDomain(href, domainKey) {
    const url = new URL(href, 'http://local.test');
    const key = normalizeDomainKey(domainKey);
    url.searchParams.set(QUERY_KEY, key);

    return `${url.pathname}${url.search}${url.hash}`;
}

/**
 * @param {string} href
 * @returns {boolean}
 */
export function isSeoPanelPath(href) {
    try {
        const url = new URL(href, 'http://local.test');

        return url.pathname === '/seo' || url.pathname.startsWith('/seo/');
    } catch {
        return false;
    }
}

/**
 * @param {Storage} storage
 * @param {string} key
 * @returns {string|null}
 */
export function readStorage(storage, key) {
    if (!storage || typeof storage.getItem !== 'function') {
        return null;
    }

    try {
        const value = storage.getItem(key);

        return value == null || String(value).trim() === '' ? null : String(value);
    } catch {
        return null;
    }
}

/**
 * @param {Storage} storage
 * @param {string} key
 * @param {string} value
 */
export function writeStorage(storage, key, value) {
    if (!storage || typeof storage.setItem !== 'function') {
        return;
    }

    try {
        storage.setItem(key, value);
    } catch {
        // Private mode / quota — ignore.
    }
}

/**
 * @param {Storage} storage
 * @param {string} key
 */
export function clearStorageKey(storage, key) {
    if (!storage || typeof storage.removeItem !== 'function') {
        return;
    }

    try {
        storage.removeItem(key);
    } catch {
        // ignore
    }
}
