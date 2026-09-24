function csrfHeaders(csrfToken) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    if (csrfToken) {
        headers['X-CSRF-TOKEN'] = csrfToken;
        headers['X-XSRF-TOKEN'] = csrfToken;
    }
    return headers;
}

async function parseJson(response) {
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const message = data?.message || `HTTP ${response.status}`;
        const error = new Error(message);
        error.status = response.status;
        error.payload = data;
        throw error;
    }
    return data;
}

export function createApi(config) {
    const endpoints = config.endpoints || {};
    const siteId = Number(config.siteId || 0);
    const csrfToken = config.csrfToken || '';

    function withSite(url, extra = {}) {
        const u = new URL(url, window.location.origin);
        u.searchParams.set('site_id', String(siteId));
        Object.entries(extra).forEach(([k, v]) => {
            if (v === null || v === undefined || v === '') {
                return;
            }
            u.searchParams.set(k, String(v));
        });
        return u.toString();
    }

    return {
        async fetchOverview() {
            return parseJson(
                await fetch(withSite(endpoints.overview), {
                    credentials: 'same-origin',
                    headers: csrfHeaders(csrfToken),
                }),
            );
        },

        async fetchTopicChildren(topicId) {
            const path = String(endpoints.topicChildren || '').replace('{topic}', String(topicId));
            return parseJson(
                await fetch(withSite(path), {
                    credentials: 'same-origin',
                    headers: csrfHeaders(csrfToken),
                }),
            );
        },

        async fetchNetwork(topicId = null) {
            return parseJson(
                await fetch(withSite(endpoints.network, { topic_id: topicId }), {
                    credentials: 'same-origin',
                    headers: csrfHeaders(csrfToken),
                }),
            );
        },

        async fetchAuditStatus() {
            return parseJson(
                await fetch(withSite(endpoints.auditStatus), {
                    credentials: 'same-origin',
                    headers: csrfHeaders(csrfToken),
                }),
            );
        },

        async runAudit() {
            return parseJson(
                await fetch(endpoints.runAudit, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        ...csrfHeaders(csrfToken),
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ site_id: siteId }),
                }),
            );
        },
    };
}

export function topicDetailUrl(template, topicId) {
    return String(template || '').replace('{topic}', String(topicId));
}
