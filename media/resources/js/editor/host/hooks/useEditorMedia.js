import { useCallback, useEffect, useMemo, useState } from 'react';
import {
    clearFeaturedViaApi,
    fetchMediaSnapshot,
    getMediaSnapshot,
    reorderGalleryViaApi,
    replaceGalleryViaApi,
    setFeaturedViaApi,
    subscribeMediaSnapshot,
} from '@content-addon/utils/articleEditorMediaSnapshot.js';
import { getEditorCommandHost } from '@content-addon/utils/editorCommands/index.js';
import { canMutateEditor } from '@content-addon/utils/editorSessionState.js';

/**
 * Phase 6C.3 — media snapshot + Featured/Gallery API contract.
 */
export function useEditorMedia(articleId = null) {
    const id = Number(articleId ?? getEditorCommandHost()?.articleId ?? 0) || 0;
    const [snapshot, setSnapshot] = useState(() => getMediaSnapshot(id));

    useEffect(() => {
        if (!id) {
            return undefined;
        }
        setSnapshot(getMediaSnapshot(id));
        const unsub = subscribeMediaSnapshot(({ articleId: aid, snapshot: next }) => {
            if (Number(aid) === id) {
                setSnapshot(next);
            }
        });
        return unsub;
    }, [id]);

    const refresh = useCallback(async () => {
        if (!id) return null;
        return fetchMediaSnapshot(id);
    }, [id]);

    const canMutate = useCallback(() => {
        if (!canMutateEditor()) return false;
        if (getEditorCommandHost()?.isArchived?.()) return false;
        return true;
    }, []);

    return useMemo(() => ({
        articleId: id,
        snapshot,
        featured: snapshot?.featured ?? null,
        gallery: snapshot?.gallery ?? { required: false, items: [] },
        capabilities: snapshot?.capabilities ?? {},
        snapshotVersion: Number(snapshot?.snapshot_version) || 1,
        canMutate,
        refresh,
        setFeatured: (item) => {
            if (!id || !canMutate()) {
                return Promise.reject(new Error('media_read_only'));
            }
            return setFeaturedViaApi(id, item);
        },
        clearFeatured: () => {
            if (!id || !canMutate()) {
                return Promise.reject(new Error('media_read_only'));
            }
            return clearFeaturedViaApi(id);
        },
        replaceGallery: (items) => {
            if (!id || !canMutate()) {
                return Promise.reject(new Error('media_read_only'));
            }
            return replaceGalleryViaApi(id, items);
        },
        reorderGallery: (orderedIds) => {
            if (!id || !canMutate()) {
                return Promise.reject(new Error('media_read_only'));
            }
            return reorderGalleryViaApi(id, orderedIds);
        },
    }), [id, snapshot, canMutate, refresh]);
}
