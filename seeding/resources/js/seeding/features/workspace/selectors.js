import { previewText, stateLabel, topicKeyOf } from '../../services/storage';
import { detectPlatformLabel, hostOf } from '../../services/linkExtract';
import { isAutoCopiedTitle } from './content';
import { canShareTopic, shareStatusLabel, shareStatusOf } from './auth';

export { detectPlatformLabel, hostOf, previewText, stateLabel, topicKeyOf };
export { canShareTopic, shareStatusLabel, shareStatusOf };
export { isAutoCopiedTitle };

/**
 * @param {string} filter
 * @param {Record<string, unknown>} topic
 */
export function topicMatchesFilter(filter, topic) {
    const state = topic.state || 'draft';
    if (filter === 'archived') return state === 'archived';
    if (state === 'archived') return false;
    if (filter === 'draft') return state === 'draft';
    if (filter === 'shared') return state === 'shared';
    if (filter === 'completed') return state === 'completed';
    return state === 'draft' || state === 'shared';
}

/**
 * @param {Array<Record<string, unknown>>} topics
 * @param {Array<Record<string, unknown>>} reports
 * @param {number|string} userId
 */
export function deriveMetrics(topics, reports, userId) {
    const list = Array.isArray(topics) ? topics : [];
    const reps = Array.isArray(reports) ? reports : [];
    const work = list.filter((t) => topicMatchesFilter('work', t)).length;
    const shared = list.filter((t) => topicMatchesFilter('shared', t)).length;
    const completed = list.filter((t) => topicMatchesFilter('completed', t)).length;

    const start = startOfLocalDay();
    // Semantics: completed comment-tasks today (report.completed_at), scoped to current user.
    const todayCount = reps.filter((r) => {
        if (String(r.user_id) !== String(userId)) return false;
        const ts = Date.parse(String(r.completed_at || ''));
        return Number.isFinite(ts) && ts >= start;
    }).length;

    return { work, shared, completed, todayComments: todayCount };
}

/**
 * Single-pass team / employee Seeding stats for the module sidebar.
 * Actors: topic creator, comment author, claimer, report completer (local-first model).
 *
 * @param {Array<Record<string, unknown>>} topics
 * @param {Array<Record<string, unknown>>} reports
 */
