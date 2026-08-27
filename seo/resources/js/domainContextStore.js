export const ALL_KEY = 'all';
export const QUERY_KEY = 'domain';
export const SITE_ID_QUERY_KEY = 'site_id';
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
 * Resolution: URL → sessionStorage (tab) → localStorage last-used → first accessible domain.
 * Never default to All when at least one accessible domain exists.
 * Explicit `domain=all` in the URL is still honored.
 *
 * @param {{ urlDomain?: unknown, sessionDomain?: unknown, lastDomain?: unknown, accessible?: string[] }} sources
 * @returns {string}
 */
export function resolveDomainContext(sources = {}) {
    const accessible = Array.isArray(sources.accessible)
        ? sources.accessible
            .map((item) => normalizeDomainKey(item))
            .filter((item) => item !== '' && item !== ALL_KEY)
        : [];
    const fallback = accessible[0] ?? ALL_KEY;

    if (sources.urlDomain != null && String(sources.urlDomain).trim() !== '') {
        const raw = normalizeDomainKey(sources.urlDomain);
        if (raw === ALL_KEY) {
            return ALL_KEY;
        }
        const fromUrl = sanitizeDomainKey(sources.urlDomain, accessible);
        return isAllDomains(fromUrl) ? fallback : fromUrl;
    }

    if (sources.sessionDomain != null && String(sources.sessionDomain).trim() !== '') {
        const fromSession = sanitizeDomainKey(sources.sessionDomain, accessible);
        if (! isAllDomains(fromSession)) {
            return fromSession;
        }
    }

    if (sources.lastDomain != null && String(sources.lastDomain).trim() !== '') {
        const fromLast = sanitizeDomainKey(sources.lastDomain, accessible);
        if (! isAllDomains(fromLast)) {
            return fromLast;
        }
    }

    return fallback;
}

/**
 * @returns {Record<string, number>}
 */
export function accessibleSiteIdsByDomainKey() {
    if (typeof window === 'undefined') {
        return {};
    }

    const raw = window.__SEO_SITE_IDS_BY_DOMAIN__;
    if (!raw || typeof raw !== 'object') {
        return {};
    }

    /** @type {Record<string, number>} */
    const map = {};
    for (const [key, value] of Object.entries(raw)) {
        const siteId = Number(value);
        if (Number.isInteger(siteId) && siteId > 0) {
            map[normalizeDomainKey(key)] = siteId;
        }
    }

    return map;
}

/**
 * @param {string} domainKey
 * @returns {number|null}
 */
export function resolveSiteIdFromDomainKey(domainKey) {
    const key = normalizeDomainKey(domainKey);
    if (isAllDomains(key)) {
        return null;
    }
    if (/^\d+$/.test(key)) {
        return Number(key);
    }

    const mapped = accessibleSiteIdsByDomainKey()[key];
    return Number.isInteger(mapped) && mapped > 0 ? mapped : null;
}

/**
 * @param {string} href
 * @returns {string|null}
 */
export function readDomainFromUrl(href) {
    try {
        const url = new URL(href, 'http://local.test');
        const siteId = url.searchParams.get(SITE_ID_QUERY_KEY);
        if (siteId != null && String(siteId).trim() !== '' && /^\d+$/.test(String(siteId).trim())) {
            return String(Number(siteId));
        }

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
    url.searchParams.delete(QUERY_KEY);
    url.searchParams.delete(SITE_ID_QUERY_KEY);

    if (isAllDomains(key)) {
        url.searchParams.set(QUERY_KEY, ALL_KEY);
    } else {
        const siteId = resolveSiteIdFromDomainKey(key);
        if (siteId != null) {
            url.searchParams.set(SITE_ID_QUERY_KEY, String(siteId));
        } else {
            url.searchParams.set(QUERY_KEY, key);
        }
    }

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
