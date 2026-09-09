/**
 * Seeding local repository — V8 hybrid (local drafts + DB commit points).
 *
 * Key: seeding:v5:{installationId}:{userId}:workspace (stable key; schema_version inside).
 * Scope: installation + user (no site/domain).
 *
 * Topic drafts = local. Shared feed = API. Generated comments = local.
 * seed_links = personal Link Pool. link_usage_today = report-derived cache.
 * Proof binary for local preview may use Object URL; report upload goes to API.
 */

const SCHEMA_VERSION = 8;
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
        seed_links: [],
        seed_batches: [],
        seed_outputs: [],
        link_usage_today: {},
        link_previews: {},
        ui: {
            filter: 'all',
            search: '',
            detail_topic_id: null,
            active_work_item_id: null,
            history_open: false,
            composer_open: false,
            sidebar_collapsed: false,
            link_pool_open: false,
            share_topic_id: null,
        },
    };
}

/**
 * @param {unknown} link
 */
export function normalizeLink(link) {
    if (typeof link === 'string') {
        const url = link.trim();
        return url ? {
            url,
            normalized_url: normalizeUrlKey(url),
            detected_at: new Date().toISOString(),
            preview_url: null,
            preview_title: null,
            preview_description: null,
            preview_image_url: null,
            preview_domain: null,
            preview_fetched_at: null,
            preview_status: null,
        } : null;
    }
    if (!link || typeof link !== 'object') return null;
    const url = String(link.url || link.normalized_url || '').trim();
    if (!url) return null;
    return {
        url,
        normalized_url: String(link.normalized_url || normalizeUrlKey(url)),
        detected_at: link.detected_at || new Date().toISOString(),
        preview_url: link.preview_url || null,
        preview_title: link.preview_title || null,
        preview_description: link.preview_description || null,
        preview_image_url: link.preview_image_url || null,
        preview_domain: link.preview_domain || null,
        preview_fetched_at: link.preview_fetched_at || null,
        preview_status: link.preview_status || null,
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
            links: normalizeLinksFromText(comment),
        };
    }
    if (!comment || typeof comment !== 'object') return null;
    const text = String(comment.text ?? comment.body ?? '').trim();
    if (!text) return null;
    let state = comment.state || 'available';
    if (!['available', 'in_progress', 'completed'].includes(state)) {
        state = 'available';
    }
    const rawLinks = Array.isArray(comment.links) ? comment.links : [];
    let links = [];
    const seen = new Set();
    for (const raw of rawLinks) {
        const link = normalizeLink(raw);
        if (!link || seen.has(link.normalized_url)) continue;
        seen.add(link.normalized_url);
        links.push(link);
    }
    // Re-sync from text so removed URLs drop stale preview associations.
    links = mergeLinksWithText(text, links);

    return {
        id: String(comment.id || makeId('cmt')),
        text,
        state,
        claimed_by_user_id: comment.claimed_by_user_id ?? null,
        claimed_at: comment.claimed_at ?? null,
        completed_at: comment.completed_at ?? null,
        created_at: comment.created_at || new Date().toISOString(),
        source: comment.source === 'ai' ? 'ai' : 'manual',
        author_user_id: comment.author_user_id ?? comment.created_by_user_id ?? null,
        author_display_name: comment.author_display_name || '',
        links,
    };
}

function normalizeLinksFromText(text) {
    const re = /https?:\/\/[^\s<>"'）)\]]+/gi;
    const out = [];
    const seen = new Set();
    let m;
    const src = String(text || '');
    while ((m = re.exec(src)) !== null) {
        const link = normalizeLink(m[0].replace(/[),.;!?]+$/g, ''));
        if (!link || seen.has(link.normalized_url)) continue;
        seen.add(link.normalized_url);
        out.push(link);
    }
    return out;
}

/**
 * Keep only URLs still present in text; preserve preview meta for survivors.
 * @param {string} text
 * @param {Array<Record<string, unknown>>} previous
 */
function mergeLinksWithText(text, previous) {
    const fresh = normalizeLinksFromText(text);
    /** @type {Map<string, Record<string, unknown>>} */
    const prev = new Map();
    for (const link of previous || []) {
        prev.set(String(link.normalized_url), link);
    }
    return fresh.map((stub) => {
        const hit = prev.get(stub.normalized_url);
        return hit ? { ...stub, ...pickPreviewFields(hit) } : stub;
    });
}