export function deriveTeamStats(topics, reports) {
    const list = Array.isArray(topics) ? topics : [];
    const reps = Array.isArray(reports) ? reports : [];
    const start = startOfLocalDay();

    /** @type {Map<string, {
     *   key: string,
     *   displayName: string,
     *   commentsCreated: number,
     *   topicsOwned: number,
     *   shares: number,
     *   inProgressClaims: number,
     *   completedReports: number,
     * }>} */
    const byActor = new Map();

    const bump = (id, name, patch) => {
        const label = String(name || '').trim();
        if ((id == null || id === '') && !label) return;
        const key = id != null && id !== '' ? `u:${id}` : `n:${label}`;
        const displayName = label || `User #${id}`;
        const prev = byActor.get(key) || {
            key,
            displayName,
            commentsCreated: 0,
            topicsOwned: 0,
            shares: 0,
            inProgressClaims: 0,
            completedReports: 0,
        };
        if (label) prev.displayName = label;
        for (const [k, v] of Object.entries(patch)) {
            prev[k] = (prev[k] || 0) + v;
        }
        byActor.set(key, prev);
    };

    let commentsCreatedToday = 0;
    let sharedToday = 0;
    let completedToday = 0;
    let newTopicsToday = 0;
    let inProgressTopics = 0;
    let pendingDrafts = 0;
    let inProgressComments = 0;

    for (const topic of list) {
        const state = topic.state || 'draft';
        const createdTs = Date.parse(String(topic.created_at || ''));
        if (Number.isFinite(createdTs) && createdTs >= start) newTopicsToday += 1;
        if (state === 'draft') pendingDrafts += 1;
        if (state === 'shared') inProgressTopics += 1;

        const sharedTs = Date.parse(String(topic.shared_at || ''));
        if (Number.isFinite(sharedTs) && sharedTs >= start) sharedToday += 1;

        bump(topic.created_by_user_id, topic.created_by_display_name, { topicsOwned: 1 });
        if (state === 'shared' || state === 'completed') {
            bump(topic.created_by_user_id, topic.created_by_display_name, { shares: 1 });
        }

        for (const comment of (topic.comments || [])) {
            const cCreated = Date.parse(String(comment.created_at || ''));
            if (Number.isFinite(cCreated) && cCreated >= start) commentsCreatedToday += 1;
            bump(
                comment.author_user_id ?? comment.created_by_user_id,
                comment.author_display_name ?? comment.created_by_display_name,
                { commentsCreated: 1 },
            );
            if (comment.state === 'in_progress') {
                inProgressComments += 1;
                bump(
                    comment.claimed_by_user_id,
                    comment.claimed_by_display_name,
                    { inProgressClaims: 1 },
                );
            }
        }
    }

    for (const report of reps) {
        const ts = Date.parse(String(report.completed_at || ''));
        if (Number.isFinite(ts) && ts >= start) completedToday += 1;
        bump(report.user_id, report.user_display_name, { completedReports: 1 });
    }

    const employees = [...byActor.values()]
        .filter((e) => e.commentsCreated > 0 || e.topicsOwned > 0 || e.shares > 0 || e.completedReports > 0 || e.inProgressClaims > 0)
        .sort((a, b) => (
            (b.commentsCreated + b.completedReports * 2) - (a.commentsCreated + a.completedReports * 2)
            || a.displayName.localeCompare(b.displayName)
        ));

    return {
        today: {
            commentsCreated: commentsCreatedToday,
            shared: sharedToday,
            completed: completedToday,
            newTopics: newTopicsToday,
        },
        workload: {
            inProgressTopics,
            pendingDrafts,
            inProgressComments,
        },
        employees,
    };
}

function startOfLocalDay() {
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    return d.getTime();
}

/**
 * @param {Record<string, unknown>} topic
 * @param {Array<Record<string, unknown>>} reports
 */
export function topicProgress(topic, reports) {
    const topicId = topicKeyOf(topic);
    const total = Array.isArray(topic.comments) ? topic.comments.length : 0;
    const done = (reports || []).filter((r) => String(r.topic_id) === topicId).length;
    return { done, total };
}

/**
 * Active work list: available + current user's in_progress (not completed).
 * @param {Array<Record<string, unknown>>} comments
 * @param {number|string} userId
 */
export function visibleWorkComments(comments, userId) {
    return (comments || []).filter((c) => {
        if (c.state === 'completed') return false;
        if (c.state === 'available') return true;
        if (c.state === 'in_progress' && String(c.claimed_by_user_id) === String(userId)) return true;
        return false;
    });
}

export function relativeTime(iso) {
    if (!iso) return '';
    const then = new Date(iso).getTime();
    if (!Number.isFinite(then)) return '';
    const diff = Math.max(0, Date.now() - then);
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'vừa xong';
    if (mins < 60) return `${mins} phút trước`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours} giờ trước`;
    return `${Math.floor(hours / 24)} ngày trước`;
}

export function topicStatusLabel(topic) {
    return stateLabel(topic.state || 'draft');
}

/**
 * Distinct user title only — never auto-copy from content.
 * @param {Record<string, unknown>} topic
 * @returns {string|null}
 */
export function topicDistinctTitle(topic) {
    const title = typeof topic?.title === 'string' ? topic.title.trim() : '';
    if (!title || isAutoCopiedTitle(title, topic?.full_text)) return null;
    return title;
}

/** Prefer topicDistinctTitle — kept for detail fallback label */
export function topicCardTitle(topic) {
    return topicDistinctTitle(topic) || previewText(topic.full_text || topic.preview, 80);
}

export function commentsCount(topic) {
    return Array.isArray(topic?.comments) ? topic.comments.length : 0;
}
