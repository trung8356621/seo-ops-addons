import { setOwnerDirty, isOwnerDirty } from '@client-core/ownerDirtyState.js';
import { diagDirty, diagMutationStart, diagMutationEnd } from '../editorDiagnostics.js';

/**
 * @typedef {object} ContentState
 * @property {string} html
 * @property {unknown} editorDocument
 * @property {object|null} articleMeta
 * @property {unknown[]|null} faqs
 * @property {Array<object>} blocks - Block-editor SoT (Task 7 extraction from SeoArticleEditor useState).
 * @property {string} title
 * @property {string} slug
 * @property {string} excerpt
 * @property {number|null} document_version
 * @property {boolean} contentDirty
 */

/** @type {ContentState} */
const state = {
    html: '',
    editorDocument: null,
    articleMeta: null,
    faqs: null,
    blocks: [],
    title: '',
    slug: '',
    excerpt: '',
    document_version: null,
    contentDirty: false,
    activeBlockId: null,
    tempMerge: null,
    globalEditor: null,
};

/** @type {Set<() => void>} */
const listeners = new Set();

/** Cached snapshot for useSyncExternalStore — must be referentially stable between emits. */
let snapshot = { ...state };

/**
 * Reactive subscription (mirrors seo/state.js) — lets hosts read content
 * state via useSyncExternalStore instead of local useState mirrors.
 * @param {() => void} listener
 * @returns {() => void}
 */
export function subscribe(listener) {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

function emit() {
    snapshot = { ...state };
    listeners.forEach((listener) => {
        listener();
    });
}

export function getContentState() {
    return snapshot;
}

export const contentActions = {
    /**
     * @param {Partial<typeof state>} patch
     */
    patch(patch) {
        diagMutationStart('content', { keys: Object.keys(patch || {}) });
        Object.assign(state, patch || {}, { contentDirty: true });
        setOwnerDirty('content', true);
        diagDirty('content', true);
        diagMutationEnd('content', {});
        emit();
    },

    /**
     * Replace the full block list (mirrors legacy `setBlocks(nextBlocks)`).
     * @param {Array<object>} nextBlocks
     */
    replaceBlocks(nextBlocks) {
        contentActions.patch({ blocks: Array.isArray(nextBlocks) ? nextBlocks : [] });
    },

    /**
     * Patch a single block by id (shallow merge).
     * @param {string} blockId
     * @param {object|((block: object) => object)} patchOrUpdater
     */
    updateBlock(blockId, patchOrUpdater) {
        const next = state.blocks.map((block) => {
            if (block.id !== blockId) {
                return block;
            }
            return typeof patchOrUpdater === 'function'
                ? patchOrUpdater(block)
                : { ...block, ...patchOrUpdater };
        });
        contentActions.patch({ blocks: next });
    },

    /**
     * Insert a block at a given index (defaults to end).
     * @param {object} block
     * @param {number} [index]
     */
    insertBlock(block, index = -1) {
        const next = [...state.blocks];
        const at = index < 0 || index > next.length ? next.length : index;
        next.splice(at, 0, block);
        contentActions.patch({ blocks: next });
    },

    /**
     * Remove a block by id.
     * @param {string} blockId
     */
    removeBlock(blockId) {
        contentActions.patch({ blocks: state.blocks.filter((block) => block.id !== blockId) });
    },

    /**
     * @param {string|null} blockId
     */
    setActiveBlockId(blockId) {
        // Session UI pointer — do not mark content dirty.
        state.activeBlockId = blockId ?? null;
        emit();
    },

    /**
     * @param {unknown} merge
     */
    setTempMerge(merge) {
        state.tempMerge = merge;
        emit();
    },

    /**
     * @param {unknown} editor
     */
    setGlobalEditor(editor) {
        state.globalEditor = editor;
        emit();
    },

    markClean() {
        state.contentDirty = false;
        setOwnerDirty('content', false);
        diagDirty('content', false);
        emit();
    },

    isDirty() {
        return isOwnerDirty('content');
    },

    /**
     * Payload slice — missing keys = untouched for coordinator merge.
     * @returns {Record<string, unknown>}
     */
    flush() {
        if (!contentActions.isDirty()) {
            return {};
        }
        return {
            html: state.html,
            client_rendered_html: state.html,
            editor_document: state.editorDocument,
            article_meta: state.articleMeta,
            ...(state.faqs !== null ? { faqs: state.faqs } : {}),
        };
    },
};
