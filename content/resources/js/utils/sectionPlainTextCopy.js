/**
 * Extract readable plain text for one Article Editor section from current block state.
 * Does not fetch DB, normalize typography, or include UI metadata.
 */

/**
 * @param {{ blockIds?: string[] }|null|undefined} section
 * @param {Map<string, { id?: string, content?: string, type?: string }>|Record<string, { id?: string, content?: string, type?: string }>|Array<{ id?: string, content?: string, type?: string }>} blocksSource
 * @returns {string}
 */
/**
 * @param {Map<string, { id?: string, content?: string, type?: string }>|Record<string, { id?: string, content?: string, type?: string }>|Array<{ id?: string, content?: string, type?: string }>} blocksSource
 * @param {string} id
 * @returns {{ id?: string, content?: string, type?: string }|null}
 */
function resolveBlockFromSource(blocksSource, id) {
    if (blocksSource instanceof Map) {
        return blocksSource.get(id) ?? null;
    }
    if (Array.isArray(blocksSource)) {
        return blocksSource.find((b) => String(b?.id ?? '') === id) ?? null;
    }
    if (blocksSource && typeof blocksSource === 'object') {
        return blocksSource[id] ?? null;
    }
    return null;
}

/**
 * Prefer live TipTap HTML for a block; fall back to stored content.
 * Read-only — does not flush React state, focus, or selection.
 *
 * @param {{ id?: string, content?: string, type?: string }|null|undefined} block
 * @param {Map<string, { isDestroyed?: boolean, getHTML?: () => string }>|null|undefined} blockEditors
 * @returns {string}
 */
function resolveBlockHtmlForCopy(block, blockEditors) {
    if (!block) {
        return '';
    }
    const id = String(block.id ?? '').trim();
    const type = String(block.type ?? 'text');
    if (type === 'image') {
        // Captions (if any) live in HTML content / figcaption; skip binary image metadata.
        return String(block.content ?? '');
    }
    const editor = id && blockEditors?.get?.(id) ? blockEditors.get(id) : null;
    if (editor && !editor.isDestroyed && typeof editor.getHTML === 'function') {
        return String(editor.getHTML() ?? '');
    }
    return String(block.content ?? '');
}

