/**
 * Optional helpers for Seeding service endpoints (bootstrap / health / AI generate).
 * Canonical workspace does NOT use topic CRUD persistence.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * @param {string} url
 * @param {RequestInit & { headers?: Record<string, string> }} [options]
 */
export async function seedingApiFetch(url, options = {}) {
    const method = String(options.method ?? 'GET').toUpperCase();
    const needsCsrf = !['GET', 'HEAD', 'OPTIONS'].includes(method);
    const token = csrfToken();
    const incoming = options.headers ?? {};

    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            ...(needsCsrf && token !== '' ? { 'X-CSRF-TOKEN': token } : {}),
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

/**
 * Stateless AI comment generation — no Seeding DB writes.
 * @param {{ full_text: string, social_url?: string, count?: number, platform?: string|null }} payload
 * @returns {Promise<{ comments: string[] }>}
 */
export async function generateSampleComments(payload) {
    return seedingApiFetch('/api/seeding/comments/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}
