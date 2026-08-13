import { normalizeLinkText } from './articleLinkScroll';
import {
    enclosingAnchorForPlainTextRange,
    findPlainTextRangeInRoot,
    wrapTextRangeWithLink,
} from './articlePlainTextRange';
import { SEO_EDITOR_LINK_CLASS, stripEditorTransientMarkup } from './articleEditorTransientMarkup';
import { normalizeInlineLinks, SEO_LINK_DEFAULT_ATTRS } from './inlineLinkNormalizer';

/**
 * Thay lần xuất hiện đầu tiên của searchText bằng text thuần (không link).
 *
 * @param {string} html
 * @param {string} searchText
 * @param {string} insertText
 * @returns {{ html: string, replaced: boolean }}
 */
export function replaceFirstPlainTextWithText(html, searchText, insertText) {
    const target = normalizeLinkText(searchText);
    const value = String(insertText ?? '').trim();

    if (!target || !value || !html) {
        return { html, replaced: false };
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const body = doc.body;
    const match = findPlainTextRangeInRoot(body, target, 0);

    if (!match) {
        return { html, replaced: false };
    }

    const range = doc.createRange();
    range.setStart(match.node, match.start);
    range.setEnd(match.endNode, match.endOffset);

    try {
        range.deleteContents();
        range.insertNode(doc.createTextNode(value));

        return {
            html: normalizeInlineLinks(stripEditorTransientMarkup(body.innerHTML)),
            replaced: true,
        };
    } catch {
        return { html, replaced: false };
    }
}

/**
 * Thay lần xuất hiện đầu tiên của searchText bằng link có label cố định (dùng khi user bôi đen rồi chèn CTA).
 *
 * @param {string} html
 * @param {string} searchText
 * @param {string} label
 * @param {string} href
 * @returns {{ html: string, replaced: boolean }}
 */
export function replaceFirstPlainTextWithLink(html, searchText, label, href) {
    const target = normalizeLinkText(searchText);
    const linkLabel = String(label ?? '').trim();
    const url = String(href ?? '').trim();

    if (!target || !linkLabel || !url || !html) {
        return { html, replaced: false };
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const body = doc.body;
    const match = findPlainTextRangeInRoot(body, target, 0);

    if (!match) {
        return { html, replaced: false };
    }

    const range = doc.createRange();
    range.setStart(match.node, match.start);
    range.setEnd(match.endNode, match.endOffset);

    const anchor = doc.createElement('a');
    anchor.href = url;
    anchor.className = SEO_EDITOR_LINK_CLASS;
    if (SEO_LINK_DEFAULT_ATTRS.target) {
        anchor.target = SEO_LINK_DEFAULT_ATTRS.target;
    }
    if (SEO_LINK_DEFAULT_ATTRS.rel) {
        anchor.rel = SEO_LINK_DEFAULT_ATTRS.rel;
    }
    anchor.textContent = linkLabel;

    try {
        range.deleteContents();
        range.insertNode(anchor);

        return {
            html: normalizeInlineLinks(stripEditorTransientMarkup(body.innerHTML)),
            replaced: true,
        };
    } catch {
        return { html, replaced: false };
    }
}

/**
 * Tìm cụm từ trong các block editor và bọc link tại vị trí khớp đầu tiên.
 *
 * @param {Array<{ id: string, type?: string, content?: string }>} blocks
 * @param {string} phrase
 * @param {string} href
 * @returns {{ blockId: string, html: string } | null}
 */
export function wrapFirstPlainTextWithLinkInBlocks(blocks, phrase, href) {
    return wrapPlainTextWithLinkInBlocks(blocks, phrase, href, 0);
}

export function countEligiblePlainTextOccurrences(html, phrase) {
    const target = normalizeLinkText(phrase);
    if (!target || !html) {
        return 0;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    let count = 0;

    for (let index = 0; ; index += 1) {
        if (!findPlainTextRangeInRoot(doc.body, target, index)) {
            break;
        }
        count += 1;
    }

    return count;
}

/**
 * Count occurrences including text already inside <a> (Vocabulary / scroll index alignment).
 *
 * @param {string} html
 * @param {string} phrase
 */
export function countAllPhraseOccurrences(html, phrase) {
    const target = normalizeLinkText(phrase);
    if (!target || !html) {
        return 0;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    let count = 0;

    for (let index = 0; ; index += 1) {
        if (!findPlainTextRangeInRoot(doc.body, target, index, { includeLinkedText: true })) {
            break;
        }
        count += 1;
    }

    return count;
}

/**
 * Wrap plain text OR update href when the occurrence is already linked.
 *
 * @param {string} html
 * @param {string} phrase
 * @param {string} href
 * @param {number} [occurrenceIndex]
 * @returns {{ html: string, replaced: boolean }}
 */
export function applyLinkToPhraseOccurrence(html, phrase, href, occurrenceIndex = 0) {
    const target = normalizeLinkText(phrase);
    const url = String(href ?? '').trim();
    const matchIndex = Math.max(0, Number(occurrenceIndex) || 0);

    if (!target || !url || !html) {
        return { html, replaced: false };
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const body = doc.body;
    const match = findPlainTextRangeInRoot(body, target, matchIndex, { includeLinkedText: true });

    if (!match) {
        return { html, replaced: false };
    }

    const existing = enclosingAnchorForPlainTextRange(match);
    if (existing) {
        existing.setAttribute('href', url);
        if (!existing.classList.contains(SEO_EDITOR_LINK_CLASS)) {
            existing.classList.add(SEO_EDITOR_LINK_CLASS);
        }
        if (SEO_LINK_DEFAULT_ATTRS.target && !existing.getAttribute('target')) {
            existing.setAttribute('target', SEO_LINK_DEFAULT_ATTRS.target);
        }
        if (SEO_LINK_DEFAULT_ATTRS.rel && !existing.getAttribute('rel')) {
            existing.setAttribute('rel', SEO_LINK_DEFAULT_ATTRS.rel);
        }

        return {
            html: normalizeInlineLinks(stripEditorTransientMarkup(body.innerHTML)),
            replaced: true,
        };
    }

    const ok = wrapTextRangeWithLink(doc, match, url);

    return {
        html: normalizeInlineLinks(stripEditorTransientMarkup(body.innerHTML)),
        replaced: ok,
    };
}

/**
 * @param {string} html
 * @param {string} phrase
 * @param {string} href
 * @param {number} [occurrenceIndex]
 * @returns {{ html: string, replaced: boolean }}
 */
export function wrapPlainTextWithLink(html, phrase, href, occurrenceIndex = 0) {
    const target = normalizeLinkText(phrase);
    const url = String(href ?? '').trim();
    const matchIndex = Math.max(0, Number(occurrenceIndex) || 0);

    if (!target || !url || !html) {
        return { html, replaced: false };
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const body = doc.body;
    const match = findPlainTextRangeInRoot(body, target, matchIndex);

    if (!match) {
        return { html, replaced: false };
    }

    const ok = wrapTextRangeWithLink(doc, match, url);

    return {
        html: normalizeInlineLinks(stripEditorTransientMarkup(body.innerHTML)),
        replaced: ok,
    };
}

export function wrapFirstPlainTextWithLink(html, phrase, href) {
    return wrapPlainTextWithLink(html, phrase, href, 0);
}

/**
 * @param {Array<{ id: string, type?: string, content?: string }>} blocks
 * @param {string} phrase
 * @param {string} href
 * @param {number} [occurrenceIndex]
 * @returns {{ blockId: string, html: string } | null}
 */
export function wrapPlainTextWithLinkInBlocks(blocks, phrase, href, occurrenceIndex = 0) {
    if (!Array.isArray(blocks) || blocks.length === 0) {
        return null;
    }

    let remaining = Math.max(0, Number(occurrenceIndex) || 0);

    for (const block of blocks) {
        if (block?.type === 'image' || !block?.content) {
            continue;
        }

        const count = countEligiblePlainTextOccurrences(block.content, phrase);
        if (count <= 0) {
            continue;
        }

        if (remaining < count) {
            const { html, replaced } = wrapPlainTextWithLink(block.content, phrase, href, remaining);
            if (replaced) {
                return { blockId: block.id, html };
            }

            return null;
        }

        remaining -= count;
    }

    return null;
}

/**
 * Block-scoped apply (Vocabulary): local matchIndex within one block, linked text allowed.
 *
 * @param {string} blockHtml
 * @param {string} phrase
 * @param {string} href
 * @param {number} [occurrenceIndex]
 * @returns {{ html: string, replaced: boolean }}
 */
export function applyLinkToPhraseOccurrenceInBlockHtml(blockHtml, phrase, href, occurrenceIndex = 0) {
    return applyLinkToPhraseOccurrence(blockHtml, phrase, href, occurrenceIndex);
}
