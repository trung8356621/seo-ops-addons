import { getPublishingState, publishingActions } from './state.js';

/**
 * Thin Pass-0 hook — wraps publishing domain module. Host may keep shell snapshots.
 */
export function usePublishingEditor() {
    return {
        getState: getPublishingState,
        actions: publishingActions,
    };
}
