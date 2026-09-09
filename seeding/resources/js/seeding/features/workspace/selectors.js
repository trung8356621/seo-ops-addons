import { previewText, stateLabel, topicKeyOf } from '../../services/storage';
import { detectPlatformLabel, hostOf } from '../../services/linkExtract';
import { startOfLocalDay, linkPoolCapacity, usedTodayByLinkId } from '../../services/linkPool';
import { isAutoCopiedTitle } from './content';
import {
    canSeedTopic,
    canShareTopic,
    seedStatusLabel,
    seedStatusOf,
    shareStatusLabel,
    shareStatusOf,
} from './auth';

export { detectPlatformLabel, hostOf, previewText, stateLabel, topicKeyOf };
export { canSeedTopic, canShareTopic, seedStatusLabel, seedStatusOf, shareStatusLabel, shareStatusOf };
export { isAutoCopiedTitle };

/** Recent window for "Đã dùng gần đây" (local days). */
export const RECENT_SEEDED_DAYS = 7;

/**
 * @param {string} filter
 * @param {Record<string, unknown>} topic
 * @param {{
 *   batches?: Array<Record<string, unknown>>,
 *   userId?: number|string,
 *   nowMs?: number,
 * }} [ctx]
 */
export function topicMatchesFilter(filter, topic, ctx = {}) {
    const state = topic.state || 'draft';
    if (filter === 'archived') return state === 'archived';
    if (state === 'archived') return false;
    if (filter === 'draft' || filter === 'new') {
        return !lastSeededAtForUser(topic, ctx.batches || [], ctx.userId, ctx.nowMs);
    }
    if (filter === 'recent') {
        return Boolean(lastSeededAtForUser(topic, ctx.batches || [], ctx.userId, ctx.nowMs));
    }
    // all
    return true;
}

/**
 * @param {Record<string, unknown>} topic
 * @param {Array<Record<string, unknown>>} batches
 * @param {number|string} [userId]
 * @param {number} [nowMs]
 * @returns {string|null} ISO timestamp
 */
export function lastSeededAtForUser(topic, batches, userId, nowMs = Date.now()) {
    const topicId = topicKeyOf(topic);
    const windowStart = nowMs - RECENT_SEEDED_DAYS * 24 * 60 * 60 * 1000;
    let latest = null;
    let latestTs = 0;
    for (const b of batches || []) {
        if (String(b.topic_id) !== topicId) continue;
        if (userId != null && userId !== '' && String(b.user_id) !== String(userId)) continue;
        const ts = Date.parse(String(b.created_at || ''));
        if (!Number.isFinite(ts) || ts < windowStart) continue;
        if (ts >= latestTs) {
            latestTs = ts;
            latest = b.created_at;
        }
    }
    return latest;
}

/**
 * Sort: trending flag first (real data only) → recent seed / updated_at.
 * @param {Array<Record<string, unknown>>} topics
 * @param {Array<Record<string, unknown>>} batches
 * @param {number|string} userId
 */
export function sortTopicsForFeed(topics, batches, userId) {
    return [...topics].sort((a, b) => {
        const ta = isTopicTrending(a) ? 1 : 0;
        const tb = isTopicTrending(b) ? 1 : 0;
        if (tb !== ta) return tb - ta;
        const sa = Date.parse(String(lastSeededAtForUser(a, batches, userId) || '')) || 0;
        const sb = Date.parse(String(lastSeededAtForUser(b, batches, userId) || '')) || 0;
        if (sb !== sa) return sb - sa;
        return String(b.updated_at || '').localeCompare(String(a.updated_at || ''));
    });
}

/**
 * Only true when real trending fields exist — never fake.
 * @param {Record<string, unknown>} topic
 */
export function isTopicTrending(topic) {
    if (topic?.is_trending === true || topic?.trending === true) return true;
    if (typeof topic?.trending === 'string' && topic.trending.trim() !== '') return true;
    return false;
}

/**
 * Personal metrics from local document (current user only).
 * Nội dung đã tạo = COUNT(outputs), never SUM(batch.quantity).
 *
 * @param {{
 *   topics: Array<Record<string, unknown>>,
 *   batches: Array<Record<string, unknown>>,
 *   outputs: Array<Record<string, unknown>>,
 *   seedLinks: Array<Record<string, unknown>>,
 *   userId: number|string,
 * }} args
 */
export function derivePersonalSeedingStats({ topics, batches, outputs, seedLinks, userId }) {
    const dayStart = startOfLocalDay();
    const batchesToday = (batches || []).filter((b) => {
        if (String(b.user_id) !== String(userId)) return false;
        const ts = Date.parse(String(b.created_at || ''));
        return Number.isFinite(ts) && ts >= dayStart;
    });
    const outputsToday = (outputs || []).filter((o) => {
        if (String(o.user_id) !== String(userId)) return false;
        const ts = Date.parse(String(o.created_at || ''));
        return Number.isFinite(ts) && ts >= dayStart;
    });

    const topicIds = new Set(batchesToday.map((b) => String(b.topic_id)));
    const usedMap = usedTodayByLinkId(outputs || []);
    const capacity = linkPoolCapacity(seedLinks || [], usedMap);

    return {
        today: {
            genBatches: batchesToday.length,
            contents: outputsToday.length,
            topicsUsed: topicIds.size,
            linksActive: capacity.active,
            linksAtLimit: capacity.atLimit,
        },
        capacity,
        // Totals (all time in local doc) for metric cards
        totals: {
            topics: (topics || []).filter((t) => (t.state || 'draft') !== 'archived').length,
            batches: (batches || []).filter((b) => String(b.user_id) === String(userId)).length,
            outputs: (outputs || []).filter((o) => String(o.user_id) === String(userId)).length,
        },
    };
}

/**
 * @deprecated Use derivePersonalSeedingStats — do not fake team from local-only doc.
 */
export function deriveTeamStats(topics, reports) {
    return {
        today: { commentsCreated: 0, shared: 0, completed: 0, newTopics: 0 },
        workload: { inProgressTopics: 0, pendingDrafts: 0, inProgressComments: 0 },
        employees: [],
        _deprecated: true,
        _note: 'local-only SoT — use derivePersonalSeedingStats',
    };
}

/**
 * Metric cards for Flexible Seeding personal view.
 */
export function deriveMetrics(topics, batches, outputs, userId) {
    const stats = derivePersonalSeedingStats({
        topics,
        batches,
        outputs,
        seedLinks: [],
        userId,
    });
    return {
        topics: stats.totals.topics,
        genToday: stats.today.genBatches,
        contentsToday: stats.today.contents,
        topicsUsedToday: stats.today.topicsUsed,
    };
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
