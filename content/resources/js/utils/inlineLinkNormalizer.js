/**
 * Chuẩn hóa / phân tích thẻ <a> liền kề cùng thuộc tính (DOMParser, không regex cấu trúc).
 * Logic đối xứng với InlineLinkNormalizer.php.
 */

import { collapseHtmlSoftNewlines } from './inlineWhitespaceGuard';

const BLOCK_TAGS = new Set([
    'P', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6',
    'LI', 'TD', 'TH', 'BLOCKQUOTE', 'PRE', 'FIGURE', 'SECTION', 'ARTICLE',
    'HEADER', 'FOOTER', 'ASIDE', 'NAV', 'MAIN', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR',
    'UL', 'OL', 'DL', 'DT', 'DD',
]);

const INLINE_WRAPPER_TAGS = new Set([
    'STRONG', 'B', 'EM', 'I', 'U', 'S', 'STRIKE', 'SPAN', 'SUB', 'SUP',
    'MARK', 'SMALL', 'CODE', 'KBD', 'SAMP', 'VAR', 'ABBR', 'CITE', 'DFN', 'TIME',
]);

/**
 * @param {string} html
 * @returns {string}
 */
export function normalizeInlineLinks(html) {
    return normalizeInlineLinksWithReport(html).html;
}

/**
 * @param {string} html
 */
export function analyzeInlineLinks(html) {
    const raw = String(html ?? '').trim();
    if (!raw) {
        return emptyAnalysis();
    }

    const doc = new DOMParser().parseFromString(`<div id="seo-inline-link-root">${raw}</div>`, 'text/html');
    const root = doc.getElementById('seo-inline-link-root');
    if (!root) {
        return emptyAnalysis();
    }

    return analyzeRoot(root);
}

/**
 * @param {string} html
 */
export function normalizeInlineLinksWithReport(html) {
    const raw = String(html ?? '').trim();
    if (!raw) {
        const empty = emptyAnalysis();
        return { html: raw, changed: false, before: empty, after: empty, changes: [] };
    }

    const doc = new DOMParser().parseFromString(`<div id="seo-inline-link-root">${raw}</div>`, 'text/html');
    const root = doc.getElementById('seo-inline-link-root');
    if (!root) {
        const empty = emptyAnalysis();
        return { html: raw, changed: false, before: empty, after: empty, changes: [] };
    }

    const before = analyzeRoot(root);
    /** @type {Array<{type: string, href?: string, detail?: string}>} */
    const changes = [];

    for (let guard = 0; guard < 50; guard += 1) {
        const step = mergePass(root);
        if (step.length === 0) {
            break;
        }
        changes.push(...step);
    }

    changes.push(...unwrapNestedAnchors(root));

    const after = analyzeRoot(root);
    const normalized = root.innerHTML;
    const changed = normalized !== raw || changes.length > 0;

    if (changed && typeof process !== 'undefined' && process.env?.NODE_ENV === 'development' && before.duplicateAdjacentCount > 0) {
        // eslint-disable-next-line no-console
        console.warn('[InlineLinkNormalizer] merged adjacent duplicate anchors', before.warnings);
    }

    return { html: normalized, changed, before, after, changes };
}

/**
 * Pretty-print HTML nhẹ (indent theo depth), giữ nguyên text.
 * @param {string} html
 */
