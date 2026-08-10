import { useSyncExternalStore } from 'react';
import { getMediaDomainState, mediaActions, mediaApi, subscribe } from './state.js';

/**
 * Media domain SoT — featured/gallery/post/supplemental image catalogs live in the
 * module store (mirrors useSeoEditor). Reactive via useSyncExternalStore.
 */
export function useMediaEditor() {
    const snapshot = useSyncExternalStore(subscribe, getMediaDomainState, getMediaDomainState);

    return {
        featuredHealthSnapshot: snapshot.featuredHealthSnapshot,
        gallery: snapshot.gallery,
        postImages: snapshot.postImages,
        supplementalImages: snapshot.supplementalImages,
        getSnapshot: mediaApi.getSnapshot,
        actions: mediaActions,
        api: mediaApi,
    };
}
