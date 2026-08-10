const STORAGE_KEY = 'seo-keyword-review-recent-reasons';
const MAX_RECENT = 12;
const MAX_SUGGESTIONS = 7;

/**
 * @returns {number[]}
 */
export function loadRecentKeywordReviewReasonIds() {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return [];
        }

        return parsed
            .map((value) => Number(value))
            .filter((value) => Number.isFinite(value) && value > 0)
            .slice(0, MAX_RECENT);
    } catch {
        return [];
    }
}

/**
 * @param {number} reasonId
 */
export function rememberKeywordReviewReasonId(reasonId) {
    const id = Number(reasonId);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    const next = [id, ...loadRecentKeywordReviewReasonIds().filter((value) => value !== id)].slice(0, MAX_RECENT);

    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch {
        // ignore quota errors
    }
}

/**
 * @param {Array<{ id: number, name: string, default_severity?: string }>} reasons
 * @param {{ severity: 'warning'|'danger', query?: string, recentIds?: number[] }} options
 * @returns {Array<{ id: number, name: string, default_severity?: string }>}
 */
export function rankKeywordReviewReasons(reasons, options) {
    const severity = options.severity === 'danger' ? 'danger' : 'warning';
    const query = String(options.query ?? '').trim().toLowerCase();
    const recentIds = Array.isArray(options.recentIds) ? options.recentIds : [];

    const active = reasons.filter((reason) => reason && reason.name);

    const pool = query === ''
        ? active
        : active.filter((reason) => String(reason.name).toLowerCase().includes(query));

    const scored = pool.map((reason) => {
        let score = 0;

        if (String(reason.default_severity ?? '') === severity) {
            score += 100;
        }

        const recentIndex = recentIds.indexOf(Number(reason.id));
        if (recentIndex >= 0) {
            score += (recentIds.length - recentIndex) * 10;
        }

        return { reason, score };
    });

    scored.sort((left, right) => {
        if (right.score !== left.score) {
            return right.score - left.score;
        }

        return String(left.reason.name).localeCompare(String(right.reason.name), undefined, { sensitivity: 'base' });
    });

    return scored.slice(0, MAX_SUGGESTIONS).map((entry) => entry.reason);
}

/**
 * @param {Array<{ id: number, name: string }>} reasons
 * @param {string} query
 */
export function hasExactKeywordReviewReason(reasons, query) {
    const normalized = String(query ?? '').trim().toLowerCase();
    if (normalized === '') {
        return false;
    }

    return reasons.some((reason) => String(reason.name).trim().toLowerCase() === normalized);
}

export { MAX_SUGGESTIONS };
