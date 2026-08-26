/**
 * Extract readable plain text for one Article Editor section from current block state.
 * Does not fetch DB, normalize typography, or include UI metadata.
 */

/**
 * @param {{ blockIds?: string[] }|null|undefined} section
 * @param {Map<string, { id?: string, content?: string, type?: string }>|Record<string, { id?: string, content?: string, type?: string }>|Array<{ id?: string, content?: string, type?: string }>} blocksSource
 * @returns {string}
 */
export function extractSectionPlainText(section, blocksSource) {
    const blockIds = Array.isArray(section?.blockIds)
        ? section.blockIds.map((id) => String(id ?? '').trim()).filter(Boolean)
        : [];
    if (blockIds.length === 0) {
        return '';
    }

    const resolveBlock = (id) => {
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
    };

    const parts = [];
    for (const id of blockIds) {
        const block = resolveBlock(id);
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
 * @param {string} html
 * @returns {string}
 */
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