export function prettyPrintHtml(html) {
    const raw = String(html ?? '');
    if (!raw.trim()) {
        return raw;
    }

    const doc = new DOMParser().parseFromString(`<div id="pretty-root">${raw}</div>`, 'text/html');
    const root = doc.getElementById('pretty-root');
    if (!root) {
        return raw;
    }

    const lines = [];
    const walk = (node, depth) => {
        const pad = '  '.repeat(depth);
        if (node.nodeType === Node.TEXT_NODE) {
            const text = node.textContent ?? '';
            if (text.replace(/\s+/g, '') === '' && text.includes('\n')) {
                return;
            }
            if (text.trim() === '' && text.length > 0) {
                // Preserve whitespace-only text nodes (spaces around inline marks).
                lines.push(pad + text);
                return;
            }
            if (text !== '') {
                lines.push(pad + text);
            }
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        const el = /** @type {Element} */ (node);
        const tag = el.tagName.toLowerCase();
        const attrs = [...el.attributes]
            .map((attr) => `${attr.name}="${attr.value.replace(/"/g, '&quot;')}"`)
            .join(' ');
        const open = attrs ? `<${tag} ${attrs}>` : `<${tag}>`;

        if (el.childNodes.length === 0) {
            lines.push(`${pad}${open}</${tag}>`);
            return;
        }

        const onlyText = el.childNodes.length === 1 && el.firstChild?.nodeType === Node.TEXT_NODE;
        if (onlyText) {
            lines.push(`${pad}${open}${el.textContent ?? ''}</${tag}>`);
            return;
        }

        lines.push(`${pad}${open}`);
        el.childNodes.forEach((child) => walk(child, depth + 1));
        lines.push(`${pad}</${tag}>`);
    };

    root.childNodes.forEach((child) => walk(child, 0));
    return lines.join('\n');
}

/**
 * Containers where whitespace-only text nodes are structural (pretty-print indent),
 * not semantic content. TipTap parse with preserveWhitespace:'full' otherwise wraps
 * those newlines into empty &lt;p&gt; (especially inside table cells).
 */
const STRUCTURAL_WHITESPACE_PARENTS = new Set([
    'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TD', 'TH',
    'UL', 'OL', 'LI', 'DL', 'DT', 'DD',
    'BLOCKQUOTE', 'FIGURE', 'SECTION', 'ARTICLE', 'DIV',
    'BODY',
]);

/**
 * @param {Element} el
 * @returns {boolean}
 */
function isEmptyParagraphElement(el) {
    if (!(el instanceof Element) || el.tagName !== 'P') {
        return false;
    }

    const inner = (el.innerHTML || '')
        .replace(/<br\s*\/?>/gi, '')
        .replace(/&nbsp;/gi, ' ')
        .trim();
    const text = (el.textContent || '').replace(/\u00a0/g, ' ').trim();

    return !text && !inner.replace(/<[^>]+>/gi, '').trim();
}

/**
 * Drop pretty-print whitespace + redundant empty paragraphs before TipTap setContent.
 * Round-trip: prettyPrintHtml → edit → prepareHtmlForTipTapApply → setContent.
 *
 * @param {string} html
 * @returns {string}
 */
export function prepareHtmlForTipTapApply(html) {
    // Soft \n inside <p> must become spaces before preserveWhitespace:'full'.
    const raw = collapseHtmlSoftNewlines(String(html ?? ''));
    if (!raw.trim()) {
        return raw;
    }

    const doc = new DOMParser().parseFromString(`<div id="tiptap-apply-root">${raw}</div>`, 'text/html');
    const root = doc.getElementById('tiptap-apply-root');
    if (!root) {
        return raw;
    }

    const collapseWhitespace = (el) => {
        const children = [...el.childNodes];
        const hasElementChild = children.some((node) => node.nodeType === Node.ELEMENT_NODE);

        for (const child of children) {
            if (child.nodeType === Node.TEXT_NODE) {
                const text = child.textContent ?? '';
                if (
                    text.trim() === ''
                    && hasElementChild
                    && STRUCTURAL_WHITESPACE_PARENTS.has(el.tagName)
                ) {
                    child.remove();
                }
                continue;
            }

            if (child.nodeType === Node.ELEMENT_NODE) {
                collapseWhitespace(/** @type {Element} */ (child));
            }
        }
    };

    collapseWhitespace(root);

    for (const cell of root.querySelectorAll('td, th')) {
        const directParagraphs = [...cell.children].filter((child) => child.tagName === 'P');
        if (directParagraphs.length <= 1) {
            continue;
        }

        const emptyOnes = directParagraphs.filter((p) => isEmptyParagraphElement(p));
        const meaningful = directParagraphs.length - emptyOnes.length;
        if (meaningful === 0) {
            // Keep a single empty paragraph so the cell stays valid for TipTap.
            emptyOnes.slice(1).forEach((p) => p.remove());
            continue;
        }

        emptyOnes.forEach((p) => p.remove());
    }

    return root.innerHTML;
}

function emptyAnalysis() {
    return {
        anchors: 0,
        duplicateAdjacentCount: 0,
        nestedAnchorCount: 0,
        invalidHrefCount: 0,
        splitGroups: [],
        invalidHrefs: [],
        warnings: [],
    };
}

/**
 * @param {Element} root
 */
function analyzeRoot(root) {
    const anchors = [...root.querySelectorAll('a')];
    let nested = 0;
    let duplicateAdjacent = 0;
    /** @type {string[]} */
    const invalidHrefs = [];
    /** @type {Map<string, {href: string, count: number, sample: string}>} */
    const groupMap = new Map();
    /** @type {string[]} */
    const warnings = [];

    for (const anchor of anchors) {
        if (closestAncestorAnchor(anchor)) {
            nested += 1;
        }

        const href = String(anchor.getAttribute('href') ?? '').trim();
        if (!isValidHref(href)) {
            invalidHrefs.push(href);
        }

        const signature = linkSignature(anchor);
        if (!signature) {
            continue;
        }

        const forward = countForwardEquivalent(anchor, signature);
        if (forward > 0) {
            duplicateAdjacent += forward;
            const existing = groupMap.get(signature.key);
            if (!existing) {
                groupMap.set(signature.key, {
                    href: signature.href,
                    count: 1 + forward,
                    sample: truncate(normalizeSpace(anchor.textContent ?? ''), 80),
                });
            } else {
                existing.count = Math.max(existing.count, 1 + forward);
            }
        }
    }

    const splitGroups = [...groupMap.values()];
    for (const group of splitGroups) {
        warnings.push(
            `Adjacent duplicate anchors for href=${group.href} (segments≈${group.count}): «${group.sample}»`,
        );
    }
    if (nested > 0) {
        warnings.push(`Found ${nested} nested anchor(s).`);
    }

    const uniqueInvalid = [...new Set(invalidHrefs)];

    return {
        anchors: anchors.length,
        duplicateAdjacentCount: duplicateAdjacent,
        nestedAnchorCount: nested,
        invalidHrefCount: uniqueInvalid.length,
        splitGroups,
        invalidHrefs: uniqueInvalid,
        warnings,
    };
}

/**
 * @param {Element} root
 */
function mergePass(root) {
    /** @type {Array<{type: string, href?: string, detail?: string}>} */
    const changes = [];

    for (const wrapper of [...root.querySelectorAll('*')]) {
        if (!(wrapper instanceof Element) || !INLINE_WRAPPER_TAGS.has(wrapper.tagName) || !wrapper.parentNode) {
            continue;
        }
        const merged = tryMergeFromLeadingWrapper(wrapper);
        if (merged) {
            changes.push(merged);
        }
    }

    for (const anchor of [...root.querySelectorAll('a')]) {
        if (!anchor.parentNode) {
            continue;
        }
        const merged = tryMergeForward(anchor);
        if (merged) {
            changes.push(merged);
        }
    }

    return changes;
}

/**
 * @param {Element} wrapper
 */
function tryMergeFromLeadingWrapper(wrapper) {
    const innerAnchor = soleDescendantAnchor(wrapper);
    if (!innerAnchor) {
        return null;
    }

    const signature = linkSignature(innerAnchor);
    if (!signature) {
        return null;
    }

    let cursor = wrapper.nextSibling;
    /** @type {ChildNode[]} */
    const whitespaceNodes = [];
    while (cursor && cursor.nodeType === Node.TEXT_NODE && String(cursor.textContent ?? '').trim() === '') {
        whitespaceNodes.push(cursor);
        cursor = cursor.nextSibling;
    }

    const trailing = resolveEquivalentAnchorNode(cursor, signature);
    if (!trailing) {
        return null;
    }

    const doc = wrapper.ownerDocument;
    const newAnchor = cloneAnchorShell(doc, innerAnchor);
    wrapper.parentNode?.insertBefore(newAnchor, wrapper);
    unwrapNode(innerAnchor);
    newAnchor.appendChild(wrapper);

    for (const ws of whitespaceNodes) {
        newAnchor.appendChild(ws);
    }

    if (trailing.kind === 'anchor') {
        appendChildren(newAnchor, trailing.node);
        trailing.node.remove();
    } else {
        unwrapNode(trailing.inner);
        newAnchor.appendChild(trailing.node);
    }

    return {
        type: 'merge_leading_wrapper_anchor',
        href: signature.href,
        detail: 'Merged leading inline-wrapped anchor with following equivalent anchor.',
    };
}

/**
 * @param {HTMLAnchorElement|Element} anchor
 */
function tryMergeForward(anchor) {
    const signature = linkSignature(anchor);
    if (!signature) {
        return null;
    }

    const parent = anchor.parentElement;
    if (parent && INLINE_WRAPPER_TAGS.has(parent.tagName) && soleDescendantAnchor(parent) === anchor) {
        return null;
    }

    let cursor = anchor.nextSibling;
    /** @type {ChildNode[]} */
    const whitespaceNodes = [];
    while (cursor && cursor.nodeType === Node.TEXT_NODE && String(cursor.textContent ?? '').trim() === '') {
        whitespaceNodes.push(cursor);
        cursor = cursor.nextSibling;
    }

    const trailing = resolveEquivalentAnchorNode(cursor, signature);
    if (!trailing) {
        return null;
    }

    for (const ws of whitespaceNodes) {
        anchor.appendChild(ws);
    }

    if (trailing.kind === 'anchor') {
        appendChildren(anchor, trailing.node);
        trailing.node.remove();
        return {
            type: 'merge_sibling_anchors',
            href: signature.href,
            detail: 'Merged adjacent sibling anchors with equivalent attributes.',
        };
    }

    unwrapNode(trailing.inner);
    anchor.appendChild(trailing.node);
    return {
        type: 'merge_wrapped_anchor',
        href: signature.href,
        detail: 'Merged anchor inside inline wrapper into preceding equivalent anchor.',
    };
}

/**
 * @param {Node|null} cursor
 * @param {{key: string, href: string}} signature
 */
function resolveEquivalentAnchorNode(cursor, signature) {
    if (!(cursor instanceof Element)) {
        return null;
    }

    if (cursor.tagName === 'A') {
        if (linkSignature(cursor)?.key !== signature.key) {
            return null;
        }
        return { kind: 'anchor', node: cursor };
    }

    if (!INLINE_WRAPPER_TAGS.has(cursor.tagName)) {
        return null;
    }

    const inner = soleDescendantAnchor(cursor);
    if (!inner || linkSignature(inner)?.key !== signature.key) {
        return null;
    }

    return { kind: 'wrapper', node: cursor, inner };
}

/**
 * @param {Element} root
 */
function unwrapNestedAnchors(root) {
    /** @type {Array<{type: string, href?: string, detail?: string}>} */
    const changes = [];
    for (const anchor of [...root.querySelectorAll('a')]) {
        if (!closestAncestorAnchor(anchor)) {
            continue;
        }
        const href = String(anchor.getAttribute('href') ?? '').trim();
        unwrapNode(anchor);
        changes.push({
            type: 'unwrap_nested_anchor',
            href,
            detail: 'Removed nested anchor inside another anchor.',
        });
    }
    return changes;
}

/**
 * @param {Element} wrapper
 * @returns {HTMLAnchorElement|Element|null}
 */
function soleDescendantAnchor(wrapper) {
    const anchors = [...wrapper.querySelectorAll('a')];
    if (anchors.length !== 1) {
        return null;
    }
    const anchor = anchors[0];
    if (!wrapperOnlyContainsAnchorPath(wrapper, anchor)) {
        return null;
    }
    return anchor;
}

/**
 * @param {Element} wrapper
 * @param {Element} anchor
 */
function wrapperOnlyContainsAnchorPath(wrapper, anchor) {
    let node = anchor;
    while (node !== wrapper) {
        const parent = node.parentElement;
        if (!parent) {
            return false;
        }
        for (const child of parent.childNodes) {
            if (child === node) {
                continue;
            }
            if (child.nodeType === Node.TEXT_NODE && String(child.textContent ?? '').trim() === '') {
                continue;
            }
            return false;
        }
        if (parent === wrapper) {
            break;
        }
        if (!INLINE_WRAPPER_TAGS.has(parent.tagName)) {
            return false;
        }
        node = parent;
    }

    for (const child of wrapper.childNodes) {
        if (child === anchor || (child instanceof Element && child.contains(anchor))) {
            continue;
        }
        if (child.nodeType === Node.TEXT_NODE && String(child.textContent ?? '').trim() === '') {
            continue;
        }
        return false;
    }

    return true;
}

/**
 * @param {Element} el
 */
function closestAncestorAnchor(el) {
    let parent = el.parentElement;
    while (parent) {
        if (parent.tagName === 'A') {
            return parent;
        }
        if (BLOCK_TAGS.has(parent.tagName)) {
            return null;
        }
        parent = parent.parentElement;
    }
    return null;
}

/**
 * @param {Element} anchor
 * @param {{key: string, href: string}} signature
 */
function countForwardEquivalent(anchor, signature) {
    let count = 0;
    let cursor = anchor.nextSibling;
    while (cursor) {
        if (cursor.nodeType === Node.TEXT_NODE) {
            if (String(cursor.textContent ?? '').trim() === '') {
                cursor = cursor.nextSibling;
                continue;
            }
            break;
        }
        if (!(cursor instanceof Element)) {
            break;
        }
        if (cursor.tagName === 'A') {
            if (linkSignature(cursor)?.key === signature.key) {
                count += 1;
                cursor = cursor.nextSibling;
                continue;
            }
            break;
        }
        if (INLINE_WRAPPER_TAGS.has(cursor.tagName)) {
            const inner = soleDescendantAnchor(cursor);
            if (inner && linkSignature(inner)?.key === signature.key) {
                count += 1;
                cursor = cursor.nextSibling;
                continue;
            }
        }
        break;
    }
    return count;
}

/**
 * @param {Element} anchor
 * @returns {{key: string, href: string}|null}
 */
function linkSignature(anchor) {
    if (anchor.tagName !== 'A') {
        return null;
    }
    const href = String(anchor.getAttribute('href') ?? '').trim();
    if (!href || !isValidHref(href)) {
        return null;
    }

    const parts = [`href=${href.toLowerCase()}`];
    for (const attr of ['target', 'rel', 'title', 'class']) {
        let value = String(anchor.getAttribute(attr) ?? '').trim();
        if (attr === 'class' || attr === 'rel') {
            const tokens = value
                .toLowerCase()
                .split(/\s+/)
                .filter(Boolean)
                .sort();
            value = tokens.join(' ');
        } else {
            value = value.toLowerCase();
        }
        parts.push(`${attr}=${value}`);
    }

    for (const attr of anchor.attributes) {
        const name = attr.name.toLowerCase();
        if (!name.startsWith('data-')) {
            continue;
        }
        parts.push(`${name}=${String(attr.value ?? '').trim().toLowerCase()}`);
    }

    parts.sort();
    return { key: parts.join('|'), href };
}

/**
 * @param {string} href
 */
export function isValidHref(href) {
    const value = String(href ?? '').trim();
    if (value === '' || value === '#') {
        return false;
    }
    if (value.startsWith('#')) {
        return true;
    }
    if (/^(https?|mailto|tel|sms):/i.test(value)) {
        return true;
    }
    if (value.startsWith('/') || value.startsWith('./') || value.startsWith('../')) {
        return true;
    }
    if (!/^[a-z][a-z0-9+.-]*:/i.test(value)) {
        return true;
    }
    return false;
}

/**
 * @param {Document} doc
 * @param {Element} source
 */
function cloneAnchorShell(doc, source) {
    const anchor = doc.createElement('a');
    for (const attr of source.attributes) {
        anchor.setAttribute(attr.name, attr.value);
    }
    return anchor;
}

/**
 * @param {Element} target
 * @param {Element} source
 */
function appendChildren(target, source) {
    while (source.firstChild) {
        target.appendChild(source.firstChild);
    }
}

/**
 * @param {Element} el
 */
function unwrapNode(el) {
    const parent = el.parentNode;
    if (!parent) {
        return;
    }
    while (el.firstChild) {
        parent.insertBefore(el.firstChild, el);
    }
    parent.removeChild(el);
}

function normalizeSpace(text) {
    return String(text ?? '').replace(/\s+/g, ' ').trim();
}

function truncate(value, max) {
    if (value.length <= max) {
        return value;
    }
    return `${value.slice(0, max - 1)}…`;
}

/** Attrs chuẩn khi setLink — tránh mark attrs lệch giữa các text node. */
export const SEO_LINK_DEFAULT_ATTRS = {
    target: '_blank',
    rel: 'noopener noreferrer',
    class: 'seo-editor-link',
};

/**
 * @param {string} href
 */
export function normalizedLinkAttrs(href) {
    return {
        href: String(href ?? '').trim(),
        ...SEO_LINK_DEFAULT_ATTRS,
    };
}

/**
 * Áp link lên selection hiện tại trong một transaction thống nhất.
 * @param {import('@tiptap/core').Editor} editor
 * @param {string} href
 * @returns {boolean}
 */
export function applyLinkToSelection(editor, href) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const trimmed = String(href ?? '').trim();
    if (!trimmed) {
        return false;
    }

    const attrs = normalizedLinkAttrs(trimmed);
    const { from, to, empty } = editor.state.selection;
    const chain = editor.chain().focus();

    if (empty) {
        if (!editor.isActive('link')) {
            return false;
        }
        return chain.extendMarkRange('link').setLink(attrs).run();
    }

    return chain.setTextSelection({ from, to }).unsetLink().setLink(attrs).run();
}
