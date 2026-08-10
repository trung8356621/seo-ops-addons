import { setOwnerDirty, isOwnerDirty } from '@client-core/ownerDirtyState.js';
import { diagDirty, diagMutationStart, diagMutationEnd, diagSnapshotRefresh } from '@content-addon/editor/domains/editorDiagnostics.js';

/** @type {{ publishBox: object|null, categoryIds: unknown[]|null }} */
const state = {
    publishBox: null,
    categoryIds: null,
};

export function getPublishingState() {
    return { ...state };
}

export const publishingActions = {
    /**
     * Refresh from Alpine shell snapshots — explicit call only.
     */
    refreshFromShell() {
        diagMutationStart('publishing', { op: 'refreshFromShell' });
        diagSnapshotRefresh('publishing', {});
        if (typeof window.__seoPublishBoxSnapshot === 'function') {
            state.publishBox = window.__seoPublishBoxSnapshot();
        }
        if (typeof window.__seoPublishCategoriesSnapshot === 'function') {
            state.categoryIds = window.__seoPublishCategoriesSnapshot();
        }
        setOwnerDirty('publishing', true);
        diagDirty('publishing', true);
        diagMutationEnd('publishing', {});
    },

    markDirty() {
        setOwnerDirty('publishing', true);
        diagDirty('publishing', true);
    },

    markClean() {
        setOwnerDirty('publishing', false);
        diagDirty('publishing', false);
    },

    isDirty() {
        return isOwnerDirty('publishing');
    },

    flush() {
        if (!publishingActions.isDirty()) {
            return {};
        }
        /** @type {Record<string, unknown>} */
        const out = {};
        if (state.publishBox !== undefined) {
            out.publish_box = state.publishBox;
        }
        if (state.categoryIds !== undefined) {
            out.category_ids = state.categoryIds;
        }
        return out;
    },
};
