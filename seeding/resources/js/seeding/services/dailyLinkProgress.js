/**
 * Seeder-local daily progress for Topic-assigned links.
 * Ownership: DB assignment (id, url, title, target_per_day) vs local counts only.
 *
 * Shape: daily_link_progress[YYYY-MM-DD][assignment_id] = count
 */

/**
 * @param {Date} [date]
 * @returns {string}
 */
export function localDayKey(date = new Date()) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

/**
 * @param {unknown} raw
 * @returns {Record<string, Record<string, number>>}
 */
export function normalizeDailyLinkProgress(raw) {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {};
    /** @type {Record<string, Record<string, number>>} */
    const out = {};
    for (const [day, bucket] of Object.entries(raw)) {
        if (!bucket || typeof bucket !== 'object' || Array.isArray(bucket)) continue;
        /** @type {Record<string, number>} */
        const dayMap = {};
        for (const [id, count] of Object.entries(bucket)) {
            const n = Number(count);
            if (!Number.isFinite(n) || n <= 0) continue;
            dayMap[String(id)] = Math.floor(n);
        }
        if (Object.keys(dayMap).length > 0) {
            out[String(day)] = dayMap;
        }
    }
    return out;
}

/**
 * @param {Record<string, Record<string, number>>} progress
 * @param {string} [day]
 * @returns {Record<string, number>}
 */
export function todayProgressMap(progress, day = localDayKey()) {
    const bucket = progress?.[day];
    if (!bucket || typeof bucket !== 'object') return {};
    return { ...bucket };
}

/**
 * @param {Record<string, Record<string, number>>} progress
 * @param {string} assignmentId
 * @param {string} [day]
 * @returns {number}
 */
export function progressForAssignment(progress, assignmentId, day = localDayKey()) {
    const id = String(assignmentId || '');
    if (!id) return 0;
    return Math.max(0, Number(progress?.[day]?.[id]) || 0);
}

/**
 * @param {Record<string, Record<string, number>>} progress
 * @param {string} assignmentId
 * @param {number} [delta]
 * @param {string} [day]
 * @returns {Record<string, Record<string, number>>}
 */
export function bumpAssignmentProgress(progress, assignmentId, delta = 1, day = localDayKey()) {
    const id = String(assignmentId || '');
    if (!id || !Number.isFinite(delta) || delta === 0) {
        return normalizeDailyLinkProgress(progress);
    }
    const next = normalizeDailyLinkProgress(progress);
    const dayMap = { ...(next[day] || {}) };
    dayMap[id] = Math.max(0, (Number(dayMap[id]) || 0) + Math.floor(delta));
    if (dayMap[id] === 0) {
        delete dayMap[id];
    }
    if (Object.keys(dayMap).length === 0) {
        delete next[day];
    } else {
        next[day] = dayMap;
    }
    return next;
}

/**
 * Map Topic-assigned links into the selectable shape used by linkSelector / copyComment.
 *
 * @param {Array<Record<string, unknown>>} topicLinks
 * @returns {Array<Record<string, unknown>>}
 */
export function topicAssignedLinksAsSelectable(topicLinks) {
    return (topicLinks || [])
        .map((link) => {
            if (!link || typeof link !== 'object') return null;
            const url = String(link.url || link.normalized_url || '').trim();
            const id = String(link.id || '').trim();
            if (!url || !id) return null;
            const target = Math.max(0, Number(link.target_per_day) || 0);
            return {
                id,
                url,
                daily_limit: target > 0 ? target : 9999,
                is_active: true,
                label: String(link.title || link.label || '').trim(),
            };
        })
        .filter(Boolean);
}
