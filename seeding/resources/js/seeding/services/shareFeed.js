/**
 * Optimistic share + feed helpers (pure).
 */

/**
 * Remove local draft after optimistic share.
 * @param {Array<Record<string, unknown>>} topics
 * @param {string} draftKey
 */
export function removeDraftTopic(topics, draftKey) {
    return (topics || []).filter((t) => String(t.localId || t.id) !== String(draftKey));
}

/**
 * Restore draft on share failure.
 * @param {Array<Record<string, unknown>>} topics
 * @param {Record<string, unknown>} draft
 * @param {number} [index]
 */
export function restoreDraftTopic(topics, draft, index = 0) {
    const list = [...(topics || [])];
    const at = Math.max(0, Math.min(list.length, Number(index) || 0));
    list.splice(at, 0, draft);
    return list;
}

/**
 * Merge server feed into local shared topics without remounting identity when possible.
 * Local drafts (localId starting with draft:) are preserved.
 *
 * @param {Array<Record<string, unknown>>} localTopics
 * @param {Array<Record<string, unknown>>} feedTopics
 * @param {number|string} userId
 */
export function mergeSharedFeed(localTopics, feedTopics, userId) {
    const drafts = (localTopics || []).filter((t) => {
        const state = t.state || 'draft';
        if (state === 'draft' || String(t.localId || '').startsWith('draft:')) {
            return String(t.created_by_user_id) === String(userId) || t.created_by_user_id == null;
        }
        return false;
    });

    const byId = new Map();
    for (const t of feedTopics || []) {
        if (t?.id == null) continue;
        byId.set(String(t.id), {
            ...t,
            state: 'shared',
            localId: undefined,
        });
    }

    // Keep locally tracked progress if feed omitted a field briefly
    for (const prev of localTopics || []) {
        if (prev?.id == null || prev.state === 'draft') continue;
        const key = String(prev.id);
        if (!byId.has(key)) continue;
        const next = byId.get(key);
        if (next.current_user_report_count == null && prev.current_user_report_count != null) {
            next.current_user_report_count = prev.current_user_report_count;
        }
    }

    return [...drafts, ...byId.values()];
}

/**
 * Apply report success locally.
 * @param {{
 *   topics: Array<Record<string, unknown>>,
 *   generatedComments: Array<Record<string, unknown>>,
 *   topicId: string|number,
 *   commentId: string,
 *   userReportCount: number,
 *   required: number,
 *   linkUsageToday: Record<string, number>,
 *   seedLinkId?: string|null,
 * }} opts
 */
export function applyReportSuccessLocal(opts) {
    const topicId = String(opts.topicId);
    const required = Math.max(1, Number(opts.required) || 1);
    const count = Math.max(0, Number(opts.userReportCount) || 0);
    const completed = count >= required;

    let topics = (opts.topics || []).map((t) => {
        if (String(t.id) !== topicId) return t;
        return {
            ...t,
            current_user_report_count: count,
            required_report_count: required,
            eligibility: {
                ...(t.eligibility || {}),
                eligible: !completed,
                remaining: Math.max(0, required - count),
            },
        };
    });

    let generatedComments = (opts.generatedComments || []).filter(
        (c) => String(c.id) !== String(opts.commentId),
    );

    if (completed) {
        topics = topics.filter((t) => String(t.id) !== topicId);
        generatedComments = generatedComments.filter((c) => String(c.topic_id) !== topicId);
    }

    const linkUsageToday = { ...(opts.linkUsageToday || {}) };
    if (opts.seedLinkId) {
        const key = String(opts.seedLinkId);
        linkUsageToday[key] = (Number(linkUsageToday[key]) || 0) + 1;
    }

    return {
        topics,
        generatedComments,
        linkUsageToday,
        completed,
    };
}
