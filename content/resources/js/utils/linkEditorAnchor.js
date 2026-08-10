/**
 * @param {import('@tiptap/core').Editor} editor
 * @returns {DOMRect|null}
 */
export function resolveLinkEditorAnchorRect(editor) {
    if (!editor?.view) {
        return null;
    }

    if (editor.isActive('link')) {
        const { from } = editor.state.selection;
        const domAt = editor.view.domAtPos(from);
        const el = domAt.node instanceof Element ? domAt.node : domAt.node?.parentElement;
        const anchor = el?.closest?.('a');
        if (anchor) {
            return anchor.getBoundingClientRect();
        }
    }

    const selection = window.getSelection();
    if (
        selection &&
        selection.rangeCount > 0 &&
        editor.view.dom.contains(selection.anchorNode)
    ) {
        const rangeRect = selection.getRangeAt(0).getBoundingClientRect();
        if (rangeRect.width > 0 || rangeRect.height > 0) {
            return rangeRect;
        }
    }

    const { from, to, empty } = editor.state.selection;
    const start = editor.view.coordsAtPos(from);
    const end = editor.view.coordsAtPos(empty ? from : to);

    const top = Math.min(start.top, end.top);
    const left = Math.min(start.left, end.left);
    const bottom = Math.max(start.bottom, end.bottom);
    const right = Math.max(start.right, end.right);

    return {
        top,
        left,
        bottom,
        right,
        width: Math.max(0, right - left),
        height: Math.max(0, bottom - top),
        x: left,
        y: top,
        toJSON: () => ({}),
    };
}

/**
 * @param {DOMRect} anchorRect viewport rect
 * @param {HTMLElement} container
 * @param {{ width: number, height: number }} panelSize
 */
export function computeLinkBubblePosition(anchorRect, container, panelSize) {
    const containerRect = container.getBoundingClientRect();
    const gap = 8;
    const padding = 8;

    const relTop = anchorRect.top - containerRect.top;
    const relBottom = anchorRect.bottom - containerRect.top;
    const relLeft = anchorRect.left - containerRect.left;

    const maxTop = Math.max(padding, container.clientHeight - panelSize.height - padding);
    let top = relBottom + gap;
    if (top + panelSize.height > container.clientHeight - padding && relTop - panelSize.height - gap >= padding) {
        top = relTop - panelSize.height - gap;
    }
    top = Math.max(padding, Math.min(top, maxTop));

    const maxLeft = Math.max(padding, container.clientWidth - panelSize.width - padding);
    const left = Math.max(padding, Math.min(relLeft, maxLeft));

    return { top, left };
}
