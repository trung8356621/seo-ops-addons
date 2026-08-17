/**
 * Pure ProseMirror helpers for paragraph/heading structure.
 * No React, Livewire, or window. Safe to unit-test in Node.
 */

/**
 * @param {import('@tiptap/pm/model').ResolvedPos} $pos
 * @returns {number}
 */
export function textblockDepth($pos) {
    for (let depth = $pos.depth; depth > 0; depth -= 1) {
        if ($pos.node(depth).isTextblock) {
            return depth;
        }
    }

    return 0;
}

/**
 * @param {import('@tiptap/pm/model').Node} node
 * @returns {boolean}
 */
export function isSplittableTextblock(node) {
    const name = String(node?.type?.name ?? '');

    return name === 'paragraph' || name === 'heading';
}

/**
 * @param {import('@tiptap/pm/model').ResolvedPos} $pos
 * @returns {number}
 */
export function listItemDepth($pos) {
    for (let depth = $pos.depth; depth > 0; depth -= 1) {
        const name = String($pos.node(depth)?.type?.name ?? '');
        if (name === 'listItem' || name === 'list_item') {
            return depth;
        }
    }

    return 0;
}

/**
 * listItem content is `paragraph block*` — heading cannot be the first/only child.
 * Lift the textblock out of the list (split the list around it) then convert.
 *
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{
 *   textblockDepth: number,
 *   listItemDepth: number,
 *   type: import('@tiptap/pm/model').NodeType,
 *   attrs: object,
 * }} opts
 * @returns {boolean}
 */
function liftListItemTextblockToType(state, dispatch, opts) {
    const { selection } = state;
    const $from = selection.$from;
    const liDepth = opts.listItemDepth;
    const listDepth = liDepth - 1;
    if (listDepth < 1) {
        return false;
    }

    const listNode = $from.node(listDepth);
    const listName = String(listNode?.type?.name ?? '');
    if (listName !== 'bulletList' && listName !== 'bullet_list'
        && listName !== 'orderedList' && listName !== 'ordered_list') {
        return false;
    }

    const listPos = $from.before(listDepth);
    const liIndex = $from.index(listDepth);
    const textblock = $from.node(opts.textblockDepth);
    const converted = opts.type.create(
        { ...textblock.attrs, ...opts.attrs },
        textblock.content,
    );

    const beforeItems = [];
    const afterItems = [];
    listNode.forEach((child, _offset, index) => {
        if (index < liIndex) {
            beforeItems.push(child);
        } else if (index > liIndex) {
            afterItems.push(child);
        }
    });

    const nodes = [];
    if (beforeItems.length > 0) {
        nodes.push(listNode.type.create(listNode.attrs, beforeItems));
    }
    nodes.push(converted);
    if (afterItems.length > 0) {
        nodes.push(listNode.type.create(listNode.attrs, afterItems));
    }
    if (nodes.length === 0) {
        return false;
    }

    const tr = state.tr.replaceWith(listPos, listPos + listNode.nodeSize, nodes);
    dispatch?.(tr.scrollIntoView());

    return true;
}

/**
 * @param {import('@tiptap/pm/model').Node} doc
 * @param {(node: import('@tiptap/pm/model').Node, pos: number, index: number) => boolean|void} visitor
 * @returns {{ node: import('@tiptap/pm/model').Node, pos: number, index: number }|null}
 */
export function findHeadingByIndex(doc, headingIndex, visitor = null) {
    const target = Number(headingIndex);
    let index = 0;
    let found = null;

    doc.descendants((node, pos) => {
        if (found || node.type.name !== 'heading') {
            return true;
        }
        if (typeof visitor === 'function') {
            visitor(node, pos, index);
        }
        if (index === target) {
            found = { node, pos, index };
            return false;
        }
        index += 1;

        return true;
    });

    return found;
}

/**
 * @param {import('@tiptap/pm/model').Node} doc
 * @returns {Array<{ node: import('@tiptap/pm/model').Node, pos: number, index: number }>}
 */
export function listHeadings(doc) {
    const out = [];
    let index = 0;
    doc.descendants((node, pos) => {
        if (node.type.name !== 'heading') {
            return true;
        }
        out.push({ node, pos, index });
        index += 1;

        return true;
    });

    return out;
}

function headingAttrs(schema, level, extra = {}) {
    const nextLevel = Math.min(6, Math.max(1, Number(level) || 2));

    return { level: nextLevel, ...extra };
}

/**
 * Split the current textblock around the selection.
 * Selection becomes its own block of `nodeType` (heading/paragraph).
 * Empty before/after slices are omitted.
 *
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ nodeType?: 'heading'|'paragraph', level?: number, attrs?: object }} [options]
 * @returns {boolean}
 */
