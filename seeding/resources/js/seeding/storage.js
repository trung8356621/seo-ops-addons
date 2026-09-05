/**
 * Seeding local repository — versioned JSON document in localStorage.
 *
 * Key: seeding:v3:{installationId}:{userId}:{siteId}:doc
 * Business SoT for this phase (no server topic CRUD).
 */

const SCHEMA_VERSION = 1;
const LOCAL_PERSIST_MS = 200;

/**
 * @param {{ installationId?: string, userId?: number|string, siteId?: number|string }} scope
 */
export function documentKey(scope) {
    const installationId = String(scope.installationId || 'app:local');
    const userId = String(scope.userId || '0');
    const siteId = String(scope.siteId || '0');
    return `seeding:v3:${installationId}:${userId}:${siteId}:doc`;
}

function emptyDocument() {
    return {
        schema_version: SCHEMA_VERSION,
        updated_at: new Date().toISOString(),
        topics: [],
        workspace: {
            selectedTopicId: null,
            search: '',
            showArchived: false,
        },
    };
}

/**
 * @param {unknown} raw
 */
export function migrateVersion(raw) {
    if (!raw || typeof raw !== 'object') {
        return emptyDocument();
    }
    const doc = /** @type {Record<string, unknown>} */ (raw);
    const version = Number(doc.schema_version ?? 0);
    if (version <= 0) {
        return emptyDocument();
    }
    // Future migrations branch on version here.
    return {
        schema_version: SCHEMA_VERSION,
        updated_at: typeof doc.updated_at === 'string' ? doc.updated_at : new Date().toISOString(),
        topics: Array.isArray(doc.topics) ? doc.topics : [],
        workspace: {
            selectedTopicId: doc.workspace?.selectedTopicId ?? doc.workspace?.selected_topic_id ?? null,
            search: doc.workspace?.search ?? '',
            showArchived: Boolean(doc.workspace?.showArchived ?? doc.workspace?.archived_open),
        },
    };
}

/**
 * @param {{ installationId?: string, userId?: number|string, siteId?: number|string }} scope
 */
export function readDocument(scope) {
    try {
        const raw = localStorage.getItem(documentKey(scope));
        if (!raw) {
            return emptyDocument();
        }
        return migrateVersion(JSON.parse(raw));
    } catch {
        return emptyDocument();
    }
}

/**
 * @param {{ installationId?: string, userId?: number|string, siteId?: number|string }} scope
 * @param {ReturnType<typeof emptyDocument>} doc
 */
export function writeDocument(scope, doc) {
    try {
        const payload = {
            ...doc,
            schema_version: SCHEMA_VERSION,
            updated_at: new Date().toISOString(),
        };
        localStorage.setItem(documentKey(scope), JSON.stringify(payload));
        return payload;
    } catch {
        return doc;
    }
}

/**
 * @param {{ installationId?: string, userId?: number|string, siteId?: number|string }} scope
 */
export function resetDocument(scope) {
    const doc = emptyDocument();
    writeDocument(scope, doc);
    return doc;
}

export function makeLocalDraftId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return `draft:${crypto.randomUUID()}`;
    }
    return `draft:${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function previewText(fullText, max = 60) {
    const text = String(fullText ?? '').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return 'Chủ đề mới';
    }
    if (text.length <= max) {
        return text;
    }
    return `${text.slice(0, max)}…`;
}

export function topicKeyOf(topic) {
    return topic?.localId || (topic?.id != null ? String(topic.id) : makeLocalDraftId());
}

/**
 * Debounced writer shared across a workspace instance.
 */
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

// --- legacy V2 key helpers (read-only migrate once) ---

function legacyWorkspaceKey(siteId) {
    return `seeding-workspace:${siteId}`;
}

function legacyTopicKey(siteId, key) {
    return `seeding-topic:${siteId}:${key}`;
}

/**
 * Best-effort one-shot import from V2 per-topic cache keys when V3 doc is empty.
 * @param {{ installationId?: string, userId?: number|string, siteId?: number|string }} scope
 */
export function importLegacyV2IfEmpty(scope) {
    const current = readDocument(scope);
    if (current.topics.length > 0) {
        return current;
    }
    const siteId = String(scope.siteId || '0');
    if (siteId === '0') {
        return current;
    }

    let workspace = current.workspace;
    try {
        const wsRaw = localStorage.getItem(legacyWorkspaceKey(siteId));
        if (wsRaw) {
            const parsed = JSON.parse(wsRaw);
            workspace = {
                selectedTopicId: parsed.selected_topic_id ?? parsed.selectedTopicId ?? null,
                search: parsed.search ?? '',
                showArchived: Boolean(parsed.archived_open ?? parsed.showArchived),
            };
        }
    } catch {
        /* ignore */
    }

    const topics = [];
    try {
        for (let i = 0; i < localStorage.length; i += 1) {
            const key = localStorage.key(i);
            if (!key || !key.startsWith(`seeding-topic:${siteId}:`)) {
                continue;
            }
            const raw = localStorage.getItem(key);
            if (!raw) continue;
            const topic = JSON.parse(raw);
            if (topic && typeof topic === 'object') {
                const localId = topic.localId || (topic.id != null ? String(topic.id) : key.split(':').pop());
                topics.push({
                    ...topic,
                    localId,
                    id: topic.id ?? null,
                    preview: previewText(topic.full_text || topic.preview),
                });
            }
        }
    } catch {
        /* ignore */
    }

    if (topics.length === 0 && !workspace.selectedTopicId && !workspace.search) {
        return current;
    }

    return writeDocument(scope, {
        schema_version: SCHEMA_VERSION,
        updated_at: new Date().toISOString(),
        topics,
        workspace,
    });
}

export { SCHEMA_VERSION, LOCAL_PERSIST_MS, legacyTopicKey, legacyWorkspaceKey };
