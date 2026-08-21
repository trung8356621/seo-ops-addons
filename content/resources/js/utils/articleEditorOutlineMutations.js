/**
 * HTML mutations for outline structure when the TipTap editor is not mounted.
 * Browser uses DOMParser; Node tests use a conservative regex fallback.
 */

import { normalizeOutlineHeadingText } from './articleEditorClientOutline.js';

const HEADING_RE = /<h([2-4])\b([^>]*)>([\s\S]*?)<\/h\1>/gi;

function headingTag(level) {
    const next = Math.min(4, Math.max(2, Number(level) || 2));

    return `h${next}`;
}

function escapeText(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function parseHeadingsRegex(html) {
    const source = String(html ?? '');
    const matches = [];
    const re = new RegExp(HEADING_RE.source, 'gi');
    let match;
    while ((match = re.exec(source)) !== null) {
        matches.push({
            index: matches.length,
            level: Number(match[1]),
            attrs: match[2] ?? '',
            inner: match[3] ?? '',
            start: match.index,
            end: match.index + match[0].length,
            full: match[0],
        });
    }

    return matches;
}

function sectionEnd(matches, headingIndex, htmlLength) {
    const current = matches[headingIndex];
    if (!current) {
        return htmlLength;
    }
    const next = matches.find((item) => (
        item.index > current.index && item.level <= current.level
    ));

    return next ? next.start : htmlLength;
}

/**
 * @param {string} html
 * @param {number} headingIndex
 * @param {string} newText
 * @returns {string}
 */
export function renameHeadingInHtml(html, headingIndex, newText) {
    const text = escapeText(normalizeOutlineHeadingText(newText));
    if (text === '') {
        return String(html ?? '');
    }

    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
        const heading = doc.body.querySelectorAll('h2, h3, h4')[headingIndex];
        if (!heading) {
            return String(html ?? '');
        }
        heading.textContent = normalizeOutlineHeadingText(newText);

        return doc.body.innerHTML;
    }

    const matches = parseHeadingsRegex(html);
    const current = matches[headingIndex];
    if (!current) {
        return String(html ?? '');
    }
    const next = `<h${current.level}${current.attrs}>${text}</h${current.level}>`;

    return String(html ?? '').slice(0, current.start) + next + String(html ?? '').slice(current.end);
}

/**
 * @param {string} html
 * @param {number} headingIndex
 * @param {number} level
 * @returns {string}
 */
export function changeHeadingLevelInHtml(html, headingIndex, level) {
    const tag = headingTag(level);

    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
        const heading = doc.body.querySelectorAll('h2, h3, h4')[headingIndex];
        if (!heading) {
            return String(html ?? '');
        }
        const next = doc.createElement(tag);
        Array.from(heading.attributes).forEach((attr) => next.setAttribute(attr.name, attr.value));
        next.innerHTML = heading.innerHTML;
        heading.replaceWith(next);

        return doc.body.innerHTML;
    }

    const matches = parseHeadingsRegex(html);
    const current = matches[headingIndex];
    if (!current) {
        return String(html ?? '');
    }
    const next = `<${tag}${current.attrs}>${current.inner}</${tag}>`;

    return String(html ?? '').slice(0, current.start) + next + String(html ?? '').slice(current.end);
}

function wrapParagraphInner(inner, kind) {
    if (kind === 'bold') {
        return `<p><strong>${inner}</strong></p>`;
    }
    if (kind === 'italic') {
        return `<p><em>${inner}</em></p>`;
    }

    return `<p>${inner}</p>`;
}

/**
 * Convert a heading to another heading level or a paragraph (optionally fully marked).
 *
 * @param {string} html
 * @param {number} headingIndex
 * @param {'h2'|'h3'|'h4'|'paragraph'|'bold'|'italic'} kind
 * @returns {string}
 */
export function convertHeadingInHtml(html, headingIndex, kind) {
    const nextKind = String(kind ?? '');
    if (nextKind === 'h2' || nextKind === 'h3' || nextKind === 'h4') {
        return changeHeadingLevelInHtml(html, headingIndex, Number(nextKind.slice(1)));
    }

    if (!['paragraph', 'bold', 'italic'].includes(nextKind)) {
        return String(html ?? '');
    }

    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
        const heading = doc.body.querySelectorAll('h2, h3, h4')[headingIndex];
        if (!heading) {
            return String(html ?? '');
        }
        const paragraph = doc.createElement('p');
        if (nextKind === 'paragraph') {
            paragraph.innerHTML = heading.innerHTML;
        } else {
            const mark = doc.createElement(nextKind === 'bold' ? 'strong' : 'em');
            mark.innerHTML = heading.innerHTML;
            paragraph.appendChild(mark);
        }
        heading.replaceWith(paragraph);

        return doc.body.innerHTML;
    }

    const matches = parseHeadingsRegex(html);
    const current = matches[headingIndex];
    if (!current) {
        return String(html ?? '');
    }
    const next = wrapParagraphInner(current.inner, nextKind);

    return String(html ?? '').slice(0, current.start) + next + String(html ?? '').slice(current.end);
}

/**
 * @param {string} html
 * @param {number} headingIndex
 * @returns {string}
 */
