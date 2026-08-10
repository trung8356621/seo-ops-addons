import { getMarkRange } from '@tiptap/core';
import { TextSelection } from '@tiptap/pm/state';

/**
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @returns {{ from: number, to: number }|null}
 */
export function resolveLinkMarkRange(editor) {
    if (!editor?.state || editor.isDestroyed) {
        return null;
    }

    const linkType = editor.state.schema.marks.link;
    if (!linkType) {
        return null;
    }

    const { from, to, empty, $from } = editor.state.selection;
    if (!empty && to > from) {
        return { from, to };
    }

    const range = getMarkRange($from, linkType);
    if (!range || range.to <= range.from) {
        return null;
    }

    return { from: range.from, to: range.to };
}

/**
 * Remove link mark(s); keep every character and non-link marks.
 * Collapsed caret → unlink whole current anchor (convention B).
 * Non-empty selection → remove link marks inside selection only.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @returns {boolean}
 */
export function removeLinkKeepText(editor) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const linkType = editor.state.schema.marks.link;
    if (!linkType) {
        return false;
    }

    const { empty, from, to, $from } = editor.state.selection;
    let rangeFrom = from;
    let rangeTo = to;

    if (empty) {
        if (!editor.isActive('link')) {
            return false;
        }
        const range = getMarkRange($from, linkType);
        if (!range || range.to <= range.from) {
            return false;
        }
        rangeFrom = range.from;
        rangeTo = range.to;
    } else if (!editor.isActive('link')) {
        // Selection may cover multiple links — still strip link marks in range.
    }

    const textBefore = editor.state.doc.textBetween(rangeFrom, rangeTo);

    const { tr } = editor.state;
    tr.removeMark(rangeFrom, rangeTo, linkType);
    tr.removeStoredMark(linkType);

    const caret = Math.min(Math.max(rangeFrom, rangeTo), tr.doc.content.size);
    try {
        tr.setSelection(TextSelection.create(tr.doc, caret));
    } catch {
        // keep mapped selection
    }

    editor.view.dispatch(tr.scrollIntoView());

    const textAfter = editor.state.doc.textBetween(rangeFrom, Math.min(rangeTo, editor.state.doc.content.size));
    if (textBefore !== '' && !editor.state.doc.textContent.includes(textBefore) && textAfter !== textBefore) {
        return false;
    }

    editor.view.focus();
    return true;
}

/**
 * Clear stored link mark when caret sits at the end of a link.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @returns {boolean}
 */
export function exitLinkAtBoundary(editor) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const linkType = editor.state.schema.marks.link;
    if (!linkType) {
        return false;
    }

    const { empty, $from, from } = editor.state.selection;
    if (!empty) {
        return false;
    }

    const range = getMarkRange($from, linkType);
    if (!range || from !== range.to) {
        // Also clear when already outside but stored mark still has link.
        const stored = editor.state.storedMarks;
        if (stored?.some((mark) => mark.type === linkType)) {
            const { tr } = editor.state;
            tr.removeStoredMark(linkType);
            editor.view.dispatch(tr);
            return true;
        }
        return false;
    }

    const { tr } = editor.state;
    tr.removeStoredMark(linkType);
    editor.view.dispatch(tr);
    return true;
}

/**
 * @param {import('@tiptap/core').Editor} editor
 * @param {KeyboardEvent} event
 * @returns {boolean}
 */
export function handleLinkBoundaryKeydown(editor, event) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    if (event.key !== 'ArrowRight' && event.key !== ' ') {
        return false;
    }

    const { empty, $from, from } = editor.state.selection;
    if (!empty) {
        return false;
    }

    const linkType = editor.state.schema.marks.link;
    if (!linkType || !editor.isActive('link')) {
        return false;
    }

    const range = getMarkRange($from, linkType);
    if (!range || from !== range.to) {
        return false;
    }

    if (event.key === 'ArrowRight') {
        event.preventDefault();
        const next = Math.min(from + 1, editor.state.doc.content.size);
        const { tr } = editor.state;
        tr.setSelection(TextSelection.create(tr.doc, next));
        tr.removeStoredMark(linkType);
        editor.view.dispatch(tr.scrollIntoView());
        return true;
    }

    // Space: do not preventDefault — insert space outside mark (inclusive: false).
    // Clear stored link mark in the same tick before input applies when possible.
    exitLinkAtBoundary(editor);
    return false;
}