export function splitSelectionToBlockType(state, dispatch, options = {}) {
    const { selection, schema } = state;
    const { from, to, empty } = selection;
    const nodeTypeName = options.nodeType === 'paragraph' ? 'paragraph' : 'heading';
    const type = schema.nodes[nodeTypeName];
    const paragraphType = schema.nodes.paragraph;

    if (!type || !paragraphType) {
        return false;
    }

    if (empty) {
        return changeCurrentBlockType(state, dispatch, {
            nodeType: nodeTypeName,
            level: options.level,
            attrs: options.attrs,
        });
    }

    const $from = selection.$from;
    const depth = textblockDepth($from);
    if (depth === 0) {
        return false;
    }

    const parent = $from.node(depth);
    if (!isSplittableTextblock(parent)) {
        return false;
    }

    const $to = selection.$to;
    if (textblockDepth($to) !== depth || $to.before(depth) !== $from.before(depth)) {
        return false;
    }

    const parentPos = $from.before(depth);
    const parentStart = $from.start(depth);
    const relFrom = Math.max(0, from - parentStart);
    const relTo = Math.max(relFrom, Math.min(parent.content.size, to - parentStart));

    if (relFrom === relTo) {
        return false;
    }

    const before = parent.content.cut(0, relFrom);
    const selected = parent.content.cut(relFrom, relTo);
    const after = parent.content.cut(relTo, parent.content.size);

    if (selected.size === 0) {
        return false;
    }

    const nextAttrs = nodeTypeName === 'heading'
        ? headingAttrs(schema, options.level ?? parent.attrs?.level ?? 3, options.attrs ?? {})
        : (options.attrs ?? {});

    const liDepth = listItemDepth($from);
    // listItem = `paragraph block*` — không được để heading dẫn đầu bên trong li.
    // Partial selection: từ chối (caller canonical split). Whole block: lift ra ngoài list.
    if (nodeTypeName === 'heading' && liDepth > 0) {
        if (before.size > 0 || after.size > 0) {
            return false;
        }

        return changeCurrentBlockType(state, dispatch, {
            nodeType: 'heading',
            level: options.level,
            attrs: options.attrs,
        });
    }

    if (before.size === 0 && after.size === 0) {
        const tr = state.tr.setNodeMarkup(parentPos, type, {
            ...parent.attrs,
            ...nextAttrs,
        });
        dispatch?.(tr.scrollIntoView());

        return true;
    }

    const nodes = [];
    if (before.size > 0) {
        nodes.push(parent.type.create(parent.attrs, before));
    }
    nodes.push(type.create(nextAttrs, selected));
    if (after.size > 0) {
        nodes.push(paragraphType.create(null, after));
    }

    const tr = state.tr.replaceWith(parentPos, parentPos + parent.nodeSize, nodes);
    dispatch?.(tr.scrollIntoView());

    return true;
}

/**
 * Split the current paragraph at the cursor. No-op at start/end (would create empty).
 *
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @returns {boolean}
 */
export function splitParagraphAtCursor(state, dispatch) {
    const { selection } = state;
    if (!selection.empty) {
        return splitSelectionToBlockType(state, dispatch, { nodeType: 'paragraph' });
    }

    const $from = selection.$from;
    const depth = textblockDepth($from);
    if (depth === 0) {
        return false;
    }

    const parent = $from.node(depth);
    if (!isSplittableTextblock(parent)) {
        return false;
    }

    const pos = $from.pos;
    const start = $from.start(depth);
    const end = $from.end(depth);
    if (pos <= start || pos >= end) {
        return false;
    }

    const tr = state.tr.split(pos);
    dispatch?.(tr.scrollIntoView());

    return true;
}

/**
 * Convert the current textblock to heading/paragraph.
 *
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ nodeType?: 'heading'|'paragraph', level?: number, attrs?: object }} [options]
 * @returns {boolean}
 */
export function changeCurrentBlockType(state, dispatch, options = {}) {
    const { selection, schema } = state;
    const $from = selection.$from;
    const depth = textblockDepth($from);
    if (depth === 0) {
        return false;
    }

    const parent = $from.node(depth);
    if (!isSplittableTextblock(parent)) {
        return false;
    }

    const nodeTypeName = options.nodeType === 'paragraph' ? 'paragraph' : 'heading';
    const type = schema.nodes[nodeTypeName];
    if (!type) {
        return false;
    }

    const parentPos = $from.before(depth);
    const nextAttrs = nodeTypeName === 'heading'
        ? headingAttrs(schema, options.level ?? 3, options.attrs ?? {})
        : (options.attrs ?? {});

    if (parent.type === type) {
        const sameLevel = nodeTypeName !== 'heading'
            || Number(parent.attrs?.level) === Number(nextAttrs.level);
        if (sameLevel) {
            return false;
        }
    }

    const liDepth = listItemDepth($from);
    if (nodeTypeName === 'heading' && liDepth > 0) {
        return liftListItemTextblockToType(state, dispatch, {
            textblockDepth: depth,
            listItemDepth: liDepth,
            type,
            attrs: nextAttrs,
        });
    }

    const tr = state.tr.setNodeMarkup(parentPos, type, {
        ...parent.attrs,
        ...nextAttrs,
    });
    dispatch?.(tr.scrollIntoView());

    return true;
}

