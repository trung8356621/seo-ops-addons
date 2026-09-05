/**
 * Seeding Topic V2 — localStorage recovery / local-first cache.
 */

function workspaceKey(siteId) {
    return `seeding-workspace:${siteId}`;
}

function topicKey(siteId, topicKey) {
    return `seeding-topic:${siteId}:${topicKey}`;
}

export function readWorkspaceState(siteId) {
    try {
        const raw = localStorage.getItem(workspaceKey(siteId));
        if (!raw) {
            return { selectedTopicId: null, search: '', showArchived: false };
        }
        const parsed = JSON.parse(raw);
        return {
            selectedTopicId: parsed.selected_topic_id ?? parsed.selectedTopicId ?? null,
            search: parsed.search ?? '',
            showArchived: Boolean(parsed.archived_open ?? parsed.showArchived),
        };
    } catch {
        return { selectedTopicId: null, search: '', showArchived: false };
    }
}

export function writeWorkspaceState(siteId, state) {
    try {
        localStorage.setItem(
            workspaceKey(siteId),
            JSON.stringify({
                selected_topic_id: state.selectedTopicId ?? null,
                search: state.search ?? '',
                archived_open: Boolean(state.showArchived),
                local_updated_at: new Date().toISOString(),
            }),
        );
    } catch {
        /* ignore quota */
    }
}

export function readTopicCache(siteId, key) {
    try {
        const raw = localStorage.getItem(topicKey(siteId, key));
        if (!raw) {
            return null;
        }
        return JSON.parse(raw);
    } catch {
        return null;
    }
}

export function writeTopicCache(siteId, key, topic) {
    try {
        localStorage.setItem(
            topicKey(siteId, key),
            JSON.stringify({
                ...topic,
                local_updated_at: new Date().toISOString(),
                server_synced_at: topic.server_synced_at ?? topic.updated_at ?? null,
            }),
        );
    } catch {
        /* ignore */
    }
}

export function removeTopicCache(siteId, key) {
    try {
        localStorage.removeItem(topicKey(siteId, key));
    } catch {
        /* ignore */
    }
}

export function migrateTopicCache(siteId, fromKey, toKey, topic) {
    writeTopicCache(siteId, toKey, topic);
    if (fromKey !== toKey) {
        removeTopicCache(siteId, fromKey);
    }
}

export function makeLocalDraftId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return `draft:${crypto.randomUUID()}`;
    }
    return `draft:${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

export function topicStorageKey(topic) {
    if (topic?.id) {
        return String(topic.id);
    }
    return topic?.localId ?? makeLocalDraftId();
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
