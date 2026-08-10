/** Tool shortcuts (Ctrl+Shift to avoid browser conflicts). */
export const TOOL_SHORTCUT_LABELS = {
    brush: 'Ctrl+Shift+B',
    rect: 'Ctrl+Shift+M',
    ellipse: 'Ctrl+Shift+O',
    polygon: 'Ctrl+Shift+P',
    eyedropper: 'Ctrl+Shift+I',
};

/**
 * @param {KeyboardEvent} e
 * @returns {'brush'|'rect'|'ellipse'|'polygon'|'eyedropper'|null}
 */
export function toolFromKeyboardEvent(e) {
    const mod = e.ctrlKey || e.metaKey;
    if (!mod || !e.shiftKey || e.altKey) {
        return null;
    }

    const key = e.key.toLowerCase();

    if (key === 'b') {
        return 'brush';
    }
    if (key === 'm' || key === 'r') {
        return 'rect';
    }
    if (key === 'o') {
        return 'ellipse';
    }
    if (key === 'p' || key === 'l') {
        return 'polygon';
    }
    if (key === 'i') {
        return 'eyedropper';
    }

    return null;
}

/** Shortcut list displayed in panel. */
export const MAGIC_ERASER_SHORTCUT_GROUPS = [
    {
        id: 'navigate',
        label: 'Move & zoom',
        items: [
            { keys: ['Space'], desc: 'Hold + drag to pan image' },
            { keys: ['H'], desc: 'Hold to pan image (hand)' },
            { keys: ['Scroll'], desc: 'Pan without holding Ctrl' },
            { keys: ['Ctrl', 'Scroll'], desc: 'Zoom at cursor position' },
            { keys: ['Ctrl', '+'], desc: 'Zoom in' },
            { keys: ['Ctrl', '−'], desc: 'Zoom out' },
            { keys: ['Ctrl', '0'], desc: 'Fit view' },
        ],
    },
    {
        id: 'tools',
        label: 'Tools',
        items: [
            { keys: ['Ctrl', 'Shift', 'B'], desc: 'Brush selection' },
            { keys: ['Ctrl', 'Shift', 'M'], desc: 'Rectangle' },
            { keys: ['Ctrl', 'Shift', 'O'], desc: 'Circle / ellipse' },
            { keys: ['Ctrl', 'Shift', 'P'], desc: 'Polygon' },
            { keys: ['Ctrl', 'Shift', 'I'], desc: 'Eyedropper' },
            { keys: ['['], desc: 'Decrease brush size' },
            { keys: [']'], desc: 'Increase brush size' },
        ],
    },
    {
        id: 'edit',
        label: 'Edit',
        items: [
            { keys: ['Enter'], desc: 'Fill selection / close polygon' },
            { keys: ['Ctrl', 'D'], desc: 'Clear selection (mask)' },
            { keys: ['Ctrl', 'Z'], desc: 'Undo' },
            { keys: ['Ctrl', 'Y'], desc: 'Redo' },
            { keys: ['Ctrl', 'Shift', 'Z'], desc: 'Redo (alternative)' },
            { keys: ['Backspace'], desc: 'Remove last point (polygon)' },
            { keys: ['Esc'], desc: 'Cancel polygon / close editor' },
        ],
    },
    {
        id: 'file',
        label: 'File',
        items: [
            { keys: ['Ctrl', 'S'], desc: 'Save image' },
        ],
    },
];
