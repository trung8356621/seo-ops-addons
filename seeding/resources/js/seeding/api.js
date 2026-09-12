/**
 * Optional helpers for Seeding service endpoints.
 * Commit points: share topic, report. AI generate is stateless.
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
 * Shared feed + link usage cache.
 * @param {AbortSignal} [signal]
 */
export async function fetchSharedFeed(signal) {
    return seedingApiFetch('/api/seeding/feed', { method: 'GET', signal });
}

/**
 * Commit point A: share local draft → DB (Manager). May expand to nhiều topic.
 * @param {{
 *   title?: string,
 *   full_text: string,
 *   source_html?: string|null,
 *   social_url?: string,
 *   social_platform?: string,
 *   target_comments?: number,
 *   social_targets?: Array<{ social_platform: string, target_comments: number }>,
 *   links?: Array<Record<string, unknown>>,
 * }} payload
 */
export async function shareTopic(payload) {
    return seedingApiFetch('/api/seeding/topics/share', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

export async function fetchManagerTopics(params = {}) {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v != null && String(v) !== '') qs.set(k, String(v));
    });
    const suffix = qs.toString() ? `?${qs}` : '';
    return seedingApiFetch(`/api/seeding/manager/topics${suffix}`, { method: 'GET' });
}

export async function pauseManagerTopic(topicId) {
    return seedingApiFetch(`/api/seeding/manager/topics/${topicId}/pause`, { method: 'POST' });
}

export async function resumeManagerTopic(topicId) {
    return seedingApiFetch(`/api/seeding/manager/topics/${topicId}/resume`, { method: 'POST' });
}

export async function cancelManagerTopic(topicId) {
    return seedingApiFetch(`/api/seeding/manager/topics/${topicId}/cancel`, { method: 'POST' });
}

export async function fetchWebsiteShareFeed(filter = 'all') {
    const qs = filter && filter !== 'all' ? `?filter=${encodeURIComponent(filter)}` : '';
    return seedingApiFetch(`/api/seeding/website-share${qs}`, { method: 'GET' });
}

export async function updateWebsiteShareContent(jobId, shareContent) {
    return seedingApiFetch(`/api/seeding/website-share/${jobId}/content`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ share_content: shareContent }),
    });
}

export async function reportWebsiteShare(jobId, payload) {
    return seedingApiFetch(`/api/seeding/website-share/${jobId}/report`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

/**
 * Commit point B: report + proof.
 * @param {{
 *   topic_id: number|string,
 *   comment_text: string,
 *   seed_link_id?: string|null,
 *   seed_url?: string|null,
 *   proof: Blob|File,
 * }} payload
 */
export async function submitReport(payload) {
    const form = new FormData();
    form.append('topic_id', String(payload.topic_id));
    form.append('comment_text', String(payload.comment_text || ''));
    if (payload.seed_link_id) form.append('seed_link_id', String(payload.seed_link_id));
    if (payload.seed_url) form.append('seed_url', String(payload.seed_url));
    form.append('proof', payload.proof);

    return seedingApiFetch('/api/seeding/reports', {
        method: 'POST',
        body: form,
    });
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

/**
 * Fetch Open Graph preview for a URL. Failures return ok:false — never throw for soft fallbacks.
 * @param {string} url
 */
export async function fetchLinkPreview(url) {
    try {
        return await seedingApiFetch('/api/seeding/link-preview', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url }),
        });
    } catch (e) {
        return {
            ok: false,
            preview_url: url,
            preview_title: null,
            preview_description: null,
            preview_image_url: null,
            preview_domain: null,
            preview_fetched_at: new Date().toISOString(),
            preview_status: 'error',
            error: e?.message || 'preview failed',
        };
    }
}
