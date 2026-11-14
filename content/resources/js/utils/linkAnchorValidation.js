/**
 * Anchor validation for Link Picker / create_link — trim edges only; never
 * strip Vietnamese diacritics or rewrite document text outside the range.
 */

/**
 * @param {string} text
 * @returns {string}
 */
export function normalizeAnchorWhitespace(text) {
    return String(text ?? '')
        .replace(/\s+/gu, ' ')
        .trim();
}

/**
 * Reject empty / punctuation-only / whitespace-only anchors.
 * @param {string} text
 * @returns {boolean}
 */
export function isMeaningfulAnchorText(text) {
    const normalized = normalizeAnchorWhitespace(text);
    if (normalized === '') {
        return false;
    }

    return /[\p{L}\p{N}]/u.test(normalized);
}

/**
 * Shrink a PM range so leading/trailing whitespace stays outside the link.
 * Does not mutate the document.
 *
 * @param {import('@tiptap/pm/model').Node} doc
 * @param {number} from
 * @param {number} to
 * @returns {{ from: number, to: number }|null}
 */
export function trimSelectionWhitespaceEdges(doc, from, to) {
    if (!doc || typeof from !== 'number' || typeof to !== 'number') {
        return null;
    }

    const size = doc.content.size;
    let start = Math.max(0, Math.min(from, size));
    let end = Math.max(start, Math.min(to, size));
    if (start === end) {
        return null;
    }

    while (start < end) {
        const ch = doc.textBetween(start, start + 1, '', '');
        if (ch !== '' && !/^\s$/u.test(ch)) {
            break;
        }
        start += 1;
    }

    while (end > start) {
        const ch = doc.textBetween(end - 1, end, '', '');
        if (ch !== '' && !/^\s$/u.test(ch)) {
            break;
        }
        end -= 1;
    }

    if (start >= end) {
        return null;
    }

    return { from: start, to: end };
}

/**
 * Resolve a linkable selection on the active editor.
 * Empty selection inside an existing link is allowed (edit href only).
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @returns {{ from: number, to: number, text: string, empty: boolean }|null}
 */
export function resolveLinkableSelectionRange(editor) {
    if (!editor?.state || editor.isDestroyed) {
        return null;
    }

    const { from, to, empty } = editor.state.selection;
    if (empty) {
        if (editor.isActive('link')) {
            return { from, to, text: '', empty: true };
        }

        return null;
    }

    const trimmed = trimSelectionWhitespaceEdges(editor.state.doc, from, to);
    if (!trimmed) {
        return null;
    }

    const text = editor.state.doc.textBetween(trimmed.from, trimmed.to, ' ');
    if (!isMeaningfulAnchorText(text)) {
        return null;
    }

    return {
        from: trimmed.from,
        to: trimmed.to,
        text: normalizeAnchorWhitespace(text),
        empty: false,
    };
}
