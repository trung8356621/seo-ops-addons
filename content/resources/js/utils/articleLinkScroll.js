import { enclosingAnchorForPlainTextRange, findPlainTextRangeInRoot } from './articlePlainTextRange';
import { normalizeLinkText } from './articleLinkTextNormalize';
import {
    SEO_EDITOR_LINK_MARK_CLASS,
    SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS,
} from './articleEditorTransientMarkup';

export { normalizeLinkText };

/**
 * Tìm block chứa offset trong HTML export (join \n\n).
 *
 * @param {Array<{ id: string, content?: string, prefix?: string, suffix?: string }>} blocks
 * @param {number} offset
 */
export function findBlockIdForExportOffset(blocks, offset) {
    if (typeof offset !== 'number' || offset < 0) {
        return null;
    }

    let pos = 0;

    for (let i = 0; i < blocks.length; i++) {
        const block = blocks[i];
        const part =
            block.prefix || block.suffix
                ? [block.prefix, block.content, block.suffix].filter(Boolean).join('\n')
                : (block.content ?? '');

        if (!part) {
            continue;
        }

        const start = pos;
        const end = pos + part.length;

        if (offset >= start && offset < end) {
            return block.id;
        }

        pos = end;
        if (i < blocks.length - 1) {
            pos += 2;
        }
    }

    return null;
}

function normalizeHrefForCompare(href) {
    const value = String(href ?? '').trim();
    if (!value) {
        return '';
    }

    try {
        const url = new URL(value, window.location.origin);
        const pathname = url.pathname.replace(/\/+$/, '');
        return `${url.origin}${pathname}${url.search}`;
    } catch {
        return value.replace(/\/+$/, '');
    }
}

/**
 * @param {HTMLAnchorElement|Element} anchor
 * @param {string} targetText
 */
export function anchorTextMatches(anchor, targetText) {
    const keyword = normalizeLinkText(targetText);
    if (!keyword) {
        return true;
    }

    const anchorText = normalizeLinkText(anchor.textContent);
    return anchorText === keyword;
}

/**
 * @param {HTMLAnchorElement|Element} anchor
 * @param {string} href
 */
export function anchorHrefMatches(anchor, href) {
    const targetHref = normalizeHrefForCompare(href);
    if (!targetHref) {
        return true;
    }

    const rawHref = anchor.getAttribute?.('href') ?? '';
    const normalized = normalizeHrefForCompare(rawHref);
    if (normalized === targetHref) {
        return true;
    }

    // Fallback for relative href rendered in editor.
    try {
        const absolute = normalizeHrefForCompare(new URL(rawHref, window.location.origin).toString());
        return absolute === targetHref;
    } catch {
        return false;
    }
}

/**
 * Đếm thẻ &lt;a&gt; có đúng anchor text (từ khóa), không lọc theo href.
 *
 * @param {string} html
 * @param {string} text
 * @param {string} [href]
 */
function unwrapAnchorElement(anchor) {
    const parent = anchor.parentNode;
    if (!parent) {
        return;
    }

    while (anchor.firstChild) {
        parent.insertBefore(anchor.firstChild, anchor);
    }

    parent.removeChild(anchor);
}

/**
 * Gỡ thẻ &lt;a&gt; khớp anchor text/href khỏi HTML block.
 *
 * @param {string} html
 * @param {string} text
 * @param {string} [href]
 * @param {{ removeAll?: boolean, matchIndex?: number }} [options]
 */
export function removeMatchingAnchorsFromHtml(html, text, href = '', options = {}) {
    const targetText = normalizeLinkText(text);
    const targetHref = normalizeHrefForCompare(href);
    if (!html || (!targetText && !targetHref)) {
        return html ?? '';
    }

    const removeAll = options.removeAll !== false;
    const matchIndex = Math.max(0, Number(options.matchIndex) || 0);
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const anchors = [...doc.querySelectorAll('a[href]')].filter(
        (anchor) => anchorTextMatches(anchor, targetText) && anchorHrefMatches(anchor, href),
    );

    if (anchors.length === 0) {
        return html;
    }

    const targets = removeAll ? anchors : [anchors[matchIndex]].filter(Boolean);
    for (const anchor of targets) {
        unwrapAnchorElement(anchor);
    }

    return doc.body.innerHTML;
}

