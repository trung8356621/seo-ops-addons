import {
    ALL_KEY,
    EVENT_NAME,
    HEADER_KEY,
    LEGACY_EVENT_NAME,
    QUERY_KEY,
    SITE_ID_QUERY_KEY,
    STORAGE_ACTIVE,
    STORAGE_LAST,
    buildUrlWithDomain,
    clearStorageKey,
    isAllDomains,
    isSeoPanelPath,
    normalizeDomainKey,
    readDomainFromUrl,
    readStorage,
    resolveDomainContext,
    sanitizeDomainKey,
    writeStorage,
} from './domainContextStore';

const BOOT_FLAG = '__SEO_DOMAIN_CONTEXT_BOOTED__';
const LOADING_CLASS = 'is-domain-context-loading';
const LOADING_DELAY_MS = 150;

function accessibleDomains() {
    const raw = window.__SEO_ACCESSIBLE_DOMAINS__;

    return Array.isArray(raw) ? raw.map((item) => normalizeDomainKey(item)) : [];
}

function currentKey() {
    return window.__SEO_DOMAIN_CONTEXT_KEY__ ?? ALL_KEY;
}

function persist(domainKey) {
    const key = sanitizeDomainKey(domainKey, accessibleDomains());
    writeStorage(window.sessionStorage, STORAGE_ACTIVE, key);
    writeStorage(window.localStorage, STORAGE_LAST, key);
    window.__SEO_DOMAIN_CONTEXT_KEY__ = key;

    return key;
}

function replaceUrl(domainKey) {
    if (typeof window.history?.replaceState !== 'function') {
        return;
    }

    const next = buildUrlWithDomain(window.location.href, domainKey);
    const current = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (next === current) {
        return;
    }

    window.history.replaceState(window.history.state, '', next);
}

function applyClientState(domainKey) {
    const key = persist(domainKey);
    replaceUrl(key);

    return key;
}

function syncAlpine(domainKey) {
    try {
        const store = window.Alpine?.store?.('domainContext');
        if (store) {
            store.domainKey = domainKey;
            store.isAllDomains = isAllDomains(domainKey);
        }
    } catch {
        // Alpine not ready.
    }
}

let loadingTimer = null;
let loadingSeq = 0;

function beginLoading() {
    const seq = ++loadingSeq;
    window.clearTimeout(loadingTimer);
    loadingTimer = window.setTimeout(() => {
        if (seq !== loadingSeq) {
            return;
        }
        document.querySelector('.fi-main')?.classList.add(LOADING_CLASS);
    }, LOADING_DELAY_MS);
}

function endLoading() {
    loadingSeq += 1;
    window.clearTimeout(loadingTimer);
    document.querySelector('.fi-main')?.classList.remove(LOADING_CLASS);
}

function dispatchLivewire(domainKey) {
    if (!window.Livewire || typeof window.Livewire.dispatch !== 'function') {
        return;
    }

    const siteId = isAllDomains(domainKey) ? null : domainKey;
    window.Livewire.dispatch(EVENT_NAME, { domain: domainKey, siteId: null });
    window.Livewire.dispatch(LEGACY_EVENT_NAME, { siteId });
}

function select(rawKey) {
    const key = applyClientState(rawKey);
    syncAlpine(key);
    beginLoading();
}

function resolveFromBrowser() {
    return resolveDomainContext({
        urlDomain: readDomainFromUrl(window.location.href),
        sessionDomain: readStorage(window.sessionStorage, STORAGE_ACTIVE),
        lastDomain: readStorage(window.localStorage, STORAGE_LAST),
        accessible: accessibleDomains(),
    });
}

function hydrateFromStorage() {
    const resolved = resolveFromBrowser();
    const key = applyClientState(resolved);
    syncAlpine(key);

    const serverKey = normalizeDomainKey(window.__SEO_DOMAIN_CONTEXT_FROM_SERVER__);
    if (key !== serverKey && window.Livewire) {
        beginLoading();
        const bars = typeof window.Livewire.getByName === 'function'
            ? window.Livewire.getByName('global-seo-bar')
            : [];
        const bar = Array.isArray(bars) ? bars[0] : null;
        if (bar && typeof bar.set === 'function') {
            bar.set('domainKey', key);
        } else {
            dispatchLivewire(key);
        }
    }

    return key;
}

