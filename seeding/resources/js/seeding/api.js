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
        const fieldError = data?.errors
            ? Object.values(data.errors).flat().find((m) => typeof m === 'string' && m.trim() !== '')
            : null;
        const err = new Error(fieldError || data?.message || `HTTP ${response.status}`);
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

/**
 * Manager-only: historical seeding_reports (inspection + approve).
 * @param {{
 *   user_id?: number|string,
 *   topic_id?: number|string,
 *   social?: string,
 *   search?: string,
 *   date_from?: string,
 *   date_to?: string,
 *   status?: 'pending'|'approved'|string,
 * }} [params]
 */
export async function fetchManagerReports(params = {}) {
    const qs = new URLSearchParams();
    Object.entries(params).forEach(([k, v]) => {
        if (v != null && String(v) !== '') qs.set(k, String(v));
    });
    const suffix = qs.toString() ? `?${qs}` : '';
    return seedingApiFetch(`/api/seeding/manager/reports${suffix}`, { method: 'GET' });
}

/** Absolute-ish URL for proof bytes (auth cookie + manager gate). */
export function managerReportProofUrl(reportId) {
    return `/api/seeding/manager/reports/${Number(reportId)}/proof`;
}

/** Manager-only: mark report proof as reviewed. Idempotent. */
export async function approveManagerReport(reportId) {
    return seedingApiFetch(`/api/seeding/manager/reports/${Number(reportId)}/approve`, {
        method: 'POST',
    });
}

/** Manager-only: social login accounts catalog. */
export async function fetchSocialAccounts() {
    return seedingApiFetch('/api/seeding/manager/social-accounts', { method: 'GET' });
}

/**
 * @param {{
 *   site_id?: number,
 *   domain?: string,
 *   platform: string,
 *   label?: string|null,
 *   username?: string|null,
 *   password?: string|null,
 *   status?: string,
 * }} payload
 */
export async function createSocialAccount(payload) {
    return seedingApiFetch('/api/seeding/manager/social-accounts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

/**
 * @param {number|string} accountId
 * @param {Record<string, unknown>} payload
 */
export async function updateSocialAccount(accountId, payload) {
    return seedingApiFetch(`/api/seeding/manager/social-accounts/${Number(accountId)}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

export async function lockSocialAccount(accountId) {
    return seedingApiFetch(`/api/seeding/manager/social-accounts/${Number(accountId)}/lock`, {
        method: 'POST',
    });
}

export async function unlockSocialAccount(accountId) {
    return seedingApiFetch(`/api/seeding/manager/social-accounts/${Number(accountId)}/unlock`, {
        method: 'POST',
    });
}

export async function deleteSocialAccount(accountId) {
    return seedingApiFetch(`/api/seeding/manager/social-accounts/${Number(accountId)}`, {
        method: 'DELETE',
    });
}

/** Explicit Manager copy — returns plaintext once for clipboard; never render. */
export async function copySocialAccountUsername(accountId) {
    return seedingApiFetch(`/api/seeding/manager/social-accounts/${Number(accountId)}/copy-username`, {
        method: 'POST',
    });
}

export async function copySocialAccountPassword(accountId) {
    return seedingApiFetch(`/api/seeding/manager/social-accounts/${Number(accountId)}/copy-password`, {
        method: 'POST',
    });
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

export async function generateWebsiteShareContent(jobId) {
    return seedingApiFetch(`/api/seeding/website-share/${jobId}/generate`, {
        method: 'POST',
    });
}

export async function updateWebsiteShareTargetContent(jobId, targetId, content) {
    return seedingApiFetch(`/api/seeding/website-share/${jobId}/targets/${targetId}/content`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ content }),
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
 * Stateless AI comment generation — Manager prompt + debug history on server.
 * Boundary payload may include legacy full_text/social_url/count/platform.
 * AI task itself receives only normalized plain-text context + rendered Manager prompt.
 * @param {{
 *   source_type?: string,
 *   content?: string,
 *   url?: string,
 *   social?: string,
 *   quantity?: number,
 *   topic_id?: number|string,
 *   full_text?: string,
 *   social_url?: string,
 *   count?: number,
 *   platform?: string|null,
 *   title?: string|null,
 *   description?: string|null,
 *   domain?: string|null,
 * }} payload
 * @returns {Promise<{ comments: string[] }>}
 */
export async function generateSampleComments(payload) {
    return seedingApiFetch('/api/seeding/comments/generate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

/** Manager: Gen Comment prompt + history */
export async function fetchCommentPrompt() {
    return seedingApiFetch('/api/seeding/manager/comment-prompt', { method: 'GET' });
}

export async function saveCommentPrompt(promptBody) {
    return seedingApiFetch('/api/seeding/manager/comment-prompt', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt_body: promptBody }),
    });
}

export async function fetchCommentPromptHistory() {
    return seedingApiFetch('/api/seeding/manager/comment-prompt/history', { method: 'GET' });
}

export async function fetchCommentPromptHistoryDetail(slot) {
    return seedingApiFetch(`/api/seeding/manager/comment-prompt/history/${slot}`, { method: 'GET' });
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

/** Shared assignment list (FLOW B) — any workspace user; default scope=shared */
export async function fetchLinkAssignments(activeOnly = true, scope = 'shared') {
    const params = new URLSearchParams();
    params.set('scope', scope === 'mine' ? 'mine' : 'shared');
    if (activeOnly) {
        params.set('active_only', '1');
    } else {
        params.set('active_only', '0');
    }
    return seedingApiFetch(`/api/seeding/link-assignments?${params.toString()}`, { method: 'GET' });
}

/** Creator manage catalog (own rows) */
export async function fetchMyLinkAssignments(activeOnly = false) {
    return fetchLinkAssignments(activeOnly, 'mine');
}

/**
 * @param {{ title?: string, url: string, target_per_day?: number, is_active?: boolean }} payload
 */
export async function createLinkAssignment(payload) {
    return seedingApiFetch('/api/seeding/link-assignments', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

/**
 * @param {number|string} assignmentId numeric DB id
 * @param {{ title?: string, url?: string, target_per_day?: number, is_active?: boolean }} payload
 */
export async function updateLinkAssignment(assignmentId, payload) {
    return seedingApiFetch(`/api/seeding/link-assignments/${assignmentId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
}

/** @param {number|string} assignmentId numeric DB id */
export async function deleteLinkAssignment(assignmentId) {
    return seedingApiFetch(`/api/seeding/link-assignments/${assignmentId}`, {
        method: 'DELETE',
    });
}

/**
 * One-time import of legacy local seed_links into DB.
 * @param {Array<Record<string, unknown>>} links
 */
export async function importLocalLinkAssignments(links) {
    return seedingApiFetch('/api/seeding/link-assignments/import-local', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ links }),
    });
}

/**
 * Extract numeric DB id from public id `assign:123` or raw number.
 * @param {string|number|null|undefined} publicOrNumeric
 * @returns {number|null}
 */
export function assignmentNumericId(publicOrNumeric) {
    const raw = String(publicOrNumeric ?? '').trim();
    if (!raw) return null;
    const m = raw.match(/^assign:(\d+)$/i);
    if (m) return Number(m[1]);
    if (/^\d+$/.test(raw)) return Number(raw);
    return null;
}
