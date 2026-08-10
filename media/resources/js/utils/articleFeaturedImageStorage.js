import {
    clearFeaturedViaApi,
    featuredFromSnapshot,
    getMediaSnapshot,
    normalizeFeaturedMediaItem,
    setFeaturedViaApi,
} from '@content-addon/utils/articleEditorMediaSnapshot.js';

/**
 * Featured helpers — Phase 2A: in-memory snapshot only (no localStorage SoT).
 */

export function normalizeFeaturedImage(item) {
    return normalizeFeaturedMediaItem(item);
}

export function loadFeaturedImage(articleId) {
    return featuredFromSnapshot(articleId);
}

/**
 * Optimistic UI only — canonical persist via setFeaturedViaApi / Livewire bridge.
 * Does NOT write localStorage.
 */
export function saveFeaturedImage(articleId, item) {
    const id = Number(articleId ?? 0);
    const normalized = normalizeFeaturedImage(item);
    if (!Number.isFinite(id) || id <= 0 || !normalized) {
        return null;
    }

    // Fire-and-forget API; callers that need ACK should await setFeaturedViaApi.
    void setFeaturedViaApi(id, normalized).catch((error) => {
        console.warn('Featured persist failed', error);
    });

    return normalized;
}

export function persistFeaturedImageDraftToServer(articleId, wire) {
    const item = loadFeaturedImage(articleId);
    if (!item) {
        return Promise.resolve(null);
    }

    return setFeaturedViaApi(articleId, item).catch(() => {
        if (wire?.persistFeaturedImageFromClient) {
            return wire.persistFeaturedImageFromClient(item);
        }

        return item;
    });
}

export function clearFeaturedImageStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    void clearFeaturedViaApi(id).catch((error) => {
        console.warn('Featured clear failed', error);
    });
}

export function featuredPresent(articleId) {
    return Boolean(getMediaSnapshot(articleId)?.featured?.url);
}
