/**
 * Seeding local repository — V5 workspace document.
 *
 * Key: seeding:v5:{installationId}:{userId}:workspace
 * Scope: installation + user (no site/domain).
 *
 * Topic = immutable context after create.
 * Comment Item = work unit.
 * Report events = completion ledger (counters derived).
 * Proof binary lives in IndexedDB — never in this JSON.
 */

const SCHEMA_VERSION = 5;
const LOCAL_PERSIST_MS = 200;

/**
 * @param {{ installationId?: string, userId?: number|string }} scope
 */
export function documentKey(scope) {
    const installationId = String(scope.installationId || 'app:local');
    const userId = String(scope.userId || '0');
    return `seeding:v5:${installationId}:${userId}:workspace`;
}

export function makeLocalDraftId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return `draft:${crypto.randomUUID()}`;
    }
    return `draft:${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function makeId(prefix = 'id') {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return `${prefix}:${crypto.randomUUID()}`;
    }
    return `${prefix}:${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function previewText(fullText, max = 60) {
    const text = String(fullText ?? '').replace(/\s+/g, ' ').trim();
    if (text === '') return 'Chủ đề mới';
    if (text.length <= max) return text;
    return `${text.slice(0, max)}…`;
}

export function topicKeyOf(topic) {
    return topic?.localId || (topic?.id != null ? String(topic.id) : makeLocalDraftId());
}

export function stateLabel(state) {
    if (state === 'shared') return 'Đang chạy';
    if (state === 'completed') return 'Hoàn tất';
    if (state === 'archived') return 'Lưu trữ';
    return 'Nháp local';
}

function emptyDocument() {
    return {
        schema_version: SCHEMA_VERSION,
        updated_at: new Date().toISOString(),
        topics: [],
        reports: [],
        ui: {
            filter: 'work',
            search: '',
            detail_topic_id: null,
            active_work_item_id: null,
            history_open: false,
            composer_open: false,
        },
    };
}

/**
 * @param {unknown} link
 */
function normalizeLink(link) {
    if (typeof link === 'string') {
        const url = link.trim();
        return url ? { url, normalized_url: normalizeUrlKey(url), detected_at: new Date().toISOString() } : null;
    }
    if (!link || typeof link !== 'object') return null;
    const url = String(link.url || link.normalized_url || '').trim();
    if (!url) return null;
    return {
        url,
        normalized_url: String(link.normalized_url || normalizeUrlKey(url)),
        detected_at: link.detected_at || new Date().toISOString(),
    };
}

export function normalizeUrlKey(url) {
    try {
        const u = new URL(String(url).trim());
        u.hash = '';
        let path = u.pathname || '/';
        if (path.length > 1 && path.endsWith('/')) path = path.slice(0, -1);
        return `${u.protocol}//${u.host.toLowerCase()}${path}${u.search}`.toLowerCase();
    } catch {
        return String(url || '').trim().toLowerCase().replace(/\/$/, '');
    }
}

/**
 * @param {unknown} comment
 */
function normalizeComment(comment) {
    if (typeof comment === 'string') {
        return {
            id: makeId('cmt'),
            text: comment,
            state: 'available',
            claimed_by_user_id: null,
            claimed_at: null,
            completed_at: null,
            created_at: new Date().toISOString(),
            source: 'manual',
        };
    }
    if (!comment || typeof comment !== 'object') return null;
    const text = String(comment.text ?? comment.body ?? '').trim();
    if (!text) return null;
    let state = comment.state || 'available';
    if (!['available', 'in_progress', 'completed'].includes(state)) {
        state = 'available';
    }
    return {
        id: String(comment.id || makeId('cmt')),
        text,
        state,
        claimed_by_user_id: comment.claimed_by_user_id ?? null,
        claimed_at: comment.claimed_at ?? null,
        completed_at: comment.completed_at ?? null,
        created_at: comment.created_at || new Date().toISOString(),
        source: comment.source === 'ai' ? 'ai' : 'manual',
    };
}

function resolveTopicState(topic, comments) {
    let state = 'draft';
    if (topic.state === 'archived' || topic.is_archived || topic.archived_at) {
        state = 'archived';
    } else if (topic.state === 'completed' || topic.status === 'done' || topic.status === 'completed') {
        state = 'completed';
    } else if (
        topic.state === 'shared'
        || topic.status === 'shared'
        || topic.shared_at
    ) {
        state = 'shared';
    } else if (topic.status === 'active' && topic.shared_at) {
        // legacy V4 "active" only counts as shared when explicitly shared
        state = 'shared';
    }

    // Repair illegal state: "Đang chạy" with zero comment work items
    const count = Array.isArray(comments) ? comments.length : 0;
    if ((state === 'shared' || state === 'completed') && count === 0) {
        state = 'draft';
    }

    return state;
}

/**
 * @param {Record<string, unknown>} topic
 */
export function normalizeTopic(topic) {
    const rawComments = Array.isArray(topic.comments) && topic.comments.length > 0
        ? topic.comments
        : (Array.isArray(topic.sample_comments) ? topic.sample_comments : []);
    const comments = rawComments.map(normalizeComment).filter(Boolean);

    const state = resolveTopicState(topic, comments);

    // Prefer persisted links snapshot; fall back to legacy resources
    const rawLinks = Array.isArray(topic.links) && topic.links.length > 0
        ? topic.links
        : (Array.isArray(topic.resources) ? topic.resources : []);
    const links = [];
    const seen = new Set();
    for (const raw of rawLinks) {
        const link = normalizeLink(raw);
        if (!link || seen.has(link.normalized_url)) continue;
        seen.add(link.normalized_url);
        links.push(link);
    }

    return {
        localId: topic.localId || (topic.id != null ? String(topic.id) : makeLocalDraftId()),
        id: topic.id ?? null,
        full_text: typeof topic.full_text === 'string' ? topic.full_text : '',
        social_url: typeof topic.social_url === 'string' ? topic.social_url : '',
        links,
        comments,
        state,
        status: state,
        status_label: stateLabel(state),
        is_archived: state === 'archived',
        archived_at: state === 'archived' ? (topic.archived_at || new Date().toISOString()) : null,
        shared_at: state === 'shared' || state === 'completed'
            ? (topic.shared_at || topic.updated_at || null)
            : null,
        created_at: topic.created_at || topic.updated_at || new Date().toISOString(),
        updated_at: topic.updated_at || new Date().toISOString(),
        preview: previewText(topic.full_text || topic.preview),
    };
}

/**
 * @param {unknown} report
 */
function normalizeReport(report) {
    if (!report || typeof report !== 'object') return null;
    const topicId = report.topic_id || report.topicId;
    const commentItemId = report.comment_item_id || report.commentItemId;
    if (!topicId || !commentItemId) return null;
    return {
        id: String(report.id || makeId('rpt')),
        topic_id: String(topicId),
        comment_item_id: String(commentItemId),
        user_id: report.user_id ?? report.userId ?? null,
        comment_text: String(report.comment_text || report.commentText || ''),
        social_url: String(report.social_url || report.socialUrl || ''),
        proof_id: String(report.proof_id || report.proofId || ''),
        mime: report.mime || null,
        size: report.size || null,
        completed_at: report.completed_at || report.completedAt || new Date().toISOString(),
    };
}

export function dedupeTopics(topics) {
    /** @type {Map<string, Record<string, unknown>>} */
    const map = new Map();
    for (const topic of topics) {
        const key = topicKeyOf(topic);
        const prev = map.get(key);
        if (!prev) {
            map.set(key, topic);
            continue;
        }
        const prevTs = Date.parse(String(prev.updated_at || 0)) || 0;
        const nextTs = Date.parse(String(topic.updated_at || 0)) || 0;
        map.set(key, nextTs >= prevTs ? topic : prev);
    }
    return [...map.values()].sort((a, b) =>
        String(b.updated_at || '').localeCompare(String(a.updated_at || '')),
    );
}

/**
 * @param {unknown} raw
 */
export function migrateVersion(raw) {
    if (!raw || typeof raw !== 'object') return emptyDocument();
    const doc = /** @type {Record<string, unknown>} */ (raw);
    if (Number(doc.schema_version ?? 0) <= 0) return emptyDocument();

    const topics = Array.isArray(doc.topics)
        ? doc.topics.filter((t) => t && typeof t === 'object').map((t) => normalizeTopic(/** @type {Record<string, unknown>} */ (t)))
        : [];

    const reports = Array.isArray(doc.reports)
        ? doc.reports.map(normalizeReport).filter(Boolean)
        : [];

    const uiRaw = /** @type {Record<string, unknown>} */ (
        (doc.ui && typeof doc.ui === 'object' ? doc.ui : null)
        || (doc.workspace && typeof doc.workspace === 'object' ? doc.workspace : {})
    );

    let filter = typeof uiRaw.filter === 'string' ? uiRaw.filter : 'work';
    if (filter === 'archive') filter = 'archived';
    if (filter === 'active') filter = 'shared';
    if (filter === 'new') filter = 'draft';

    return {
        schema_version: SCHEMA_VERSION,
        updated_at: typeof doc.updated_at === 'string' ? doc.updated_at : new Date().toISOString(),
        topics,
        reports,
        ui: {
            filter,
            search: String(uiRaw.search ?? ''),
            detail_topic_id: uiRaw.detail_topic_id ?? uiRaw.detailTopicId ?? null,
            active_work_item_id: uiRaw.active_work_item_id ?? uiRaw.activeWorkItemId ?? null,
            history_open: Boolean(uiRaw.history_open ?? uiRaw.historyOpen),
            composer_open: false,
        },
    };
}

function importLegacyKeys(scope, prefixes) {
    /** @type {Array<Record<string, unknown>>} */
    const importedTopics = [];
    /** @type {Array<Record<string, unknown>>} */
    const importedReports = [];
    const installationId = String(scope.installationId || 'app:local');
    const userId = String(scope.userId || '0');

    try {
        for (let i = 0; i < localStorage.length; i += 1) {
            const key = localStorage.key(i);
            if (!key) continue;
            const matched = prefixes.some((p) => key.startsWith(p.replace('{i}', installationId).replace('{u}', userId)));
            if (!matched) continue;
            const raw = localStorage.getItem(key);
            if (!raw) continue;
            try {
                const migrated = migrateVersion(JSON.parse(raw));
                importedTopics.push(...migrated.topics);
                importedReports.push(...migrated.reports);
            } catch {
                /* ignore */
            }
        }
    } catch {
        /* ignore */
    }

    return {
        topics: dedupeTopics(importedTopics),
        reports: dedupeReports(importedReports),
    };
}

function dedupeReports(reports) {
    /** @type {Map<string, Record<string, unknown>>} */
    const map = new Map();
    for (const r of reports) {
        const key = String(r.comment_item_id);
        const prev = map.get(key);
        if (!prev) {
            map.set(key, r);
            continue;
        }
        const prevTs = Date.parse(String(prev.completed_at || 0)) || 0;
        const nextTs = Date.parse(String(r.completed_at || 0)) || 0;
        map.set(key, nextTs >= prevTs ? r : prev);
    }
    return [...map.values()];
}

/**
 * Best-effort import V3/V4 into V5. Never deletes old keys.
 * @param {{ installationId?: string, userId?: number|string }} scope
 */
export function importLegacyIfNeeded(scope) {
    return importLegacyKeys(scope, [
        'seeding:v4:{i}:{u}:workspace',
        'seeding:v3:{i}:{u}:',
    ]);
}

/**
 * @param {{ installationId?: string, userId?: number|string }} scope
 */
export function readDocument(scope) {
    try {
        const raw = localStorage.getItem(documentKey(scope));
        if (raw) return migrateVersion(JSON.parse(raw));
    } catch {
        /* fall through */
    }

    const imported = importLegacyIfNeeded(scope);
    if (imported.topics.length === 0 && imported.reports.length === 0) {
        return emptyDocument();
    }

    return writeDocument(scope, {
        ...emptyDocument(),
        topics: imported.topics,
        reports: imported.reports,
    });
}

/**
 * @param {{ installationId?: string, userId?: number|string }} scope
 * @param {ReturnType<typeof emptyDocument>} doc
 */
export function writeDocument(scope, doc) {
    try {
        const payload = {
            schema_version: SCHEMA_VERSION,
            updated_at: new Date().toISOString(),
            topics: Array.isArray(doc.topics) ? doc.topics.map((t) => normalizeTopic(t)) : [],
            reports: Array.isArray(doc.reports) ? doc.reports.map(normalizeReport).filter(Boolean) : [],
            ui: {
                filter: doc.ui?.filter || 'work',
                search: doc.ui?.search || '',
                detail_topic_id: doc.ui?.detail_topic_id ?? null,
                active_work_item_id: doc.ui?.active_work_item_id ?? null,
                history_open: Boolean(doc.ui?.history_open),
                composer_open: false,
            },
        };
        localStorage.setItem(documentKey(scope), JSON.stringify(payload));
        return payload;
    } catch {
        return doc;
    }
}

export function createDebouncedWriter(ms = LOCAL_PERSIST_MS) {
    let timer = null;
    return {
        schedule(fn) {
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => {
                timer = null;
                fn();
            }, ms);
        },
        flush(fn) {
            if (timer) clearTimeout(timer);
            timer = null;
            fn();
        },
        cancel() {
            if (timer) clearTimeout(timer);
            timer = null;
        },
    };
}

/** Topic has work history → delete blocked */
export function topicHasWorkHistory(topic, reports) {
    const topicId = topicKeyOf(topic);
    if ((reports || []).some((r) => String(r.topic_id) === topicId)) return true;
    const comments = Array.isArray(topic.comments) ? topic.comments : [];
    return comments.some((c) => c.state === 'in_progress' || c.state === 'completed' || c.claimed_by_user_id || c.completed_at);
}

export function findReportForComment(reports, commentItemId) {
    return (reports || []).find((r) => String(r.comment_item_id) === String(commentItemId)) || null;
}

export {
    SCHEMA_VERSION,
    LOCAL_PERSIST_MS,
    emptyDocument,
};
