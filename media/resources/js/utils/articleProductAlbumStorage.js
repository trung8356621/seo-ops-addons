import {
    applyMediaSnapshot,
    clearMediaSnapshot,
    discardLegacyMediaLocalStorage,
    galleryFromSnapshot,
    getMediaSnapshot,
    replaceGalleryViaApi,
    reorderGalleryViaApi,
} from '@content-addon/utils/articleEditorMediaSnapshot.js';

/**
 * Product album helpers — Phase 2A: snapshot SoT, no localStorage shadow.
 */

export function normalizeProductAlbumItem(item) {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const url = String(item.url ?? item.src ?? '').trim();
    if (url === '') {
        return null;
    }

    const wpAttachmentId = Number(item.wp_attachment_id ?? item.wpAttachmentId ?? 0);
    const seoMediaId = Number(item.seo_media_id ?? item.seoMediaId ?? item.id ?? 0);
    const id = wpAttachmentId > 0 ? wpAttachmentId : seoMediaId;

    return {
        id: id > 0 ? id : 0,
        url,
        stable_id: String(item.stable_id ?? item.id_key ?? ''),
    };
}

export function normalizeProductAlbumList(items) {
    if (!Array.isArray(items)) {
        return [];
    }

    const seen = new Set();
    const out = [];

    items.forEach((row) => {
        const normalized = normalizeProductAlbumItem(row);
        if (!normalized) {
            return;
        }

        const key = `${normalized.id}:${normalized.url}`;
        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        out.push(normalized);
    });

    return out;
}

export function loadProductAlbum(articleId) {
    return galleryFromSnapshot(articleId);
}

/**
 * @deprecated Bootstrap merge no longer reads localStorage.
 */
export function mergeProductAlbumBootstrap(serverItems, articleId) {
    return normalizeProductAlbumList(serverItems);
}

export function saveProductAlbum(articleId, items, { dispatch = true } = {}) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const normalized = normalizeProductAlbumList(items);
    void replaceGalleryViaApi(id, normalized).catch((error) => {
        console.warn('Gallery persist failed', error);
    });

    if (dispatch) {
        dispatchProductGalleryUpdated(id, normalized);
    }

    return normalized;
}

export function dispatchProductGalleryUpdated(articleId, gallery) {
    const items = normalizeProductAlbumList(gallery);

    window.dispatchEvent(
        new CustomEvent('seo-product-gallery-updated', {
            detail: {
                gallery: items,
                article_id: Number(articleId ?? 0),
                articleId: Number(articleId ?? 0),
                pending: true,
            },
        }),
    );
}

export function appendProductAlbumItems(articleId, incomingItems) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const current = loadProductAlbum(id);
    const merged = normalizeProductAlbumList([...current, ...incomingItems]);

    return saveProductAlbum(id, merged);
}

export function removeProductAlbumItem(articleId, url) {
    const id = Number(articleId ?? 0);
    const target = String(url ?? '').trim();
    if (!Number.isFinite(id) || id <= 0 || target === '') {
        return loadProductAlbum(id);
    }

    const next = loadProductAlbum(id).filter((row) => String(row?.url ?? '').trim() !== target);

    return saveProductAlbum(id, next);
}

export function reorderProductAlbum(articleId, orderedUrlsOrIds) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const current = loadProductAlbum(id);
    const snap = getMediaSnapshot(id);
    const stableIds = Array.isArray(snap.gallery?.items)
        ? snap.gallery.items.map((row) => String(row.id || ''))
        : [];

    // Prefer stable snapshot ids when caller passes them.
    const asIds = (orderedUrlsOrIds || []).map((value) => String(value ?? '').trim()).filter(Boolean);
    if (asIds.length > 0 && asIds.every((value) => stableIds.includes(value))) {
        void reorderGalleryViaApi(id, asIds).catch((error) => {
            console.warn('Gallery reorder failed', error);
        });

        return current;
    }

    const orderKeys = asIds;
    const remaining = [...current];
    const ordered = [];

    orderKeys.forEach((key) => {
        const index = remaining.findIndex(
            (row) => String(row.url) === key || String(row.id) === key || String(row.stable_id) === key,
        );
        if (index < 0) {
            return;
        }
        ordered.push(remaining[index]);
        remaining.splice(index, 1);
    });

    return saveProductAlbum(id, [...ordered, ...remaining]);
}

export function persistProductAlbumDraftToServer(articleId, wire) {
    const items = loadProductAlbum(articleId);

    return replaceGalleryViaApi(articleId, items).catch(() => {
        if (wire?.persistProductAlbumFromClient) {
            return wire.persistProductAlbumFromClient(items);
        }

        return items;
    });
}

export async function syncProductAlbumToServer(articleId) {
    return persistProductAlbumDraftToServer(articleId, null);
}

/**
 * Client-only clear before reload. Must NOT PUT empty gallery to server
 * (that wiped WP pull results and caused media_snapshot_version_conflict).
 */
export function clearProductAlbumStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return [];
    }

    const current = getMediaSnapshot(id);
    if (current?.article_id) {
        applyMediaSnapshot(id, {
            ...current,
            gallery: {
                required: Boolean(current.gallery?.required),
                items: [],
            },
        }, { force: true });
    } else {
        clearMediaSnapshot(id);
        discardLegacyMediaLocalStorage(id);
    }

    return [];
}
