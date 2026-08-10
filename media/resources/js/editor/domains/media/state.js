import { setOwnerDirty, isOwnerDirty } from '@client-core/ownerDirtyState.js';
import { applyMediaSnapshot, getMediaSnapshot } from '@content-addon/utils/articleEditorMediaSnapshot.js';
import { diagDirty, diagMutationStart, diagMutationEnd, diagApi } from '@content-addon/editor/domains/editorDiagnostics.js';

/**
 * UI-facing media catalogs (featured health mirror, gallery, post/supplemental image
 * catalogs). Distinct from the Laravel-SoT snapshot cache in articleEditorMediaSnapshot.js —
 * these are the host's editable working copies, now owned by the domain store instead of
 * component useState so multiple consumers can read/write the same SoT.
 * @type {{ featuredHealthSnapshot: object|null, gallery: unknown[], postImages: unknown[], supplementalImages: unknown[] }}
 */
const state = {
    featuredHealthSnapshot: null,
    gallery: [],
    postImages: [],
    supplementalImages: [],
};

/** @type {Set<() => void>} */
const listeners = new Set();

/** Cached snapshot for useSyncExternalStore — must be referentially stable between emits. */
let snapshot = {
    featuredHealthSnapshot: state.featuredHealthSnapshot,
    gallery: state.gallery,
    postImages: state.postImages,
    supplementalImages: state.supplementalImages,
};

/**
 * @param {() => void} listener
 * @returns {() => void}
 */
export function subscribe(listener) {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

function refreshSnapshot() {
    snapshot = {
        featuredHealthSnapshot: state.featuredHealthSnapshot,
        gallery: state.gallery,
        postImages: state.postImages,
        supplementalImages: state.supplementalImages,
    };
}

function emit() {
    refreshSnapshot();
    listeners.forEach((listener) => {
        listener();
    });
}

export function getMediaDomainState() {
    return snapshot;
}

/**
 * @param {unknown} current
 * @param {unknown|((prev: unknown) => unknown)} valueOrUpdater
 */
function resolveNext(current, valueOrUpdater) {
    return typeof valueOrUpdater === 'function' ? valueOrUpdater(current) : valueOrUpdater;
}

export const mediaActions = {
    /**
     * Explicit featured mutation. Missing key on flush = unchanged; null = clear.
     * @param {number} articleId
     * @param {object|null} featured
     */
    setFeatured(articleId, featured) {
        diagMutationStart('media', { op: 'setFeatured', articleId });
        const id = Number(articleId || 0);
        const snap = getMediaSnapshot(id) || { featured: null, gallery: { required: false, items: [] } };
        applyMediaSnapshot(id, {
            ...snap,
            featured: featured === undefined ? snap.featured : featured,
        }, { force: true });
        setOwnerDirty('media', true);
        diagDirty('media', true);
        diagMutationEnd('media', {});
    },

    /**
     * Explicit clear featured (null intent).
     * @param {number} articleId
     */
    clearFeatured(articleId) {
        mediaActions.setFeatured(articleId, null);
    },

    /**
     * Featured-image health mirror (rendered in Images tab / SEO widgets).
     * Setter-style (accepts value or updater) so call sites read like useState setters.
     * @param {object|null|((prev: object|null) => object|null)} valueOrUpdater
     */
    setFeaturedHealthSnapshot(valueOrUpdater) {
        diagMutationStart('media', { op: 'setFeaturedHealthSnapshot' });
        state.featuredHealthSnapshot = resolveNext(state.featuredHealthSnapshot, valueOrUpdater);
        diagMutationEnd('media', {});
        emit();
    },

    /**
     * Product gallery working copy.
     * @param {unknown[]|((prev: unknown[]) => unknown[])} valueOrUpdater
     */
    setGallery(valueOrUpdater) {
        diagMutationStart('media', { op: 'setGallery' });
        state.gallery = resolveNext(state.gallery, valueOrUpdater);
        diagMutationEnd('media', {});
        emit();
    },

    /**
     * Post (body) image catalog synced from editor images pipeline.
     * @param {unknown[]|((prev: unknown[]) => unknown[])} valueOrUpdater
     */
    setPostImages(valueOrUpdater) {
        diagMutationStart('media', { op: 'setPostImages' });
        state.postImages = resolveNext(state.postImages, valueOrUpdater);
        diagMutationEnd('media', {});
        emit();
    },

    /**
     * Supplemental (non-block) image catalog — feeds Images tab + gallery distribution.
     * @param {unknown[]|((prev: unknown[]) => unknown[])} valueOrUpdater
     */
    setSupplementalImages(valueOrUpdater) {
        diagMutationStart('media', { op: 'setSupplementalImages' });
        state.supplementalImages = resolveNext(state.supplementalImages, valueOrUpdater);
        diagMutationEnd('media', {});
        emit();
    },

    /**
     * One-shot mount hydrate — writes without emit so callers can run during render
     * before useMediaEditor() subscribes (avoids sync listener re-entry / TDZ).
     * @param {{ featuredHealthSnapshot?: object|null, gallery?: unknown[], postImages?: unknown[], supplementalImages?: unknown[] }} next
     */
    hydrate(next = {}) {
        if (Object.prototype.hasOwnProperty.call(next, 'featuredHealthSnapshot')) {
            state.featuredHealthSnapshot = next.featuredHealthSnapshot ?? null;
        }
        if (Object.prototype.hasOwnProperty.call(next, 'gallery')) {
            state.gallery = Array.isArray(next.gallery) ? next.gallery : [];
        }
        if (Object.prototype.hasOwnProperty.call(next, 'postImages')) {
            state.postImages = Array.isArray(next.postImages) ? next.postImages : [];
        }
        if (Object.prototype.hasOwnProperty.call(next, 'supplementalImages')) {
            state.supplementalImages = Array.isArray(next.supplementalImages) ? next.supplementalImages : [];
        }
        refreshSnapshot();
    },

    markDirty() {
        setOwnerDirty('media', true);
        diagDirty('media', true);
    },

    markClean() {
        setOwnerDirty('media', false);
        diagDirty('media', false);
    },

    isDirty() {
        return isOwnerDirty('media');
    },

    /**
     * @param {number} articleId
     * @returns {Record<string, unknown>}
     */
    flush(articleId) {
        if (!mediaActions.isDirty() || !articleId) {
            return {};
        }
        diagApi('media', 'flush', { articleId });
        const mediaSnapshot = getMediaSnapshot(articleId);
        if (mediaSnapshot == null) {
            return {};
        }
        /** @type {Record<string, unknown>} */
        const out = { media_snapshot: mediaSnapshot };
        if (Object.prototype.hasOwnProperty.call(mediaSnapshot, 'featured')) {
            out.featured_image = mediaSnapshot.featured ?? null;
        }
        if (mediaSnapshot?.gallery?.required && Array.isArray(mediaSnapshot.gallery.items)) {
            out.product_album = mediaSnapshot.gallery.items;
        }
        return out;
    },
};

export const mediaApi = {
    getSnapshot: getMediaSnapshot,
};
