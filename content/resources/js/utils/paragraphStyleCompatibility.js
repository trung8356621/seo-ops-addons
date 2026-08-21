/**
 * Heading conversion (P ↔ H2–H6) only for plain textblocks — never flatten table/list structure.
 */

/**
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @returns {boolean}
 */
export function selectionIsInsideStructuredBlock(editor) {
    if (!editor || editor.isDestroyed || !editor.state) {
        return false;
    }

    try {
        if (
            editor.isActive('table')
            || editor.isActive('tableCell')
            || editor.isActive('tableHeader')
            || editor.isActive('tableRow')
            || editor.isActive('bulletList')
            || editor.isActive('orderedList')
            || editor.isActive('listItem')
            || editor.isActive('codeBlock')
            || editor.isActive('blockquote')
            || editor.isActive('image')
            || editor.isActive('horizontalRule')
        ) {
            return true;
        }
    } catch {
        // TipTap may throw when extension missing — fall through to depth walk.
    }

    const $from = editor.state.selection?.$from;
    if (!$from) {
        return false;
    }

    for (let depth = $from.depth; depth > 0; depth -= 1) {
        const name = String($from.node(depth)?.type?.name ?? '');
        if (
            name === 'table'
            || name === 'tableCell'
            || name === 'tableHeader'
            || name === 'tableRow'
            || name === 'bulletList'
            || name === 'orderedList'
            || name === 'listItem'
            || name === 'codeBlock'
            || name === 'blockquote'
            || name === 'image'
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @returns {boolean}
 */
export function selectionIsHeadingCompatibleTextblock(editor) {
    if (!editor || editor.isDestroyed || !editor.state) {
        return false;
    }

    const $from = editor.state.selection?.$from;
    if (!$from) {
        return false;
    }

    const parent = $from.parent;
    const name = String(parent?.type?.name ?? '');
    if (name !== 'paragraph' && name !== 'heading') {
        return false;
    }

    return !selectionIsInsideStructuredBlock(editor);
}

/**
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} value style value: p|pre|h1…h6
 * @returns {boolean}
 */
export function canApplyParagraphStyle(editor, value) {
    const style = String(value ?? 'p').trim().toLowerCase();
    if (!style || !editor || editor.isDestroyed || !editor.state) {
        return false;
    }

    const $from = editor.state.selection?.$from;
    const parentName = String($from?.parent?.type?.name ?? '');

    if (style === 'p') {
        // Allow recovering a heading textblock back to paragraph (including inside cells).
        return parentName === 'paragraph' || parentName === 'heading';
    }

    if (style === 'pre') {
        if (parentName === 'codeBlock') {
            return true;
        }

        return (parentName === 'paragraph' || parentName === 'heading')
            && !selectionIsInsideStructuredBlock(editor);
    }

    if (!/^h[1-6]$/.test(style)) {
        return false;
    }

    return selectionIsHeadingCompatibleTextblock(editor);
}

export default {
    selectionIsInsideStructuredBlock,
    selectionIsHeadingCompatibleTextblock,
    canApplyParagraphStyle,
};
