/**
 * Canonical Article Editor media snapshot (Phase 2A).
 * Laravel SoT; React holds in-memory snapshot only. No Featured/Gallery localStorage SoT.
 */

export const ARTICLE_EDITOR_MEDIA_SNAPSHOT_EVENT = 'article-editor-media-snapshot-changed';

const LEGACY_FEATURED_KEY = (articleId) => `seo_featured_image_${articleId}`;
const LEGACY_ALBUM_KEY = (articleId) => `seo_product_album_list_${articleId}`;

/** @type {Record<number, object>} */
const snapshotsByArticle = Object.create(null);
/** @type {Set<(detail: { articleId: number, snapshot: object }) => void>} */
const snapshotListeners = new Set();
/** @type {Map<string, Promise<object|null>>} */
const inFlightRequests = new Map();
/** @type {Record<number, number>} */
const requestSequencesByArticle = Object.create(null);
export const MEDIA_SNAPSHOT_REFRESH_TTL_MS = 4 * 60 * 1000;

function nextRequestSequence(articleId) {
    const id = Number(articleId) || 0;
    requestSequencesByArticle[id] = (Number(requestSequencesByArticle[id]) || 0) + 1;

    return requestSequencesByArticle[id];
}

function isCurrentRequest(articleId, sequence) {
    return (Number(requestSequencesByArticle[Number(articleId) || 0]) || 0) === Number(sequence);
}

/**
 * Phase 6C.3 — React panels subscribe without Alpine mirror.
 * @param {(detail: { articleId: number, snapshot: object }) => void} listener
 * @returns {() => void}
 */
export function subscribeMediaSnapshot(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }
    snapshotListeners.add(listener);
    return () => snapshotListeners.delete(listener);
}

function emitSnapshotListeners(articleId, snapshot) {
    const detail = { articleId: Number(articleId) || 0, snapshot };
    snapshotListeners.forEach((listener) => {
        try {
            listener(detail);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[media-snapshot] listener failed', error);
        }
    });
}

function emptySnapshot(articleId = 0) {
    return {
        version: 1,
        snapshot_version: 1,
        article_id: Number(articleId) || 0,
        document_version: 1,
        generated_at: null,
        featured: null,
        content_images: {
            occurrence_count: 0,
            valid_count: 0,
            invalid_count: 0,
            items: [],
        },
        gallery: {
            required: false,
            items: [],
        },
        capabilities: {
            can_edit_featured: false,
            can_edit_gallery: false,
            can_browse_wordpress_media: false,
            can_upload_local_media: false,
            can_rename_wordpress_media: false,
        },
    };
}

/**
 * Discard legacy Featured/Gallery localStorage shadow SoT. Never apply into React.
 */
export function discardLegacyMediaLocalStorage(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    try {
        window.localStorage.removeItem(LEGACY_FEATURED_KEY(id));
        window.localStorage.removeItem(LEGACY_ALBUM_KEY(id));
    } catch {
        // ignore
    }
}

/**
 * Drop in-memory snapshot + legacy localStorage only. Never mutates server.
 * Used before reload after WordPress pull / destructive overwrite.
 */
export function clearMediaSnapshot(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return;
    }

    delete snapshotsByArticle[id];
    discardLegacyMediaLocalStorage(id);
}

export function getMediaSnapshot(articleId) {
    const id = Number(articleId ?? 0);
    if (!Number.isFinite(id) || id <= 0) {
        return emptySnapshot(0);
    }

    return snapshotsByArticle[id] ? { ...snapshotsByArticle[id] } : emptySnapshot(id);
}

/**
 * Apply server snapshot if version is not older than current.
 * @returns {object|null} applied snapshot or null if ignored as stale
 */
