/**
 * Clipboard-only outline export (H2/H3/H4 subtree). Does not mutate editor state.
 */

/**
 * Strip outline hierarchy prefixes only: `2.`, `2.1.`, `2.1.3.` at start.
 * Does not strip meaningful numbers like `2024 …` or `3.5 triệu`.
 * @param {string} text
 * @returns {string}
 */
export function stripOutlineNumberPrefix(text) {
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/^\d+(?:\.\d+)*\.\s+/u, '');
}

/**
 * @param {{ level?: number, heading_text?: string, children?: unknown[] }|null|undefined} node
 * @param {number} [depth=0]
 * @returns {string}
 */
export function formatOutlineSubtreePlainText(node, depth = 0) {
    if (!node || typeof node !== 'object') {
        return '';
    }
    const text = stripOutlineNumberPrefix(node.heading_text ?? '');
    const indent = '  '.repeat(Math.max(0, Number(depth) || 0));
    /** @type {string[]} */
    const lines = [];
    if (text !== '') {
        lines.push(`${indent}${text}`);
    }
    const children = Array.isArray(node.children) ? node.children : [];
    for (const child of children) {
        const childText = formatOutlineSubtreePlainText(child, depth + 1);
        if (childText !== '') {
            lines.push(childText);
        }
    }
    // trimEnd only — leading indent on nested lines must survive recursive joins.
    return lines.join('\n').replace(/\s+$/u, '');
}

export default {
    formatOutlineSubtreePlainText,
    stripOutlineNumberPrefix,
};
