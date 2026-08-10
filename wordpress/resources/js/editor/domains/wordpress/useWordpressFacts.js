import { getWordpressSnapshot, wordpressActions } from './snapshotStore.js';

/**
 * Thin Pass-0 hook — wraps wordpress facts snapshot (read-only remote facts).
 */
export function useWordpressFacts() {
    return {
        getSnapshot: getWordpressSnapshot,
        actions: wordpressActions,
    };
}