export function applyMediaSnapshot(articleId, snapshot, { force = false, emitLegacyGallery = false } = {}) {
    const id = Number(articleId ?? snapshot?.article_id ?? 0);
    if (!Number.isFinite(id) || id <= 0 || !snapshot || typeof snapshot !== 'object') {
        return null;
    }

    const incoming = Math.max(1, Number(snapshot.snapshot_version) || 1);
    const current = snapshotsByArticle[id];
    const currentVersion = Math.max(1, Number(current?.snapshot_version) || 0);

    if (!force && current && incoming < currentVersion) {
        return null;
    }

    const next = {
        ...emptySnapshot(id),
        ...snapshot,
        article_id: id,
        snapshot_version: incoming,
        featured: snapshot.featured ?? null,
        gallery: snapshot.gallery && typeof snapshot.gallery === 'object'
            ? {
                required: Boolean(snapshot.gallery.required),
                items: Array.isArray(snapshot.gallery.items) ? snapshot.gallery.items : [],
            }
            : emptySnapshot(id).gallery,
        content_images: snapshot.content_images && typeof snapshot.content_images === 'object'
            ? snapshot.content_images
            : emptySnapshot(id).content_images,
        capabilities: snapshot.capabilities && typeof snapshot.capabilities === 'object'
            ? snapshot.capabilities
            : emptySnapshot(id).capabilities,
    };

    snapshotsByArticle[id] = next;
    discardLegacyMediaLocalStorage(id);
    emitSnapshotListeners(id, next);

    const featuredPresent = Boolean(next.featured?.url);
    const galleryCount = Array.isArray(next.gallery?.items) ? next.gallery.items.length : 0;

    window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_MEDIA_SNAPSHOT_EVENT, {
        detail: {
            article_id: id,
            snapshot_version: next.snapshot_version,
            featured_present: featuredPresent,
            gallery_count: galleryCount,
            media_snapshot: next,
        },
    }));

    if (emitLegacyGallery) {
        window.dispatchEvent(new CustomEvent('seo-product-gallery-updated', {
            detail: {
                article_id: id,
                articleId: id,
                gallery: (next.gallery?.items || []).map((row) => ({
                    id: Number(row.wp_attachment_id || row.media_id || 0) || 0,
                    asset_key: String(row.asset_key || row.id || ''),
                    source: String(row.source || ''),
                    url: String(row.url || ''),
                })),
                from_snapshot: true,
            },
        }));
    }

    return next;
}

export function featuredFromSnapshot(articleId) {
    const featured = getMediaSnapshot(articleId).featured;
    const url = String(
        featured?.url
        ?? featured?.src
        ?? featured?.thumb_url
        ?? featured?.thumbnail_url
        ?? featured?.featured_thumb_url
        ?? '',
    ).trim();

    if (url === '') {
        return null;
    }

    return {
        url,
        wp_attachment_id: Number(featured.wp_attachment_id) || 0,
        seo_media_id: Number(featured.media_id) || 0,
        asset_key: String(featured.asset_key || featured.id || ''),
        source: String(featured.source || ''),
        alt: String(featured.alt || ''),
        slug: String(featured.filename || ''),
    };
}

export function galleryFromSnapshot(articleId) {
    const items = getMediaSnapshot(articleId).gallery?.items;
    if (!Array.isArray(items)) {
        return [];
    }

    return items
        .map((row) => ({
            id: Number(row.wp_attachment_id || row.media_id || 0) || 0,
            asset_key: String(row.asset_key || row.id || ''),
            source: String(row.source || ''),
            url: String(row.url || '').trim(),
            stable_id: String(row.id || ''),
        }))
        .filter((row) => row.url !== '');
}

function sessionHeaders() {
    const client = window.__seoEditorSessionClient;
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (csrf) {
        headers['X-CSRF-TOKEN'] = csrf;
    }
    if (client?.sessionId) {
        headers['X-Editor-Session-Id'] = client.sessionId;
    }

    return headers;
}

function sessionBodyExtras(articleId) {
    const client = window.__seoEditorSessionClient;
    const snap = getMediaSnapshot(articleId);

    return {
        editor_session_id: client?.sessionId || null,
        expected_snapshot_version: snap.snapshot_version || null,
    };
}

