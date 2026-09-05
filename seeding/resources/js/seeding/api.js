function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function xsrfTokenFromCookie() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    if (!match) {
        return '';
    }
    try {
        return decodeURIComponent(match[1]);
    } catch {
        return match[1] || '';
    }
}

/**
 * @param {string} url
 * @param {RequestInit & { headers?: Record<string, string> }} [options]
 */
export async function seedingApiFetch(url, options = {}) {
    const method = String(options.method ?? 'GET').toUpperCase();
    const needsCsrf = !['GET', 'HEAD', 'OPTIONS'].includes(method);
    const token = csrfToken();
    const xsrf = xsrfTokenFromCookie();
    const incoming = options.headers ?? {};
    const hasContentType = Object.keys(incoming).some((k) => k.toLowerCase() === 'content-type');
    const isJsonStringBody = typeof options.body === 'string' && options.body !== '';

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...(isJsonStringBody && !hasContentType ? { 'Content-Type': 'application/json' } : {}),
            ...(needsCsrf && token !== '' ? { 'X-CSRF-TOKEN': token } : {}),
            ...(needsCsrf && xsrf !== '' ? { 'X-XSRF-TOKEN': xsrf } : {}),
            ...incoming,
        },
    });

    let data = null;
    const text = await response.text();
    if (text !== '') {
        try {
            data = JSON.parse(text);
        } catch {
            data = { message: text };
        }
    }

    if (!response.ok) {
        const err = new Error(data?.message || `HTTP ${response.status}`);
        err.status = response.status;
        err.data = data;
        throw err;
    }

    return data;
}

export function buildTopicsUrl(apiBase, siteId, archived = false) {
    const url = new URL(apiBase, window.location.origin);
    url.searchParams.set('site_id', String(siteId));
    if (archived) {
        url.searchParams.set('archived', '1');
    }
    return url.toString();
}

export function buildTopicUrl(apiBase, topicId, siteId) {
    const url = new URL(`${apiBase.replace(/\/$/, '')}/${topicId}`, window.location.origin);
    url.searchParams.set('site_id', String(siteId));
    return url.toString();
}
