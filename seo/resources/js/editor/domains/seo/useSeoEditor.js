import { useSyncExternalStore } from 'react';
import { getSeoState, seoActions, seoApi, subscribe } from './state.js';

/**
 * SEO domain SoT — focusKeyword + analysis live in module store.
 */
export function useSeoEditor() {
    const snapshot = useSyncExternalStore(subscribe, getSeoState, getSeoState);

    return {
        focusKeyword: snapshot.focusKeyword,
        analysis: snapshot.analysis,
        seoScore: snapshot.seoScore,
        actions: seoActions,
        api: seoApi,
    };
}
