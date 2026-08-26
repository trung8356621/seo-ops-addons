/**
 * TipTap/ProseMirror HTML parse defaults to preserveWhitespace=false which can
 * drop spaces around <strong>/<em>/<a> on hydrate. Always pass FULL for HTML.
 *
 * IMPORTANT: preserveWhitespace:'full' also promotes literal newlines inside
 * phrasing content into hardBreak nodes. HTML soft breaks (CommonMark single \n
 * inside a <p>) must be collapsed to spaces before TipTap parse — without
 * touching explicit <br> elements.
 */

export const TIPTAP_HTML_PARSE_OPTIONS = Object.freeze({
    preserveWhitespace: 'full',
});

const SOFT_NEWLINE_SKIP_TAGS = new Set(['PRE', 'CODE', 'SCRIPT', 'STYLE', 'KBD', 'SAMP', 'TEXTAREA']);

/**
 * Collapse soft newlines in text nodes to spaces (HTML phrasing semantics).
 * Preserves <br> / structure; does not merge paragraphs or lists.
 *
 * @param {string} html
 * @returns {string}
 */
export function collapseHtmlSoftNewlines(html) {
    const raw = String(html ?? '');
    if (raw === '' || !/[\r\n]/.test(raw)) {
        return raw;
    }

    if (typeof DOMParser === 'undefined') {
        // Node/phpunit source contracts — only collapse newlines outside tags.
        return raw.replace(/>([^<]*)</g, (full, text) => `>${String(text).replace(/\r\n|\r|\n/g, ' ')}<`);
    }

    const doc = new DOMParser().parseFromString(`<div id="omi-soft-nl-root">${raw}</div>`, 'text/html');
    const root = doc.getElementById('omi-soft-nl-root');
    if (!root) {
        return raw;
    }

    const walk = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = String(node.textContent ?? '');
            if (/[\r\n]/.test(text)) {
                node.textContent = text.replace(/\r\n|\r|\n/g, ' ');
            }
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }
        const el = /** @type {Element} */ (node);
        if (SOFT_NEWLINE_SKIP_TAGS.has(el.tagName)) {
            return;
        }
        Array.from(el.childNodes).forEach((child) => walk(child));
    };

    walk(root);

    return root.innerHTML;
}

/**
 * @param {string} value
 * @returns {string}
 */
export function semanticPlainText(value) {
    return String(value ?? '')
        .replace(/\u00a0/g, ' ')
        .replace(/\s+/gu, ' ')
        .trim();
}

/**
 * Letters/digits only — used to detect space-only divergence.
 *
 * @param {string} value
 * @returns {string}
 */
export function stripAllWhitespace(value) {
    return String(value ?? '').replace(/\s+/gu, '');
}

/**
 * Count spaces that sit between word characters (mark-boundary candidates).
 *
 * @param {string} value
 * @returns {number}
 */
export function countInterWordSpaces(value) {
    const text = String(value ?? '');
    const matches = text.match(/[\p{L}\p{N}]\s+[\p{L}\p{N}]/gu);

    return matches ? matches.length : 0;
}

/**
 * True when candidate lost multiple inter-word spaces vs base while letters match.
 * Used for bootstrap prefer-body and save corruption guard.
 *
 * @param {string} basePlain
 * @param {string} candidatePlain
 * @param {{ minLostSpaces?: number }} [options]
 * @returns {boolean}
 */
export function hasInlineWhitespaceCorruption(basePlain, candidatePlain, options = {}) {
    const minLost = Math.max(2, Number(options.minLostSpaces) || 2);
    const base = semanticPlainText(basePlain);
    const candidate = semanticPlainText(candidatePlain);
    if (base === '' || candidate === '') {
        return false;
    }
    if (stripAllWhitespace(base) !== stripAllWhitespace(candidate)) {
        return false;
    }
    if (base === candidate) {
        return false;
    }

    const lost = countInterWordSpaces(base) - countInterWordSpaces(candidate);

    return lost >= minLost;
}

/**
 * PHP-callable mirror helpers stay in the Unit test; this is the client SoT.
 *
 * @param {string} html
 * @returns {string}
 */
export function plainTextFromHtmlLoose(html) {
    const source = String(html ?? '');
    if (source.trim() === '') {
        return '';
    }
    try {
        if (typeof DOMParser === 'undefined') {
            return semanticPlainText(source.replace(/<[^>]+>/g, ' '));
        }
        const doc = new DOMParser().parseFromString(source, 'text/html');

        return semanticPlainText(doc.body?.textContent || '');
    } catch {
        return semanticPlainText(source.replace(/<[^>]+>/g, ' '));
    }
}

export const INLINE_WHITESPACE_CORRUPTION_CODE = 'inline_whitespace_corruption_detected';

const MARK_OPEN_RE = /[\p{L}\p{N}]<(?:strong|b|em|i|a|u|s|code|mark)\b/giu;
const MARK_CLOSE_RE = /<\/(?:strong|b|em|i|a|u|s|code|mark)>[\p{L}\p{N}]/giu;

/**
 * Count word-char glued directly to inline mark open/close tags.
 * Absolute signal — works even when DB body already lost spaces.
 *
 * @param {string} html
 * @returns {number}
 */
export function countGluedInlineMarkBoundaries(html) {
    const source = String(html ?? '');
    if (source === '') {
        return 0;
    }
    const open = source.match(MARK_OPEN_RE);
    const close = source.match(MARK_CLOSE_RE);

    return (open ? open.length : 0) + (close ? close.length : 0);
}

/**
 * Surgical repair: insert one space only where word-char is glued to mark tags.
 * Does not add space before punctuation (</strong>, stays).
 *
 * @param {string} html
 * @returns {string}
 */
export function repairGluedInlineMarkBoundaryWhitespace(html) {
    const source = String(html ?? '');
    if (source === '' || countGluedInlineMarkBoundaries(source) === 0) {
        return source;
    }

    return source
        .replace(/([\p{L}\p{N}])(<(?:strong|b|em|i|a|u|s|code|mark)\b)/giu, '$1 $2')
        .replace(/(<\/(?:strong|b|em|i|a|u|s|code|mark)>)([\p{L}\p{N}])/giu, '$1 $2');
}

/**
 * @param {string} html
 * @returns {{ html: string, repaired: boolean, gluedBefore: number, gluedAfter: number }}
 */
export function repairGluedInlineMarkBoundaryWhitespaceWithReport(html) {
    const source = String(html ?? '');
    const gluedBefore = countGluedInlineMarkBoundaries(source);
    if (gluedBefore === 0) {
        return { html: source, repaired: false, gluedBefore: 0, gluedAfter: 0 };
    }
    const next = repairGluedInlineMarkBoundaryWhitespace(source);

    return {
        html: next,
        repaired: next !== source,
        gluedBefore,
        gluedAfter: countGluedInlineMarkBoundaries(next),
    };
}