/**
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ headingIndex: number, text: string }} payload
 * @returns {boolean}
 */
export function renameHeadingByIndex(state, dispatch, payload) {
    const found = findHeadingByIndex(state.doc, payload.headingIndex);
    if (!found) {
        return false;
    }

    const text = String(payload.text ?? '');
    const from = found.pos + 1;
    const to = found.pos + found.node.nodeSize - 1;
    const tr = state.tr.insertText(text, from, to);
    dispatch?.(tr);

    return true;
}

/**
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ headingIndex: number, level: number }} payload
 * @returns {boolean}
 */
export function changeHeadingLevelByIndex(state, dispatch, payload) {
    const found = findHeadingByIndex(state.doc, payload.headingIndex);
    if (!found) {
        return false;
    }

    const level = Math.min(6, Math.max(1, Number(payload.level) || 2));
    if (Number(found.node.attrs?.level) === level) {
        return false;
    }

    const tr = state.tr.setNodeMarkup(found.pos, null, {
        ...found.node.attrs,
        level,
    });
    dispatch?.(tr);

    return true;
}

/**
 * Delete the heading node, keep following content.
 *
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ headingIndex: number }} payload
 * @returns {boolean}
 */
export function deleteHeadingKeepContent(state, dispatch, payload) {
    const found = findHeadingByIndex(state.doc, payload.headingIndex);
    if (!found) {
        return false;
    }

    const tr = state.tr.delete(found.pos, found.pos + found.node.nodeSize);
    dispatch?.(tr);

    return true;
}

/**
 * Delete heading + content until the next heading of the same or higher level.
 *
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ headingIndex: number }} payload
 * @returns {boolean}
 */
export function deleteHeadingWithContent(state, dispatch, payload) {
    const headings = listHeadings(state.doc);
    const found = headings.find((item) => item.index === Number(payload.headingIndex));
    if (!found) {
        return false;
    }

    const currentLevel = Number(found.node.attrs?.level) || 2;
    const next = headings.find((item) => (
        item.index > found.index && Number(item.node.attrs?.level || 2) <= currentLevel
    ));
    const from = found.pos;
    const to = next ? next.pos : state.doc.content.size;
    if (to <= from) {
        return false;
    }

    const tr = state.tr.delete(from, to);
    dispatch?.(tr);

    return true;
}

/**
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ headingIndex: number, visible: boolean }} payload
 * @returns {boolean}
 */
export function setHeadingOutlineVisible(state, dispatch, payload) {
    const found = findHeadingByIndex(state.doc, payload.headingIndex);
    if (!found) {
        return false;
    }

    const visible = payload.visible !== false;
    if (Boolean(found.node.attrs?.outlineVisible !== false) === visible) {
        return false;
    }

    const tr = state.tr.setNodeMarkup(found.pos, null, {
        ...found.node.attrs,
        outlineVisible: visible,
    });
    dispatch?.(tr);

    return true;
}

/**
 * Insert a heading (and optional empty paragraph) after the target heading's section slice.
 *
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {(tr: import('@tiptap/pm/state').Transaction) => void} [dispatch]
 * @param {{ headingIndex: number, level: number, text: string, insertParagraph?: boolean }} payload
 * @returns {boolean}
 */
export function insertHeadingAfterSection(state, dispatch, payload) {
    const headings = listHeadings(state.doc);
    const found = headings.find((item) => item.index === Number(payload.headingIndex));
    const schema = state.schema;
    const headingType = schema.nodes.heading;
    const paragraphType = schema.nodes.paragraph;
    if (!headingType || !paragraphType) {
        return false;
    }

    const text = String(payload.text ?? '').trim() || 'Heading';
    const level = Math.min(6, Math.max(2, Number(payload.level) || 3));
    const nodes = [];
    if (payload.paragraphOnly) {
        nodes.push(paragraphType.create());
    } else {
        nodes.push(headingType.create(
            headingAttrs(schema, level),
            schema.text(text),
        ));
        if (payload.insertParagraph !== false) {
            nodes.push(paragraphType.create());
        }
    }

    let insertPos = state.doc.content.size;
    if (found) {
        const parentLevel = Number(found.node.attrs?.level) || 2;
        const next = headings.find((item) => (
            item.index > found.index && Number(item.node.attrs?.level || 2) <= parentLevel
        ));
        insertPos = next ? next.pos : state.doc.content.size;
    }

    const tr = state.tr.insert(insertPos, nodes);
    dispatch?.(tr);

    return true;
}

export default {
    textblockDepth,
    listItemDepth,
    isSplittableTextblock,
    splitSelectionToBlockType,
    splitParagraphAtCursor,
    changeCurrentBlockType,
    renameHeadingByIndex,
    changeHeadingLevelByIndex,
    deleteHeadingKeepContent,
    deleteHeadingWithContent,
    setHeadingOutlineVisible,
    insertHeadingAfterSection,
    findHeadingByIndex,
    listHeadings,
};
