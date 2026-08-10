import { useSyncExternalStore } from 'react';
import { getContentState, contentActions, subscribe } from './state.js';
import { contentApi } from './actions.js';

/**
 * Content domain SoT — reactive read via useSyncExternalStore (mirrors useSeoEditor).
 */
export function useContentEditor() {
    const snapshot = useSyncExternalStore(subscribe, getContentState, getContentState);

    return {
        html: snapshot.html,
        editorDocument: snapshot.editorDocument,
        articleMeta: snapshot.articleMeta,
        faqs: snapshot.faqs,
        blocks: snapshot.blocks,
        title: snapshot.title,
        slug: snapshot.slug,
        excerpt: snapshot.excerpt,
        document_version: snapshot.document_version,
        contentDirty: snapshot.contentDirty,
        getState: getContentState,
        actions: contentActions,
        api: contentApi,
    };
}

/**
 * Fine-grained selector — subscribe to a derived slice of content state
 * without re-rendering on unrelated field changes.
 * @template T
 * @param {(state: import('./state.js').ContentState) => T} selector
 * @returns {T}
 */
export function useContentSelector(selector) {
    return useSyncExternalStore(
        subscribe,
        () => selector(getContentState()),
        () => selector(getContentState()),
    );
}