export function countMatchingAnchorsInHtml(html, text, href = '') {
    const targetText = normalizeLinkText(text);
    if (!html) {
        return 0;
    }

    if (!targetText && !normalizeHrefForCompare(href)) {
        return 0;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');

    return [...doc.querySelectorAll('a[href]')].filter(
        (anchor) => anchorTextMatches(anchor, targetText) && anchorHrefMatches(anchor, href),
    ).length;
}

/**
 * Tìm anchor trong block theo từ khóa (thứ tự xuất hiện trong DOM).
 *
 * @param {HTMLElement} root
 * @param {string} text
 * @param {number} matchIndex
 * @param {string} [href]
 */
export function findAnchorElementInRoot(root, text, matchIndex = 0, href = '') {
    if (!root) {
        return null;
    }

    const targetText = normalizeLinkText(text);
    if (!targetText && !normalizeHrefForCompare(href)) {
        return null;
    }

    const anchors = [...root.querySelectorAll('a[href]')];
    let seen = 0;

    for (const anchor of anchors) {
        if (!anchorTextMatches(anchor, targetText) || !anchorHrefMatches(anchor, href)) {
            continue;
        }
        if (seen === matchIndex) {
            return anchor;
        }
        seen += 1;
    }

    return null;
}

/**
 * @param {HTMLElement} el
 */
export function highlightAnchorElement(el) {
    if (!el) {
        return;
    }

    el.classList.add('seo-link-scroll-highlight');
    window.setTimeout(() => {
        el.classList.remove('seo-link-scroll-highlight');
    }, 2400);
}

/**
 * Cuộn tới anchor theo từ khóa — thử lại khi TipTap đang mount.
 *
 * @param {string} blockId
 * @param {string} text
 * @param {number} matchIndex
 * @param {string} [href]
 * @param {{ onDone?: () => void }} [options]
 */
export function scrollToKeywordAnchor(blockId, text, matchIndex = 0, href = '', options = {}) {
    const maxAttempts = 12;

    const attempt = (tryNo) => {
        const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
        if (!slot) {
            if (tryNo < maxAttempts) {
                window.requestAnimationFrame(() => attempt(tryNo + 1));
            }
            return;
        }

        const anchorEl = findAnchorElementInRoot(slot, text, matchIndex, href);

        if (anchorEl) {
            anchorEl.scrollIntoView({ behavior: tryNo === 0 ? 'smooth' : 'auto', block: 'center' });
            highlightAnchorElement(anchorEl);
            options.onDone?.();
            return;
        }

        if (tryNo < maxAttempts) {
            window.requestAnimationFrame(() => attempt(tryNo + 1));
            return;
        }

        if (options.onMiss?.() === true) {
            options.onDone?.(true);
            return;
        }

        slot.scrollIntoView({ behavior: 'auto', block: 'center' });
        options.onDone?.(false);
    };

    attempt(0);
}

/**
 * @param {HTMLElement} itemEl
 * @param {string} keyword
 */
export function faqItemMatchesKeyword(itemEl, keyword) {
    const target = normalizeLinkText(keyword);
    if (!target || !itemEl) {
        return false;
    }

    const question = normalizeLinkText(
        itemEl.querySelector('.seo-faq-question-input')?.value ?? '',
    );

    return question === target;
}

/**
 * @param {number} faqIndex — thứ tự trong `.seo-faq-item` (khớp sidebar FAQ).
 * @returns {boolean}
 */
export function scrollToFaqByIndex(faqIndex) {
    const item =
        document.querySelector(`[data-seo-faq-index="${faqIndex}"]`) ??
        [...document.querySelectorAll('.seo-faq-item')][faqIndex];

    if (!item) {
        return false;
    }

    window.dispatchEvent(new CustomEvent('seo-editor-faq-navigate'));

    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
    item.classList.add('seo-faq-scroll-highlight');

    window.setTimeout(() => {
        item.classList.remove('seo-faq-scroll-highlight');
    }, 2400);

    return true;
}

/**
 * @param {string} text
 * @param {number} matchIndex
 * @returns {boolean}
 */
function countOccurrencesCaseInsensitive(haystack, needle) {
    const h = haystack.toLowerCase();
    const n = needle.toLowerCase();
    if (!n) {
        return 0;
    }

    let count = 0;
    let pos = 0;

    while ((pos = h.indexOf(n, pos)) !== -1) {
        count += 1;
        pos += n.length;
    }

    return count;
}

/**
 * @param {string} html
 * @param {string} text
 */
export function countPlainTextInHtml(html, text) {
    const targetText = normalizeLinkText(text);
    if (!targetText || !html) {
        return 0;
    }

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const bodyText = doc.body?.textContent ?? '';

    return countOccurrencesCaseInsensitive(bodyText, targetText);
}

/**
 * @param {HTMLElement} root
 * @param {string} text
 * @param {number} matchIndex
 * @param {{ includeLinkedText?: boolean }} [options]
 */
export function findPlainTextMatchInRoot(root, text, matchIndex = 0, options = {}) {
    return findPlainTextRangeInRoot(root, text, matchIndex, {
        includeLinkedText: options.includeLinkedText !== false,
    });
}

/**
 * @param {string} blockId
 * @param {string} text
 * @param {number} matchIndex
 * @param {{ onDone?: () => void, onMiss?: () => boolean|void }} [options]
 */
export function scrollToPlainTextInBlock(blockId, text, matchIndex = 0, options = {}) {
    const maxAttempts = 12;

    const attempt = (tryNo) => {
        const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
        if (!slot) {
            if (tryNo < maxAttempts) {
                window.requestAnimationFrame(() => attempt(tryNo + 1));
            }
            return;
        }

        // Include linked text so Vocabulary occurrences stay highlightable after linking.
        const match = findPlainTextMatchInRoot(slot, text, matchIndex, { includeLinkedText: true });

        if (match) {
            const range = document.createRange();
            range.setStart(match.node, match.start);
            range.setEnd(match.endNode, match.endOffset);

            const mark = document.createElement('mark');
            mark.className = `${SEO_EDITOR_LINK_MARK_CLASS} ${SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS}`;

            try {
                range.surroundContents(mark);
                mark.scrollIntoView({ behavior: tryNo === 0 ? 'smooth' : 'auto', block: 'center' });
                window.setTimeout(() => {
                    const parent = mark.parentNode;
                    if (parent) {
                        while (mark.firstChild) {
                            parent.insertBefore(mark.firstChild, mark);
                        }
                        parent.removeChild(mark);
                    }
                }, 2400);
                options.onDone?.();
                return;
            } catch {
                const anchor = enclosingAnchorForPlainTextRange(match);
                if (anchor) {
                    highlightAnchorElement(anchor);
                    options.onDone?.();
                    return;
                }
                range.startContainer.parentElement?.scrollIntoView({
                    behavior: tryNo === 0 ? 'smooth' : 'auto',
                    block: 'center',
                });
                options.onDone?.();
                return;
            }
        }

        if (tryNo < maxAttempts) {
            window.requestAnimationFrame(() => attempt(tryNo + 1));
            return;
        }

        if (options.onMiss?.() === true) {
            options.onDone?.(true);
            return;
        }

        slot.scrollIntoView({ behavior: 'auto', block: 'center' });
        options.onDone?.(false);
    };

    attempt(0);
}

export function scrollToFaqKeyword(text, matchIndex = 0) {
    const keyword = normalizeLinkText(text);
    if (!keyword) {
        return false;
    }

    const items = [...document.querySelectorAll('.seo-faq-item')];
    let seen = 0;

    for (const item of items) {
        if (!faqItemMatchesKeyword(item, keyword)) {
            continue;
        }

        if (seen === matchIndex) {
            window.dispatchEvent(new CustomEvent('seo-editor-faq-navigate'));
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            item.classList.add('seo-faq-scroll-highlight');

            window.setTimeout(() => {
                item.classList.remove('seo-faq-scroll-highlight');
            }, 2400);

            return true;
        }

        seen += 1;
    }

    return false;
}

const SPECIAL_LINK_SCHEMES = new Set([
    'tel',
    'mailto',
    'sms',
    'fax',
    'callto',
    'geo',
    'skype',
    'whatsapp',
    'viber',
    'data',
    'cid',
]);

function isSpecialSchemeLink(href) {
    const value = String(href ?? '').trim();
    if (value === '') {
        return false;
    }

    const lower = value.toLowerCase();
    if (lower.startsWith('javascript:')) {
        return true;
    }

    try {
        const url = new URL(value, 'https://placeholder.local');
        const scheme = String(url.protocol || '').replace(/:$/, '').toLowerCase();

        if (scheme !== '' && scheme !== 'http' && scheme !== 'https' && SPECIAL_LINK_SCHEMES.has(scheme)) {
            return true;
        }
    } catch {
        // fall through
    }

    if (/^[+]?[\d\s().-]{6,}$/u.test(value)) {
        return true;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(value);
}

function normalizeDomainHost(host) {
    return String(host ?? '')
        .trim()
        .toLowerCase()
        .replace(/^www\./, '');
}

function normalizeLinkHrefForDedup(href) {
    const value = String(href ?? '').trim();
    if (value === '') {
        return '';
    }

    return value.toLowerCase().replace(/\/+$/, '');
}

function deduplicateLinksByHrefAndText(links) {
    const seen = new Set();
    const unique = [];

    for (const link of links) {
        const href = normalizeLinkHrefForDedup(link?.href);
        const text = normalizeLinkText(link?.text).toLowerCase();
        const key = `${href}\u0000${text}`;

        if (href === '' || seen.has(key)) {
            continue;
        }

        seen.add(key);
        unique.push(link);
    }

    return unique;
}

export function isInternalLinkHref(href, domain) {
    const value = String(href ?? '').trim();
    if (value === '') {
        return false;
    }

    if (value.startsWith('/')) {
        return true;
    }

    let resolved = value;
    if (resolved.startsWith('//')) {
        resolved = `https:${resolved}`;
    }

    try {
        const url = new URL(resolved, 'https://placeholder.local');
        const host = normalizeDomainHost(url.hostname);
        const normalizedDomain = normalizeDomainHost(domain);

        return host !== '' && normalizedDomain !== '' && host === normalizedDomain;
    } catch {
        return false;
    }
}

/**
 * Trích xuất internal/external links từ HTML (logic tương thích SeoAnalyzerService::extractLinks).
 *
 * @param {string} html
 * @param {string} [domain]
 * @returns {{ internal: Array<{href:string,text:string,is_nofollow:boolean}>, external: Array<{href:string,text:string,is_nofollow:boolean}> }}
 */
export function extractLinksFromHtml(html, domain = '') {
    const result = {
        internal: [],
        external: [],
    };

    const source = String(html ?? '').trim();
    if (source === '') {
        return result;
    }

    const doc = new DOMParser().parseFromString(source, 'text/html');

    for (const anchor of doc.querySelectorAll('a[href]')) {
        const href = String(anchor.getAttribute('href') ?? '').trim();
        if (href === '' || href.startsWith('#') || isSpecialSchemeLink(href)) {
            continue;
        }

        const text = normalizeLinkText(anchor.textContent);
        const rel = String(anchor.getAttribute('rel') ?? '').toLowerCase();
        const item = {
            href,
            text,
            is_nofollow: rel.includes('nofollow'),
        };

        if (isInternalLinkHref(href, domain)) {
            result.internal.push(item);
        } else {
            result.external.push(item);
        }
    }

    return {
        internal: deduplicateLinksByHrefAndText(result.internal),
        external: deduplicateLinksByHrefAndText(result.external),
    };
}

/**
 * @param {Array<{ type?: string, content?: string }>} blocks
 * @param {string} [domain]
 */
export function extractLinksFromBlocks(blocks, domain = '') {
    const merged = {
        internal: [],
        external: [],
    };

    for (const block of Array.isArray(blocks) ? blocks : []) {
        if (block?.type === 'image' || !block?.content) {
            continue;
        }

        const part = extractLinksFromHtml(block.content, domain);
        merged.internal.push(...part.internal);
        merged.external.push(...part.external);
    }

    return {
        internal: deduplicateLinksByHrefAndText(merged.internal),
        external: deduplicateLinksByHrefAndText(merged.external),
    };
}
