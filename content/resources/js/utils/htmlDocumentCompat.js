/**
 * HTML → TipTap/PM JSON compatibility adapter (Phase 3).
 * Single place allowed to DOMParser for document ingest.
 * Analysis/widgets must not call this repeatedly — convert once → DocumentModel.
 */

/**
 * @param {string} html
 * @returns {{ type: 'doc', content: object[] }}
 */
export function htmlToDocumentJson(html) {
    const source = String(html ?? '').trim();
    if (source === '') {
        return { type: 'doc', content: [] };
    }

    if (typeof DOMParser === 'undefined') {
        return htmlToDocumentJsonFallback(source);
    }

    const doc = new DOMParser().parseFromString(source, 'text/html');
    const body = doc.body;
    const content = [];
    Array.from(body.childNodes).forEach((node) => {
        const converted = convertDomNode(node);
        if (Array.isArray(converted)) {
            content.push(...converted.filter(Boolean));
        } else if (converted) {
            content.push(converted);
        }
    });

    return { type: 'doc', content };
}

/**
 * Article editor blocks[] → single PM doc.
 * Text block.content HTML converted via compat; image blocks → articleImage node.
 *
 * @param {Array<{ type?: string, content?: string, image?: object }>} blocks
 */
export function blocksToDocumentJson(blocks) {
    const list = Array.isArray(blocks) ? blocks : [];
    const content = [];

    list.forEach((block) => {
        const type = String(block?.type ?? 'text');
        if (type === 'image') {
            const img = block.image && typeof block.image === 'object' ? block.image : {};
            const src = String(img.src ?? img.url ?? '').trim();
            if (src === '') {
                return;
            }
            content.push({
                type: 'articleImage',
                attrs: {
                    src,
                    alt: String(img.alt ?? '').trim(),
                    title: String(img.title ?? '').trim(),
                },
            });
            return;
        }

        const html = String(block?.content ?? '');
        if (html.trim() === '') {
            return;
        }
        const partial = htmlToDocumentJson(html);
        (partial.content || []).forEach((node) => content.push(node));
    });

    return { type: 'doc', content };
}

/**
 * @param {Node} node
 * @returns {object|object[]|null}
 */
function convertDomNode(node) {
    if (!node) {
        return null;
    }

    if (node.nodeType === Node.TEXT_NODE) {
        const text = String(node.textContent ?? '');
        // Keep whitespace-only text (do not trim) — callers decide block wrapping.
        if (text === '') {
            return null;
        }
        // Top-level text → wrap paragraph
        return {
            type: 'paragraph',
            content: [{ type: 'text', text }],
        };
    }

    if (node.nodeType !== Node.ELEMENT_NODE) {
        return null;
    }

    const el = /** @type {HTMLElement} */ (node);
    const tag = el.tagName.toLowerCase();

    if (tag === 'h1' || tag === 'h2' || tag === 'h3' || tag === 'h4' || tag === 'h5' || tag === 'h6') {
        return {
            type: 'heading',
            attrs: { level: Number(tag.slice(1)) },
            content: convertInlineChildren(el),
        };
    }

    if (tag === 'p') {
        return {
            type: 'paragraph',
            attrs: classAttrs(el),
            content: convertInlineChildren(el),
        };
    }

    if (tag === 'blockquote') {
        const inner = [];
        Array.from(el.childNodes).forEach((child) => {
            const c = convertDomNode(child);
            if (Array.isArray(c)) {
                inner.push(...c.filter(Boolean));
            } else if (c) {
                inner.push(c);
            }
        });
        return {
            type: 'blockquote',
            content: inner.length > 0 ? inner : [{ type: 'paragraph', content: [] }],
        };
    }

    if (tag === 'ul' || tag === 'ol') {
        const items = [];
        Array.from(el.children).forEach((li) => {
            if (li.tagName?.toLowerCase() !== 'li') {
                return;
            }
            items.push({
                type: 'listItem',
                content: [{
                    type: 'paragraph',
                    content: convertInlineChildren(li),
                }],
            });
        });
        return {
            type: tag === 'ul' ? 'bulletList' : 'orderedList',
            content: items,
        };
    }

    if (tag === 'img') {
        return {
            type: 'articleImage',
            attrs: {
                src: String(el.getAttribute('src') ?? '').trim(),
                alt: String(el.getAttribute('alt') ?? '').trim(),
                title: String(el.getAttribute('title') ?? '').trim(),
            },
        };
    }

    if (tag === 'figure') {
        const img = el.querySelector('img');
        if (img) {
            return convertDomNode(img);
        }
        return flattenBlockChildren(el);
    }

    if (tag === 'table') {
        return convertTableElement(el);
    }

    if (tag === 'br') {
        return {
            type: 'paragraph',
            content: [{ type: 'hardBreak' }],
        };
    }

    if (tag === 'div' || tag === 'section' || tag === 'article') {
        return flattenBlockChildren(el);
    }

    // Unknown block → paragraph with inlines
    return {
        type: 'paragraph',
        attrs: classAttrs(el),
        content: convertInlineChildren(el),
    };
}