function applyDomainHeader(headers, value) {
    if (!headers) {
        return { [HEADER_KEY]: value };
    }

    if (typeof Headers !== 'undefined' && headers instanceof Headers) {
        headers.set(HEADER_KEY, value);

        return headers;
    }

    headers[HEADER_KEY] = value;

    return headers;
}

function attachLivewireHeader() {
    if (!window.Livewire) {
        return;
    }

    if (typeof window.Livewire.interceptRequest === 'function') {
        window.Livewire.interceptRequest(({ request }) => {
            if (request && typeof request.addHeader === 'function') {
                request.addHeader(HEADER_KEY, currentKey());
            }
        });
    }

    if (typeof window.Livewire.hook !== 'function') {
        return;
    }

    window.Livewire.hook('request', ({ options }) => {
        if (!options) {
            return;
        }
        options.headers = applyDomainHeader(options.headers, currentKey());
    });

    window.Livewire.hook('commit', ({ succeed, fail }) => {
        succeed(() => endLoading());
        fail(() => endLoading());
    });
}

function interceptSeoLinks(event) {
    const anchor = event.target?.closest?.('a[href]');
    if (!anchor) {
        return;
    }

    const href = anchor.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
        return;
    }

    let url;
    try {
        url = new URL(href, window.location.origin);
    } catch {
        return;
    }

    if (url.origin !== window.location.origin || !isSeoPanelPath(url.href)) {
        return;
    }

    if (url.searchParams.has(QUERY_KEY) || url.searchParams.has(SITE_ID_QUERY_KEY)) {
        return;
    }

    url.searchParams.set(QUERY_KEY, currentKey());
    anchor.setAttribute('href', `${url.pathname}${url.search}${url.hash}`);
}

function attachNavigateHook() {
    document.addEventListener('livewire:navigate', (event) => {
        const url = event?.detail?.url;
        if (!url) {
            return;
        }

        try {
            const next = new URL(url, window.location.origin);
            if (!isSeoPanelPath(next.href) || next.searchParams.has(QUERY_KEY) || next.searchParams.has(SITE_ID_QUERY_KEY)) {
                return;
            }
            next.searchParams.set(QUERY_KEY, currentKey());
            event.detail.url = next.toString();
        } catch {
            // ignore
        }
    });
}

function registerAlpineStore(Alpine) {
    if (!Alpine || typeof Alpine.store !== 'function') {
        return;
    }

    try {
        const existing = Alpine.store('domainContext');
        if (existing && typeof existing.select === 'function') {
            return;
        }
    } catch {
        // continue
    }

    Alpine.store('domainContext', {
        domainKey: currentKey(),
        isAllDomains: isAllDomains(currentKey()),
        select(key) {
            return window.SeoDomainContext.select(key);
        },
    });
}

function exposeApi() {
    window.SeoDomainContext = {
        ALL_KEY,
        QUERY_KEY,
        SITE_ID_QUERY_KEY,
        domainKey: currentKey,
        isAllDomains: () => isAllDomains(currentKey()),
        select,
        hydrateFromStorage,
        resolveFromBrowser,
    };
}

export function bootDomainContext() {
    if (window[BOOT_FLAG]) {
        hydrateFromStorage();

        return;
    }

    window[BOOT_FLAG] = true;
    exposeApi();

    const initial = resolveFromBrowser();
    applyClientState(initial);
    syncAlpine(initial);

    document.addEventListener('click', interceptSeoLinks, true);
    attachNavigateHook();

    const startLivewire = () => {
        attachLivewireHeader();
        hydrateFromStorage();
    };

    if (window.Livewire) {
        startLivewire();
    } else {
        document.addEventListener('livewire:init', startLivewire, { once: true });
    }

    document.addEventListener('alpine:init', () => registerAlpineStore(window.Alpine));
    if (window.Alpine) {
        registerAlpineStore(window.Alpine);
    }

    document.addEventListener('livewire:navigated', () => {
        hydrateFromStorage();
    });
}

bootDomainContext();