function pickPreviewFields(link) {
    return {
        preview_url: link.preview_url ?? null,
        preview_title: link.preview_title ?? null,
        preview_description: link.preview_description ?? null,
        preview_image_url: link.preview_image_url ?? null,
        preview_domain: link.preview_domain ?? null,
        preview_fetched_at: link.preview_fetched_at ?? null,
        preview_status: link.preview_status ?? null,
    };
}

/**
 * @param {unknown} raw
 * @returns {Record<string, Record<string, unknown>>}
 */
export function normalizeLinkPreviewCache(raw) {
    /** @type {Record<string, Record<string, unknown>>} */
    const out = {};
    if (!raw || typeof raw !== 'object') return out;
    for (const [key, value] of Object.entries(raw)) {
        const link = normalizeLink(value && typeof value === 'object' ? { ...value, normalized_url: value.normalized_url || key } : key);
        if (!link || !link.preview_fetched_at) continue;
        out[link.normalized_url] = link;
    }
    return out;
}

function resolveTopicState(topic) {
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
        state = 'shared';
    }
    // V7: do not repair shared/completed → draft based on comment count.
    // Flexible Seeding does not gate topics on sample comments.
    return state;
}

/**
 * @param {unknown} raw
 */
function normalizeSeedBatch(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const topicId = raw.topic_id ?? raw.topicId;
    if (!topicId) return null;
    const requested = Math.max(1, Number(raw.requested_quantity ?? raw.quantity) || 1);
    const generated = Math.max(0, Number(raw.generated_quantity ?? raw.generatedQuantity) || 0);
    return {
        id: String(raw.id || makeId('sbatch')),
        topic_id: String(topicId),
        user_id: raw.user_id ?? raw.userId ?? null,
        requested_quantity: requested,
        generated_quantity: generated,
        created_at: raw.created_at || raw.createdAt || new Date().toISOString(),
    };
}

/**
 * @param {unknown} raw
 */
function normalizeSeedOutput(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const topicId = raw.topic_id ?? raw.topicId;
    const content = String(raw.content ?? '').trim();
    if (!topicId || !content) return null;
    const now = new Date().toISOString();
    return {
        id: String(raw.id || makeId('sout')),
        batch_id: raw.batch_id != null ? String(raw.batch_id) : null,
        topic_id: String(topicId),
        user_id: raw.user_id ?? raw.userId ?? null,
        content,
        seed_link_id: raw.seed_link_id != null && raw.seed_link_id !== ''
            ? String(raw.seed_link_id)
            : null,
        url: raw.url ? String(raw.url) : null,
        created_at: raw.created_at || raw.createdAt || now,
        updated_at: raw.updated_at || raw.updatedAt || raw.created_at || now,
    };
}

/**
 * @param {unknown} raw
 */
function normalizeSeedLinkRecord(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const url = String(raw.url || '').trim();
    if (!url) return null;
    const dailyLimit = Math.max(1, Number(raw.daily_limit) || 5);
    const now = new Date().toISOString();
    return {
        id: String(raw.id || makeId('slink')),
        url,
        normalized_url: String(raw.normalized_url || normalizeUrlKey(url)),
        daily_limit: dailyLimit,
        is_active: raw.is_active !== false,
        label: typeof raw.label === 'string' ? raw.label.trim() : '',
        created_at: raw.created_at || now,
        updated_at: raw.updated_at || now,
    };
}

/**
 * @param {Record<string, unknown>} topic
 */