async function parseSnapshotResponse(response, articleId, { apply = true, sequence = null, emitLegacyGallery = false } = {}) {
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.success === false) {
        const error = new Error(data?.message || `HTTP ${response.status}`);
        error.code = data?.error || 'media_mutation_failed';
        error.data = data;
        throw error;
    }

    const snapshot = data.media_snapshot || data.data?.media_snapshot;
    if (snapshot && apply && (sequence === null || isCurrentRequest(articleId, sequence))) {
        applyMediaSnapshot(articleId, snapshot, { emitLegacyGallery });
    }

    return snapshot || getMediaSnapshot(articleId);
}

export async function fetchMediaSnapshot(articleId, endpoint) {
    const id = Number(articleId);
    const key = `article:${id}:snapshot:refresh`;
    if (inFlightRequests.has(key)) {
        return inFlightRequests.get(key);
    }

    const url = endpoint || `/api/seo/articles/${id}/editor/media-snapshot`;
    const sequence = nextRequestSequence(id);
    const task = fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: sessionHeaders(),
    }).then((response) => parseSnapshotResponse(response, id, { sequence }));

    inFlightRequests.set(key, task);
    try {
        return await task;
    } finally {
        inFlightRequests.delete(key);
    }
}

export function mediaSnapshotAgeMs(articleId, nowMs = Date.now()) {
    const generatedAt = String(getMediaSnapshot(articleId)?.generated_at || '').trim();
    const timestamp = generatedAt !== '' ? Date.parse(generatedAt) : NaN;
    if (!Number.isFinite(timestamp) || timestamp <= 0) {
        return Number.POSITIVE_INFINITY;
    }

    return Math.max(0, Number(nowMs) - timestamp);
}

export async function refreshMediaSnapshotIfStale(articleId, endpoint, minAgeMs = MEDIA_SNAPSHOT_REFRESH_TTL_MS) {
    if (mediaSnapshotAgeMs(articleId) < minAgeMs) {
        return getMediaSnapshot(articleId);
    }

    return fetchMediaSnapshot(articleId, endpoint);
}

function mediaSnapshotMutationKey(articleId, kind) {
    return `article:${Number(articleId) || 0}:media:${kind}`;
}

function shouldRefreshOnConflict(error) {
    return error?.code === 'media_snapshot_version_conflict'
        || Number(error?.data?.conflict?.snapshot_version || 0) > 0;
}

async function runMediaMutation(articleId, kind, request) {
    const id = Number(articleId);
    const key = mediaSnapshotMutationKey(id, kind);
    if (inFlightRequests.has(key)) {
        return inFlightRequests.get(key);
    }

    const previous = getMediaSnapshot(id);
    const sequence = nextRequestSequence(id);
    const task = request()
        .then((response) => parseSnapshotResponse(response, id, { sequence, emitLegacyGallery: kind === 'gallery' }))
        .catch(async (error) => {
            if (shouldRefreshOnConflict(error)) {
                await fetchMediaSnapshot(id);
            } else {
                applyMediaSnapshot(id, previous, { force: true });
            }
            throw error;
        });

    inFlightRequests.set(key, task);
    try {
        return await task;
    } finally {
        inFlightRequests.delete(key);
    }
}

export async function flushMediaSnapshotMutations(articleId) {
    const id = Number(articleId);
    if (!Number.isFinite(id) || id <= 0) {
        return getMediaSnapshot(0);
    }

    const keys = [
        mediaSnapshotMutationKey(id, 'featured'),
        mediaSnapshotMutationKey(id, 'gallery'),
    ];
    const pending = keys
        .map((key) => inFlightRequests.get(key))
        .filter(Boolean);

    if (pending.length > 0) {
        await Promise.all(pending);
    }

    return getMediaSnapshot(id);
}

/**
 * Normalize Featured media identity from picker / block / Livewire payloads.
 * Accepts snake_case + camelCase aliases.
 *
 * @param {Record<string, unknown>|null|undefined} item
 * @returns {{
 *   url: string,
 *   wp_attachment_id: number,
 *   seo_media_id: number,
 *   id: number,
 *   alt: string,
 *   slug: string,
 * }|null}
 */
