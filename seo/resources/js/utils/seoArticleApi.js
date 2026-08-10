import {
    emitArticleEditorNetworkFailure,
    emitArticleEditorNetworkHttpOk,
} from '@content-addon/utils/articleEditorNetwork.js';

export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * @return {{ siteId: number|null, connectionHash: string }}
 */
export function readSeoArticleApiContext() {
    let siteId = null;
    let connectionHash =
        typeof window.__SEO_CONNECTION_HASH__ === 'string'
            ? window.__SEO_CONNECTION_HASH__.trim()
            : '';

    // Phase 2+: prefer core bootstrap (meta/initial-seo embeds removed).
    try {
        const coreEl = document.getElementById('seo-article-core-bootstrap');
        const rawCore = coreEl?.textContent?.trim();
        if (rawCore) {
            const core = JSON.parse(rawCore);
            const parsedSiteId = Number.parseInt(String(core?.siteId ?? core?.site_id ?? ''), 10);
            if (Number.isFinite(parsedSiteId) && parsedSiteId > 0) {
                siteId = parsedSiteId;
            }
            const coreHash = String(core?.connectionHash ?? core?.seo_connection_hash ?? '').trim();
            if (coreHash !== '') {
                connectionHash = coreHash;
            }
        }
    } catch {
        /* ignore invalid core bootstrap */
    }

    // Legacy fallback for older cached HTML.
    try {
        const metaEl = document.getElementById('seo-article-meta');
        const rawMeta = metaEl?.textContent?.trim();
        if (!rawMeta) {
            return { siteId, connectionHash };
        }

        const meta = JSON.parse(rawMeta);
        if (siteId === null) {
            const parsedSiteId = Number.parseInt(String(meta?.site_id ?? ''), 10);
            if (Number.isFinite(parsedSiteId) && parsedSiteId > 0) {
                siteId = parsedSiteId;
            }
        }

        const metaHash = String(meta?.seo_connection_hash ?? '').trim();
        if (connectionHash === '' && metaHash !== '') {
            connectionHash = metaHash;
        }
    } catch {
        /* ignore invalid meta */
    }

    return { siteId, connectionHash };
}

/**
 * @param {Record<string, string>} extraHeaders
 * @return {Record<string, string>}
 */
export function seoArticleApiHeaders(extraHeaders = {}) {
    const { siteId, connectionHash } = readSeoArticleApiContext();
    const headers = { ...extraHeaders };

    if (connectionHash !== '') {
        headers['X-SEO-Connection'] = connectionHash;
    }

    if (siteId !== null && siteId > 0) {
        headers['X-Site-ID'] = String(siteId);
    }

    return headers;
}

/**
 * @param {string} url
 * @param {RequestInit & { headers?: Record<string, string> }} [options]
 */
export async function seoArticleApiFetch(url, options = {}) {
    const method = String(options.method ?? 'GET').toUpperCase();
    const token = csrfToken();
    const needsCsrf = !['GET', 'HEAD', 'OPTIONS'].includes(method);
    const incomingHeaders = options.headers ?? {};
    const hasContentType = Object.keys(incomingHeaders).some(
        (key) => key.toLowerCase() === 'content-type',
    );
    // Only auto-tag string JSON bodies. Never set Content-Type on FormData/Blob.
    const isJsonStringBody = typeof options.body === 'string' && options.body !== '';

    let response;
    try {
        response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
            headers: {
                Accept: 'application/json',
                // Laravel only parses JSON bodies when Content-Type is application/json.
                // Missing header → empty input → editor-sessions 422 invalid_client_instance_id.
                ...(isJsonStringBody && !hasContentType ? { 'Content-Type': 'application/json' } : {}),
                ...seoArticleApiHeaders(),
                ...(needsCsrf && token !== '' ? { 'X-CSRF-TOKEN': token } : {}),
                ...incomingHeaders,
            },
        });
    } catch (error) {
        // Network-level only (no HTTP response). HTTP 4xx/5xx never land here.
        emitArticleEditorNetworkFailure(error);
        throw error;
    }

    // Any HTTP response proves backend reachable (4xx/5xx stay app-error flows).
    emitArticleEditorNetworkHttpOk();

    const data = await response.json().catch(() => ({}));

    return { response, data };
}
