import { diagSnapshotRefresh } from '@content-addon/editor/domains/editorDiagnostics.js';

/**
 * WordPress external facts — read-only in editor. Never authoritative for editable fields.
 * @type {{
 *   wpPostId: number|null,
 *   syncStatus: string|null,
 *   capabilities: object|null,
 *   remoteSnapshot: object|null,
 * }}
 */
const facts = {
    wpPostId: null,
    syncStatus: null,
    capabilities: null,
    remoteSnapshot: null,
};

export function getWordpressSnapshot() {
    return { ...facts };
}

export const wordpressActions = {
    /**
     * @param {Partial<typeof facts>} patch
     */
    applyRemoteFacts(patch) {
        diagSnapshotRefresh('wordpress', { keys: Object.keys(patch || {}) });
        Object.assign(facts, patch || {});
    },

    /**
     * Remote refresh must NOT overwrite content/media/seo editable state.
     */
    refreshSnapshot(factsPatch) {
        wordpressActions.applyRemoteFacts(factsPatch);
    },
};
