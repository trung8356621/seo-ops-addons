/**
 * Context-menu selection capture/restore. Pure PM helpers — no React/Livewire.
 * Tiptap document stays the only source of truth; this never patches Outline.
 */

import { listHeadings, textblockDepth } from './editorCommands/headingSplitEngine.js';
import { canSplitIntoCanonicalBlocks } from './editorCommands/canonicalArticleBlockSplit.js';

export const CONTEXT_MENU_ROOT_ATTR = 'data-seo-ctx-menu';

/**
 * @param {number} value
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
export function clampNumber(value, min, max) {
    return Math.min(max, Math.max(min, value));
}

/**
 * @param {{ from: number, to: number, empty?: boolean }|null} selection
 * @param {number} clickPos
 * @returns {boolean}
 */
export function shouldKeepExistingSelection(selection, clickPos) {
    if (!selection || selection.empty) {
        return false;
    }
    const from = Number(selection.from);
    const to = Number(selection.to);
    const pos = Number(clickPos);
    if (!Number.isFinite(from) || !Number.isFinite(to) || !Number.isFinite(pos)) {
        return false;
    }

    return pos >= Math.min(from, to) && pos <= Math.max(from, to);
}

/**
 * @param {{ posAtCoords?: Function }|null} view
 * @param {number} clientX
 * @param {number} clientY
 * @param {number} fallbackPos
 * @returns {number}
 */
export function resolveClickPos(view, clientX, clientY, fallbackPos) {
    const coords = view?.posAtCoords?.({ left: clientX, top: clientY });
    if (coords && Number.isFinite(coords.pos)) {
        return coords.pos;
    }

    return Number.isFinite(fallbackPos) ? fallbackPos : 1;
}

/**
 * @param {import('@tiptap/pm/model').Node} doc
 * @param {number} pos
 * @returns {number|null}
 */
export function headingIndexAtPos(doc, pos) {
    const safePos = clampNumber(Number(pos) || 0, 0, doc.content.size);
    const $pos = doc.resolve(safePos);
    const depth = textblockDepth($pos);
    if (depth === 0 || $pos.node(depth).type.name !== 'heading') {
        return null;
    }
    const headingPos = $pos.before(depth);
    const found = listHeadings(doc).find((item) => item.pos === headingPos);

    return found ? found.index : null;
}

/**
 * @param {Event} event
 * @param {Element|null} root
 * @returns {boolean}
 */
export function eventPathContainsMenu(event, root) {
    if (!root) {
        return false;
    }
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    if (path.includes(root)) {
        return true;
    }
    const target = event.target;
    if (target && typeof target.closest === 'function' && target.closest(`[${CONTEXT_MENU_ROOT_ATTR}]`)) {
        return true;
    }

    return typeof root.contains === 'function' && root.contains(target);
}

/**
 * @param {number} x
 * @param {number} y
 * @param {number} width
 * @param {number} height
 * @param {{ vw: number, vh: number, pad?: number }} viewport
 * @returns {{ left: number, top: number, flipSubmenuLeft: boolean }}
 */
export function clampMenuPosition(x, y, width, height, viewport) {
    const pad = Number(viewport?.pad) || 8;
    const vw = Number(viewport?.vw) || 0;
    const vh = Number(viewport?.vh) || 0;
    let left = Number(x) || 0;
    let top = Number(y) || 0;
    const menuW = Math.max(0, Number(width) || 0);
    const menuH = Math.max(0, Number(height) || 0);

    if (vw > 0 && left + menuW > vw - pad) {
        left = Math.max(pad, vw - menuW - pad);
    }
    if (vh > 0 && top + menuH > vh - pad) {
        top = Math.max(pad, vh - menuH - pad);
    }
    if (left < pad) {
        left = pad;
    }
    if (top < pad) {
        top = pad;
    }

    return {
        left,
        top,
        flipSubmenuLeft: vw > 0 && left + menuW + 168 > vw - pad,
    };
}

function dispatchSelection(editor, selection) {
    editor.view.dispatch(
        editor.state.tr.setSelection(selection).setMeta('addToHistory', false),
    );
}

function selectionApi(editor) {
    const Ctor = editor.state.selection.constructor;

    return {
        near($pos) {
            if (typeof Ctor.near === 'function') {
                return Ctor.near($pos);
            }

            return Ctor.create(editor.state.doc, $pos.pos);
        },
        range(doc, from, to) {
            return Ctor.create(doc, from, to);
        },
    };
}

function markActive(state, markName) {
    const mark = state?.schema?.marks?.[markName];
    if (!mark) {
        return false;
    }
    if (typeof state?.doc?.rangeHasMark === 'function' && !state.selection.empty) {
        return state.doc.rangeHasMark(state.selection.from, state.selection.to, mark);
    }
    const marks = state?.selection?.$from?.marks?.() ?? [];

    return marks.some((mark) => mark.type.name === markName);
}

/**
 * Set a safe PM selection at the right-click (or keep a range that contains it).
 * Selection-only — does not change document content.
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {{ clientX: number, clientY: number, blockId: string }} input
 * @returns {object|null}
 */