export function normalizeFeaturedMediaItem(item) {
    if (!item || typeof item !== 'object') {
        return null;
    }

    const url = String(item.url ?? item.src ?? item.localSrc ?? item.thumb_url ?? '').trim();
    if (url === '') {
        return null;
    }

    const wpAttachmentId = Number(
        item.wp_attachment_id
        ?? item.wpAttachmentId
        ?? item.attachment_id
        ?? item.attachmentId
        ?? 0,
    ) || 0;
    const seoMediaId = Number(
        item.seo_media_id
        ?? item.seoMediaId
        ?? item.media_id
        ?? item.mediaId
        ?? 0,
    ) || 0;
    const rawId = Number(item.id ?? 0) || 0;
    const id = wpAttachmentId > 0 ? wpAttachmentId : (seoMediaId > 0 ? seoMediaId : rawId);

    return {
        url,
        wp_attachment_id: wpAttachmentId,
        seo_media_id: seoMediaId,
        asset_key: String(item.asset_key ?? item.assetKey ?? '').trim()
            || (wpAttachmentId > 0 ? `wp:${wpAttachmentId}` : (seoMediaId > 0 ? `local:${seoMediaId}` : '')),
        source: String(item.source ?? (wpAttachmentId > 0 ? 'wordpress' : (seoMediaId > 0 ? 'local' : ''))).trim(),
        id: id > 0 ? id : 0,
        alt: String(item.alt ?? '').trim(),
        slug: String(item.slug ?? item.filename ?? '').trim(),
    };
}

export async function setFeaturedViaApi(articleId, item, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/featured`;
    const normalized = normalizeFeaturedMediaItem(item);
    if (!normalized) {
        throw new Error('Featured image URL is required.');
    }

    if (getMediaSnapshot(id)?.gallery?.required) {
        const error = new Error('Product posts use the first gallery item as featured.');
        error.code = 'featured_managed_by_gallery';
        throw error;
    }

    return runMediaMutation(id, 'featured', () => fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify({
            ...sessionBodyExtras(id),
            item: normalized,
            url: normalized.url,
            wp_attachment_id: normalized.wp_attachment_id || undefined,
            seo_media_id: normalized.seo_media_id || undefined,
            id: normalized.id || undefined,
        }),
    }));
}

export async function clearFeaturedViaApi(articleId, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/featured`;
    if (getMediaSnapshot(id)?.gallery?.required) {
        const error = new Error('Clear product featured by clearing the product album.');
        error.code = 'featured_managed_by_gallery';
        throw error;
    }

    return runMediaMutation(id, 'featured', () => fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify(sessionBodyExtras(id)),
    }));
}

export async function replaceGalleryViaApi(articleId, items, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/gallery`;
    return runMediaMutation(id, 'gallery', () => fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify({
            ...sessionBodyExtras(id),
            items,
        }),
    }));
}

export async function reorderGalleryViaApi(articleId, orderedIds, endpoint) {
    const id = Number(articleId);
    const url = endpoint || `/api/seo/articles/${id}/editor/media/gallery/reorder`;
    return runMediaMutation(id, 'gallery', () => fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: sessionHeaders(),
        body: JSON.stringify({
            ...sessionBodyExtras(id),
            ordered_ids: orderedIds,
        }),
    }));
}

export default {
    ARTICLE_EDITOR_MEDIA_SNAPSHOT_EVENT,
    discardLegacyMediaLocalStorage,
    clearMediaSnapshot,
    getMediaSnapshot,
    applyMediaSnapshot,
    featuredFromSnapshot,
    galleryFromSnapshot,
    fetchMediaSnapshot,
    normalizeFeaturedMediaItem,
    setFeaturedViaApi,
    clearFeaturedViaApi,
    replaceGalleryViaApi,
    reorderGalleryViaApi,
};