export function normalizeTopic(topic) {
    const rawComments = Array.isArray(topic.comments) && topic.comments.length > 0
        ? topic.comments
        : (Array.isArray(topic.sample_comments) ? topic.sample_comments : []);
    const comments = rawComments.map(normalizeComment).filter(Boolean);

    const state = resolveTopicState(topic);

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
        title: typeof topic.title === 'string' ? topic.title.trim() : '',
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
        created_by_user_id: topic.created_by_user_id ?? topic.created_by ?? null,
        created_by_display_name: topic.created_by_display_name || '',
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

    const seedLinks = Array.isArray(doc.seed_links)
        ? doc.seed_links.map(normalizeSeedLinkRecord).filter(Boolean)
        : [];
    const seedBatches = Array.isArray(doc.seed_batches)
        ? doc.seed_batches.map(normalizeSeedBatch).filter(Boolean)
        : [];
    const seedOutputs = Array.isArray(doc.seed_outputs)
        ? doc.seed_outputs.map(normalizeSeedOutput).filter(Boolean)
        : [];

    let linkPreviews = normalizeLinkPreviewCache(doc.link_previews);
    // Backfill shared cache from embedded topic/comment link metas (V5 → V6).
    for (const topic of topics) {
        for (const link of topic.links || []) {
            if (!link.preview_fetched_at) continue;
            const key = String(link.normalized_url);
            if (!linkPreviews[key]) linkPreviews[key] = normalizeLink(link);
        }
        for (const comment of topic.comments || []) {
            for (const link of comment.links || []) {
                if (!link.preview_fetched_at) continue;
                const key = String(link.normalized_url);
                if (!linkPreviews[key]) linkPreviews[key] = normalizeLink(link);
            }
        }
    }

    const uiRaw = /** @type {Record<string, unknown>} */ (
        (doc.ui && typeof doc.ui === 'object' ? doc.ui : null)
        || (doc.workspace && typeof doc.workspace === 'object' ? doc.workspace : {})
    );

    let filter = typeof uiRaw.filter === 'string' ? uiRaw.filter : 'all';
    if (filter === 'archive') filter = 'archived';
    if (filter === 'active' || filter === 'shared' || filter === 'work') filter = 'all';
    if (filter === 'new') filter = 'draft';
    if (filter === 'completed') filter = 'all';

    return {
        schema_version: SCHEMA_VERSION,
        updated_at: typeof doc.updated_at === 'string' ? doc.updated_at : new Date().toISOString(),
        topics,
        reports,
        seed_links: seedLinks,
        seed_batches: seedBatches,
        seed_outputs: seedOutputs,
        link_usage_today: doc.link_usage_today && typeof doc.link_usage_today === 'object'
            ? doc.link_usage_today
            : {},
        link_previews: linkPreviews,
        ui: {
            filter,
            search: String(uiRaw.search ?? ''),
            detail_topic_id: uiRaw.detail_topic_id ?? uiRaw.detailTopicId ?? null,
            active_work_item_id: null,
            history_open: Boolean(uiRaw.history_open ?? uiRaw.historyOpen),
            composer_open: false,
            sidebar_collapsed: Boolean(uiRaw.sidebar_collapsed ?? uiRaw.sidebarCollapsed),
            link_pool_open: Boolean(uiRaw.link_pool_open),
            share_topic_id: uiRaw.share_topic_id ?? null,
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
            seed_links: Array.isArray(doc.seed_links)
                ? doc.seed_links.map(normalizeSeedLinkRecord).filter(Boolean)
                : [],
            seed_batches: Array.isArray(doc.seed_batches)
                ? doc.seed_batches.map(normalizeSeedBatch).filter(Boolean)
                : [],
            seed_outputs: Array.isArray(doc.seed_outputs)
                ? doc.seed_outputs.map(normalizeSeedOutput).filter(Boolean)
                : [],
            link_usage_today: doc.link_usage_today && typeof doc.link_usage_today === 'object'
                ? doc.link_usage_today
                : {},
            link_previews: normalizeLinkPreviewCache(doc.link_previews),
            ui: {
                filter: doc.ui?.filter || 'all',
                search: doc.ui?.search || '',
                detail_topic_id: doc.ui?.detail_topic_id ?? null,
                active_work_item_id: null,
                history_open: Boolean(doc.ui?.history_open),
                composer_open: false,
                sidebar_collapsed: Boolean(doc.ui?.sidebar_collapsed),
                link_pool_open: Boolean(doc.ui?.link_pool_open),
                share_topic_id: doc.ui?.share_topic_id ?? null,
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

/**
 * Topic has work history → delete blocked.
 * V7: also treat seed batches/outputs as history.
 * @param {Record<string, unknown>} topic
 * @param {Array<Record<string, unknown>>} reports
 * @param {{ seed_batches?: Array<Record<string, unknown>>, seed_outputs?: Array<Record<string, unknown>> }} [extra]
 */
export function topicHasWorkHistory(topic, reports, extra = {}) {
    const topicId = topicKeyOf(topic);
    if ((reports || []).some((r) => String(r.topic_id) === topicId)) return true;
    if ((extra.seed_batches || []).some((b) => String(b.topic_id) === topicId)) return true;
    if ((extra.seed_outputs || []).some((o) => String(o.topic_id) === topicId)) return true;
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