export function captureEditorContextMenuSnapshot(editor, input) {
    if (!editor?.view || editor.isDestroyed) {
        return null;
    }

    const clickPos = resolveClickPos(
        editor.view,
        input.clientX,
        input.clientY,
        editor.state.selection.head,
    );
    const keep = shouldKeepExistingSelection(editor.state.selection, clickPos);

    if (!keep) {
        const $pos = editor.state.doc.resolve(clampNumber(clickPos, 0, editor.state.doc.content.size));
        dispatchSelection(editor, selectionApi(editor).near($pos));
    }

    const { state } = editor;
    const { selection } = state;
    const $from = selection.$from;
    const depth = textblockDepth($from);
    const node = depth > 0 ? $from.node(depth) : null;
    const nodeName = node?.type?.name === 'heading' ? 'heading' : (node?.type?.name || 'paragraph');
    const headingIndex = nodeName === 'heading' ? headingIndexAtPos(state.doc, selection.from) : null;

    return {
        blockId: String(input.blockId ?? ''),
        from: selection.from,
        to: selection.to,
        empty: selection.empty,
        clickPos,
        nodeName,
        headingLevel: nodeName === 'heading' ? Number(node.attrs?.level) || 2 : null,
        headingIndex,
        outlineVisible: node?.attrs?.outlineVisible !== false,
        inLink: Boolean(markActive(state, 'link')),
        keptRange: keep,
        parentOffset: depth > 0 ? selection.from - $from.start(depth) : 0,
        parentSize: node?.content?.size ?? 0,
        canSplitParagraph: canSplitIntoCanonicalBlocks(state, { empty: selection.empty }),
        canSplitCursor: Boolean(
            selection.empty
            && canSplitIntoCanonicalBlocks(state, { empty: true })
        ),
    };
}

/**
 * Restore the captured PM selection on the SAME editor. No window.getSelection().
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {{ from: number, to: number }} snapshot
 * @returns {boolean}
 */
export function applyContextMenuSelection(editor, snapshot) {
    if (!editor?.view || editor.isDestroyed || !snapshot) {
        return false;
    }
    const size = editor.state.doc.content.size;
    const from = clampNumber(Number(snapshot.from) || 0, 0, size);
    const to = clampNumber(Number(snapshot.to) || from, from, size);
    dispatchSelection(editor, selectionApi(editor).range(editor.state.doc, from, to));
    editor.view.focus();

    return true;
}

/**
 * Explicit UI → command mapping. Menu must not mutate the document itself.
 */
export const CONTEXT_MENU_COMMANDS = Object.freeze({
    splitParagraph: { name: 'split_selection_to_paragraph', args: () => ({}) },
    splitH3: { name: 'split_selection_to_heading', args: () => ({ level: 3 }) },
    splitH4: { name: 'split_selection_to_heading', args: () => ({ level: 4 }) },
    splitAtCursor: { name: 'split_paragraph_at_cursor', args: () => ({}) },
    changeH2: { name: 'change_current_block_heading', args: () => ({ level: 2 }) },
    changeH3: { name: 'change_current_block_heading', args: () => ({ level: 3 }) },
    changeH4: { name: 'change_current_block_heading', args: () => ({ level: 4 }) },
    changeHeadingLevel: {
        name: 'change_heading_level',
        args: (snapshot, level) => ({ headingIndex: snapshot.headingIndex, level }),
    },
    renameHeading: {
        name: 'rename_heading',
        args: (snapshot, text) => ({ headingIndex: snapshot.headingIndex, text }),
    },
    hideOutline: {
        name: 'set_heading_outline_visible',
        args: (snapshot, visible) => ({ headingIndex: snapshot.headingIndex, visible }),
    },
    deleteKeep: {
        name: 'delete_heading_keep_content',
        args: (snapshot) => ({ headingIndex: snapshot.headingIndex }),
    },
    deleteWith: {
        name: 'delete_heading_with_content',
        args: (snapshot) => ({ headingIndex: snapshot.headingIndex }),
    },
    insertHeadingAfter: {
        name: 'insert_heading_after',
        args: (snapshot, extra = {}) => ({
            headingIndex: snapshot.headingIndex,
            level: extra.level ?? 3,
            text: extra.text ?? '',
        }),
    },
    bulletList: { name: 'toggle_bullet_list', args: () => ({}) },
    orderedList: { name: 'toggle_ordered_list', args: () => ({}) },
    createLink: { name: 'create_link', args: (_s, href) => ({ href }) },
    updateLink: { name: 'update_link', args: (_s, href) => ({ href }) },
    removeLink: { name: 'remove_link_keep_text', args: () => ({}) },
    clearFormatting: { name: 'clear_formatting', args: () => ({}) },
});

export default {
    CONTEXT_MENU_COMMANDS,
    CONTEXT_MENU_ROOT_ATTR,
    applyContextMenuSelection,
    captureEditorContextMenuSnapshot,
    clampMenuPosition,
    clampNumber,
    eventPathContainsMenu,
    headingIndexAtPos,
    resolveClickPos,
    shouldKeepExistingSelection,
};