function flattenBlockChildren(el) {
    const out = [];
    Array.from(el.childNodes).forEach((child) => {
        const c = convertDomNode(child);
        if (Array.isArray(c)) {
            out.push(...c.filter(Boolean));
        } else if (c) {
            out.push(c);
        }
    });
    return out;
}

/**
 * @param {HTMLElement} table
 * @returns {{ type: string, content: object[] }}
 */
function convertTableElement(table) {
    const rows = [];
    Array.from(table.querySelectorAll('tr')).forEach((tr) => {
        if (tr.closest('table') !== table) {
            return;
        }
        const cells = [];
        Array.from(tr.children).forEach((cell) => {
            const cellTag = String(cell.tagName || '').toLowerCase();
            if (cellTag !== 'td' && cellTag !== 'th') {
                return;
            }
            const attrs = {};
            const colspan = Number.parseInt(cell.getAttribute('colspan') || '1', 10);
            const rowspan = Number.parseInt(cell.getAttribute('rowspan') || '1', 10);
            if (colspan > 1) attrs.colspan = colspan;
            if (rowspan > 1) attrs.rowspan = rowspan;
            const inline = convertInlineChildren(cell);
            cells.push({
                type: cellTag === 'th' ? 'tableHeader' : 'tableCell',
                attrs: Object.keys(attrs).length > 0 ? attrs : undefined,
                content: [{
                    type: 'paragraph',
                    content: inline.length > 0 ? inline : [{ type: 'text', text: '' }],
                }],
            });
        });
        if (cells.length > 0) {
            rows.push({ type: 'tableRow', content: cells });
        }
    });

    return {
        type: 'table',
        content: rows,
    };
}

function classAttrs(el) {
    const cls = String(el.getAttribute('class') ?? '').trim();
    return cls !== '' ? { class: cls } : undefined;
}

/**
 * @param {HTMLElement} el
 * @returns {object[]}
 */
function convertInlineChildren(el) {
    const content = [];
    Array.from(el.childNodes).forEach((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            const text = String(child.textContent ?? '');
            if (text !== '') {
                // Soft newlines → spaces (HTML phrasing). Explicit <br> stays hardBreak.
                content.push({ type: 'text', text: text.replace(/\r\n|\r|\n/g, ' ') });
            }
            return;
        }
        if (child.nodeType !== Node.ELEMENT_NODE) {
            return;
        }
        const childEl = /** @type {HTMLElement} */ (child);
        const tag = childEl.tagName.toLowerCase();
        if (tag === 'br') {
            content.push({ type: 'hardBreak' });
            return;
        }
        if (tag === 'a') {
            const href = String(childEl.getAttribute('href') ?? '').trim();
            const inner = convertInlineChildren(childEl);
            inner.forEach((piece) => {
                if (piece.type === 'text') {
                    const marks = Array.isArray(piece.marks) ? [...piece.marks] : [];
                    if (href !== '') {
                        marks.push({ type: 'link', attrs: { href } });
                    }
                    content.push({ ...piece, marks });
                } else {
                    content.push(piece);
                }
            });
            return;
        }
        if (tag === 'strong' || tag === 'b') {
            convertInlineChildren(childEl).forEach((piece) => {
                if (piece.type === 'text') {
                    const marks = [...(piece.marks || []), { type: 'bold' }];
                    content.push({ ...piece, marks });
                } else {
                    content.push(piece);
                }
            });
            return;
        }
        if (tag === 'em' || tag === 'i') {
            convertInlineChildren(childEl).forEach((piece) => {
                if (piece.type === 'text') {
                    const marks = [...(piece.marks || []), { type: 'italic' }];
                    content.push({ ...piece, marks });
                } else {
                    content.push(piece);
                }
            });
            return;
        }
        // Nested span etc.
        content.push(...convertInlineChildren(childEl));
    });

    return content;
}

/**
 * No-DOM fallback (Node/phpunit source tests may not have DOMParser).
 * Strip to a single paragraph of text; links via rough regex.
 *
 * @param {string} html
 */
function htmlToDocumentJsonFallback(html) {
    const links = [];
    const re = /<a\b[^>]*\bhref\s*=\s*["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/giu;
    let match;
    while ((match = re.exec(html)) !== null) {
        links.push({
            type: 'text',
            text: String(match[2] ?? '').replace(/<[^>]+>/g, ''),
            marks: [{ type: 'link', attrs: { href: String(match[1] ?? '').trim() } }],
        });
    }

    const text = html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    const content = [];
    if (text !== '') {
        content.push({
            type: 'paragraph',
            content: links.length > 0 ? links : [{ type: 'text', text }],
        });
    }

    const h2Count = (html.match(/<h2\b[^>]*>/gi) ?? []).length;
    for (let i = 0; i < h2Count; i += 1) {
        content.unshift({
            type: 'heading',
            attrs: { level: 2 },
            content: [{ type: 'text', text: `H2 ${i + 1}` }],
        });
    }

    return { type: 'doc', content };
}

export default {
    htmlToDocumentJson,
    blocksToDocumentJson,
};