export function extractSectionPlainText(section, blocksSource) {
    const blockIds = Array.isArray(section?.blockIds)
        ? section.blockIds.map((id) => String(id ?? '').trim()).filter(Boolean)
        : [];
    if (blockIds.length === 0) {
        return '';
    }

    const parts = [];
    for (const id of blockIds) {
        const block = resolveBlockFromSource(blocksSource, id);
        if (!block) {
            continue;
        }
        const plain = htmlToSectionPlainText(String(block.content ?? ''));
        if (plain !== '') {
            parts.push(plain);
        }
    }

    return parts
        .join('\n\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * Extract readable plain text for Shift-selected blocks only (temp-merge range).
 * Preserves selection order when provided; otherwise document order.
 * Does not mutate selection, focus, or block state.
 *
 * @param {string[]|null|undefined} blockIds
 * @param {Array<{ id?: string, content?: string, type?: string }>|Map<string, { id?: string, content?: string, type?: string }>|null|undefined} blocksSource
 * @param {Map<string, { isDestroyed?: boolean, getHTML?: () => string }>|null|undefined} blockEditors
 * @returns {string}
 */
export function extractSelectedBlocksPlainText(blockIds, blocksSource, blockEditors = null) {
    const ids = (Array.isArray(blockIds) ? blockIds : [])
        .map((id) => String(id ?? '').trim())
        .filter(Boolean);
    if (ids.length === 0) {
        return '';
    }

    // Keep caller order, but drop duplicates / missing ids.
    const seen = new Set();
    const parts = [];
    for (const id of ids) {
        if (seen.has(id)) {
            continue;
        }
        seen.add(id);
        const block = resolveBlockFromSource(blocksSource, id);
        if (!block) {
            continue;
        }
        const plain = htmlToSectionPlainText(resolveBlockHtmlForCopy(block, blockEditors));
        if (plain !== '') {
            parts.push(plain);
        }
    }

    return parts
        .join('\n\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * Extract readable plain text for the full article from current editor/block state.
 * Preserves document order. Does not mutate selection, focus, or block state.
 *
 * @param {Array<{ id?: string, content?: string, type?: string }>|Map<string, { id?: string, content?: string, type?: string }>|null|undefined} blocksSource
 * @param {Map<string, { isDestroyed?: boolean, getHTML?: () => string }>|null|undefined} blockEditors
 * @returns {string}
 */
export function extractArticlePlainText(blocksSource, blockEditors = null) {
    const list = Array.isArray(blocksSource)
        ? blocksSource
        : blocksSource instanceof Map
            ? [...blocksSource.values()]
            : [];

    return extractSelectedBlocksPlainText(
        list.map((block) => String(block?.id ?? '').trim()).filter(Boolean),
        blocksSource,
        blockEditors,
    );
}

/**
 * @param {string} html
 * @returns {string}
 */
/**
 * Clipboard-only: strip managed CTA wrappers and [seo_ops_cta] shortcodes.
 * Does not mutate the live editor document.
 * @param {Element} root
 */
function stripCtaFromCopyRoot(root) {
    if (!(root instanceof Element)) {
        return;
    }
    root.querySelectorAll('.seo-managed-cta, .seo-managed-cta-view, [data-cta-placement]').forEach((node) => {
        node.remove();
    });
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    /** @type {Text[]} */
    const textNodes = [];
    let current = walker.nextNode();
    while (current) {
        textNodes.push(/** @type {Text} */ (current));
        current = walker.nextNode();
    }
    const ctaPair = /\[seo_ops_cta\b[^\]]*\][\s\S]*?\[\/seo_ops_cta\]/gi;
    const ctaSelf = /\[seo_ops_cta\b[^\]]*\/\]/gi;
    for (const textNode of textNodes) {
        const value = String(textNode.nodeValue ?? '');
        if (!/seo_ops_cta/i.test(value)) {
            continue;
        }
        const next = value.replace(ctaPair, '').replace(ctaSelf, '');
        textNode.nodeValue = next;
    }
}

export function htmlToSectionPlainText(html) {
    const raw = String(html ?? '').trim();
    if (raw === '') {
        return '';
    }

    const doc = new DOMParser().parseFromString(`<div id="omi-section-copy-root">${raw}</div>`, 'text/html');
    const root = doc.getElementById('omi-section-copy-root');
    if (!root) {
        return '';
    }

    stripCtaFromCopyRoot(root);

    /** @type {string[]} */
    const lines = [];

    /**
     * @param {Node} node
     */
    const walk = (node) => {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = String(node.textContent ?? '').replace(/\u00a0/g, ' ');
            if (text.replace(/\s+/g, '') === '') {
                return;
            }
            lines.push(text.replace(/\s+/g, ' ').trim());
            return;
        }
        if (node.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        const el = /** @type {Element} */ (node);
        const tag = el.tagName.toLowerCase();

        if (tag === 'script' || tag === 'style') {
            return;
        }

        if (
            el.classList?.contains('seo-managed-cta')
            || el.classList?.contains('seo-managed-cta-view')
            || el.getAttribute?.('data-cta-placement')
        ) {
            return;
        }

        if (tag === 'img' || tag === 'picture' || tag === 'video' || tag === 'iframe' || tag === 'br' || tag === 'hr') {
            // Skip binary/media URLs and editor chrome. Captions live in sibling text/figcaption.
            if (tag === 'br') {
                lines.push('');
            }
            return;
        }

        if (tag === 'figcaption') {
            const cap = String(el.textContent ?? '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
            if (cap !== '') {
                lines.push(cap);
            }
            return;
        }

        if (tag === 'li') {
            const itemText = inlinePlain(el).trim();
            if (itemText !== '') {
                lines.push(itemText);
            }
            return;
        }

        if (/^h[1-6]$/.test(tag) || tag === 'p' || tag === 'blockquote' || tag === 'div' || tag === 'section' || tag === 'figure' || tag === 'td' || tag === 'th') {
            // Block containers: prefer direct structured children; fall back to own text.
            const blockChildren = [...el.children];
            const hasBlockChild = blockChildren.some((child) => {
                const t = child.tagName.toLowerCase();
                return (
                    /^h[1-6]$/.test(t)
                    || ['p', 'ul', 'ol', 'li', 'blockquote', 'table', 'div', 'section', 'figure', 'pre'].includes(t)
                );
            });
            if (hasBlockChild) {
                blockChildren.forEach((child) => walk(child));
                return;
            }
            const text = inlinePlain(el).trim();
            if (text !== '') {
                lines.push(text);
            }
            return;
        }

        if (tag === 'ul' || tag === 'ol' || tag === 'table' || tag === 'thead' || tag === 'tbody' || tag === 'tr') {
            [...el.children].forEach((child) => walk(child));
            return;
        }

        const fallback = inlinePlain(el).trim();
        if (fallback !== '') {
            lines.push(fallback);
        }
    };

    [...root.childNodes].forEach((child) => walk(child));

    return lines
        .map((line) => line.trim())
        .filter((line, index, arr) => !(line === '' && (index === 0 || arr[index - 1] === '')))
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
}

/**
 * @param {Element} el
 * @returns {string}
 */
function inlinePlain(el) {
    const clone = el.cloneNode(true);
    if (!(clone instanceof Element)) {
        return '';
    }
    clone.querySelectorAll('script,style,img,picture,video,iframe').forEach((node) => node.remove());
    return String(clone.textContent ?? '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ');
}

/**
 * @param {string} text
 * @returns {Promise<void>}
 */
export async function writeTextToClipboard(text) {
    const value = String(text ?? '');
    if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(value);
        return;
    }

    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    document.execCommand('copy');
    document.body.removeChild(textarea);
}