export function deleteHeadingKeepContentInHtml(html, headingIndex) {
    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
        const heading = doc.body.querySelectorAll('h2, h3, h4')[headingIndex];
        if (!heading) {
            return String(html ?? '');
        }
        heading.remove();
        const next = doc.body.innerHTML.trim();

        return next !== '' ? next : '<p></p>';
    }

    const matches = parseHeadingsRegex(html);
    const current = matches[headingIndex];
    if (!current) {
        return String(html ?? '');
    }
    const next = String(html ?? '').slice(0, current.start) + String(html ?? '').slice(current.end);
    const trimmed = next.trim();

    return trimmed !== '' ? trimmed : '<p></p>';
}

/**
 * @param {string} html
 * @param {number} headingIndex
 * @returns {string}
 */
export function deleteHeadingWithContentInHtml(html, headingIndex) {
    const source = String(html ?? '');
    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(source, 'text/html');
        const headings = Array.from(doc.body.querySelectorAll('h2, h3, h4'));
        const heading = headings[headingIndex];
        if (!heading) {
            return source;
        }
        const level = Number(heading.tagName.charAt(1));
        const nodes = [];
        let cursor = heading;
        while (cursor) {
            const next = cursor.nextSibling;
            if (cursor !== heading && cursor.nodeType === 1 && /^H[2-4]$/i.test(cursor.tagName)) {
                const nextLevel = Number(cursor.tagName.charAt(1));
                if (nextLevel <= level) {
                    break;
                }
            }
            nodes.push(cursor);
            cursor = next;
        }
        nodes.forEach((node) => node.remove());
        const nextHtml = doc.body.innerHTML.trim();

        return nextHtml !== '' ? nextHtml : '<p></p>';
    }

    const matches = parseHeadingsRegex(source);
    const current = matches[headingIndex];
    if (!current) {
        return source;
    }
    const end = sectionEnd(matches, headingIndex, source.length);
    const next = (source.slice(0, current.start) + source.slice(end)).trim();

    return next !== '' ? next : '<p></p>';
}

/**
 * @param {string} html
 * @param {number} headingIndex
 * @param {boolean} visible
 * @returns {string}
 */
export function setOutlineVisibleInHtml(html, headingIndex, visible) {
    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
        const heading = doc.body.querySelectorAll('h2, h3, h4')[headingIndex];
        if (!heading) {
            return String(html ?? '');
        }
        if (visible === false) {
            heading.setAttribute('data-outline-visible', 'false');
        } else {
            heading.removeAttribute('data-outline-visible');
        }

        return doc.body.innerHTML;
    }

    const matches = parseHeadingsRegex(html);
    const current = matches[headingIndex];
    if (!current) {
        return String(html ?? '');
    }
    let attrs = current.attrs.replace(/\sdata-outline-visible\s*=\s*(['"]).*?\1/i, '');
    if (visible === false) {
        attrs += ' data-outline-visible="false"';
    }
    const next = `<h${current.level}${attrs}>${current.inner}</h${current.level}>`;

    return String(html ?? '').slice(0, current.start) + next + String(html ?? '').slice(current.end);
}

/**
 * @param {string} html
 * @param {{ headingIndex?: number, level?: number, text?: string, paragraph?: boolean }} payload
 * @returns {string}
 */
export function insertAfterHeadingSectionInHtml(html, payload = {}) {
    const source = String(html ?? '');
    const level = Math.min(4, Math.max(2, Number(payload.level) || 3));
    const text = escapeText(normalizeOutlineHeadingText(payload.text) || 'Heading');
    const paragraphOnly = payload.paragraph === true;
    const insert = paragraphOnly
        ? '<p></p>'
        : `<${headingTag(level)}>${text}</${headingTag(level)}><p></p>`;
    const headingIndex = Number(payload.headingIndex);

    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(source || '<p></p>', 'text/html');
        const headings = Array.from(doc.body.querySelectorAll('h2, h3, h4'));
        const heading = Number.isFinite(headingIndex) ? headings[headingIndex] : null;
        if (!heading) {
            doc.body.insertAdjacentHTML('beforeend', insert);

            return doc.body.innerHTML;
        }

        // Intro paragraph must sit directly under the heading, before H3/table/body.
        if (paragraphOnly) {
            heading.insertAdjacentHTML('afterend', insert);

            return doc.body.innerHTML;
        }

        const currentLevel = Number(heading.tagName.charAt(1));
        let last = heading;
        let cursor = heading.nextSibling;
        while (cursor) {
            if (cursor.nodeType === 1 && /^H[2-4]$/i.test(cursor.tagName)) {
                const nextLevel = Number(cursor.tagName.charAt(1));
                if (nextLevel <= currentLevel) {
                    break;
                }
            }
            last = cursor;
            cursor = cursor.nextSibling;
        }
        last.insertAdjacentHTML('afterend', insert);

        return doc.body.innerHTML;
    }

    const matches = parseHeadingsRegex(source);
    if (!Number.isFinite(headingIndex) || !matches[headingIndex]) {
        return `${source}${insert}`;
    }
    if (paragraphOnly) {
        const current = matches[headingIndex];

        return source.slice(0, current.end) + insert + source.slice(current.end);
    }
    const end = sectionEnd(matches, headingIndex, source.length);

    return source.slice(0, end) + insert + source.slice(end);
}

export default {
    renameHeadingInHtml,
    changeHeadingLevelInHtml,
    convertHeadingInHtml,
    deleteHeadingKeepContentInHtml,
    deleteHeadingWithContentInHtml,
    setOutlineVisibleInHtml,
    insertAfterHeadingSectionInHtml,
};
