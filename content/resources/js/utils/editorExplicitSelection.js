/**
 * Authoritative detector: user explicitly selected text inside the article editor.
 * Caret / frozen bookmark / browser Find / sidebar selection do NOT count.
 */

const EDITOR_PROSE_SELECTOR = '[data-seo-block-id] .ProseMirror, .ProseMirror[data-seo-block-id]';

/**
 * @param {Node|null|undefined} node
 * @returns {Element|null}
 */
function closestEditorProse(node) {
    if (!node) {
        return null;
    }
    const el = node.nodeType === 1 ? node : node.parentElement;
    return el?.closest?.(EDITOR_PROSE_SELECTOR) ?? null;
}

/**
 * Live DOM selection inside a TipTap/ProseMirror editor surface.
 *
 * @returns {{ text: string, blockId: string }|null}
 */
export function readLiveEditorTextSelection() {
    if (typeof window === 'undefined' || typeof window.getSelection !== 'function') {
        return null;
    }

    const selection = window.getSelection();
    if (!selection || selection.isCollapsed || selection.rangeCount < 1) {
        return null;
    }

    const text = String(selection.toString() ?? '').replace(/\s+/g, ' ').trim();
    if (text === '') {
        return null;
    }

    const range = selection.getRangeAt(0);
    const prose = closestEditorProse(range.commonAncestorContainer);
    if (!prose) {
        return null;
    }

    const blockHost = prose.closest('[data-seo-block-id]');
    const blockId = String(blockHost?.getAttribute('data-seo-block-id') ?? '').trim();

    return { text, blockId };
}

/**
 * True only for a real, non-collapsed user text selection inside the article editor.
 *
 * @returns {boolean}
 */
export function hasExplicitEditorTextSelection() {
    return readLiveEditorTextSelection() !== null;
}
