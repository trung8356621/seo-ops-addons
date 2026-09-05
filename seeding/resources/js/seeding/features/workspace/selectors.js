import { previewText, stateLabel, topicKeyOf } from '../../services/storage';
import { detectPlatformLabel, hostOf } from '../../services/linkExtract';

export { detectPlatformLabel, hostOf, previewText, stateLabel, topicKeyOf };

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
    const todayCount = reps.filter((r) => {
        if (String(r.user_id) !== String(userId)) return false;
        const ts = Date.parse(String(r.completed_at || ''));
        return Number.isFinite(ts) && ts >= start;
    }).length;

    return { work, shared, completed, todayComments: todayCount };
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

export function topicCardTitle(topic) {
    return previewText(topic.full_text || topic.preview, 80);
}
