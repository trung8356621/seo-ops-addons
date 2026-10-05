/**
 * Chuẩn hóa câu trả lời FAQ (plain text / HTML) cho TipTap.
 */
export function answerHtmlForEditor(answer) {
    const raw = (answer ?? '').trim();
    if (raw === '') {
        return '<p></p>';
    }

    if (/<[a-z][\s\S]*>/i.test(raw)) {
        return raw;
    }

    const lines = raw.split(/\r\n|\r|\n/).map((line) => line.trim()).filter(Boolean);
    if (lines.length === 0) {
        return '<p></p>';
    }

    return lines.map((line) => `<p>${escapeHtml(line)}</p>`).join('');
}

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Normalize HTML for semantic equality comparison.
 * Collapses tag whitespace, empty paragraph variations, and break tags without losing rich formatting.
 *
 * @param {unknown} html
 * @returns {string}
 */
export function normalizeSemanticFaqHtml(html) {
    if (html == null) return '';
    return String(html)
        .replace(/\r\n|\r/g, '\n')
        .replace(/<br\s*\/?>/gi, '<br>')
        .replace(/<p>\s*(?:<br>)?\s*<\/p>/gi, '<p></p>')
        .replace(/>\s+</g, '><')
        .replace(/&nbsp;|\u00A0/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Compare two FAQ answer HTML strings semantically.
 *
 * @param {unknown} htmlA
 * @param {unknown} htmlB
 * @returns {boolean}
 */
export function isSemanticFaqHtmlEqual(htmlA, htmlB) {
    if (htmlA === htmlB) return true;
    return normalizeSemanticFaqHtml(htmlA) === normalizeSemanticFaqHtml(htmlB);
}
