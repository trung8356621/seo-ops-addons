/**
 * Article Editor Document Model (Phase 3).
 * Canonical shape = ProseMirror / TipTap JSON: { type: 'doc', content: [...] }.
 * HTML is compatibility input only (htmlDocumentCompat).
 */

/**
 * @typedef {{ type: string, attrs?: Record<string, unknown>, marks?: Array<{type:string,attrs?:Record<string,unknown>}>, content?: PmNode[], text?: string }} PmNode
 * @typedef {{ type: 'doc', content?: PmNode[] }} PmDoc
 */

/**
 * @param {PmDoc|PmNode|null|undefined} doc
 * @returns {PmDoc}
 */
export function normalizeDocument(doc) {
    if (!doc || typeof doc !== 'object') {
        return { type: 'doc', content: [] };
    }
    if (doc.type === 'doc') {
        return {
            type: 'doc',
            content: Array.isArray(doc.content) ? doc.content : [],
        };
    }

    return { type: 'doc', content: [doc] };
}

/**
 * Depth-first walk. Callback may return false to skip children.
 *
 * @param {PmDoc|PmNode} root
 * @param {(node: PmNode, path: number[], parent: PmNode|null) => void|false} visitor
 */
export function walk(root, visitor) {
    const doc = normalizeDocument(root);

    const visit = (node, path, parent) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        const cont = visitor(node, path, parent);
        if (cont === false) {
            return;
        }
        const children = Array.isArray(node.content) ? node.content : [];
        children.forEach((child, index) => visit(child, path.concat(index), node));
    };

    (doc.content || []).forEach((child, index) => visit(child, [index], doc));
}

/**
 * Memoized index for one document snapshot.
 *
 * @param {PmDoc|PmNode|null|undefined} doc
 */
export function createDocumentModel(doc) {
    const normalized = normalizeDocument(doc);
    /** @type {null | {
     *   plainText: string,
     *   plainTextEligible: string,
     *   headings: Array<{level:number,text:string,node:PmNode}>,
     *   links: Array<{href:string,text:string,marks:unknown[]}>,
     *   images: Array<{src:string,alt:string,node:PmNode}>,
     *   paragraphs: PmNode[],
     *   blockquotes: PmNode[],
     *   lists: PmNode[],
     *   tables: PmNode[],
     * }} */
    let cache = null;

    const build = () => {
        if (cache) {
            return cache;
        }

        const headings = [];
        const links = [];
        const images = [];
        const paragraphs = [];
        const blockquotes = [];
        const lists = [];
        const tables = [];
        const textParts = [];
        const eligibleParts = [];

        walk(normalized, (node) => {
            const type = String(node.type ?? '');

            if (type === 'text' && typeof node.text === 'string') {
                textParts.push(node.text);
                // Exclude link-only chrome? Keep all text nodes for body word count.
                eligibleParts.push(node.text);
                const marks = Array.isArray(node.marks) ? node.marks : [];
                marks.forEach((mark) => {
                    if (mark?.type === 'link') {
                        const href = String(mark.attrs?.href ?? '').trim();
                        if (href !== '') {
                            links.push({
                                href,
                                text: String(node.text ?? ''),
                                marks,
                            });
                        }
                    }
                });
                return;
            }

            if (type === 'heading') {
                const level = Math.max(1, Number(node.attrs?.level) || 2);
                headings.push({
                    level,
                    text: collectText(node),
                    node,
                });
                return;
            }

            if (type === 'paragraph') {
                paragraphs.push(node);
                const cls = String(node.attrs?.class ?? '');
                if (cls.includes('omi-faq-placeholder') || cls.includes('article-cta')) {
                    // Still count as paragraph; FAQ/CTA selectors pick separately.
                }
                return;
            }

            if (type === 'blockquote') {
                blockquotes.push(node);
                return;
            }

            if (type === 'bulletList' || type === 'orderedList') {
                lists.push(node);
                return;
            }

            if (type === 'table') {
                tables.push(node);
                return;
            }

            if (type === 'image' || type === 'articleImage') {
                images.push({
                    src: String(node.attrs?.src ?? node.attrs?.url ?? '').trim(),
                    alt: String(node.attrs?.alt ?? '').trim(),
                    node,
                });
                // Do not recurse into image for eligible prose (no alt inflation).
                return false;
            }

            if (type === 'hardBreak') {
                textParts.push(' ');
                eligibleParts.push(' ');
            }

            return undefined;
        });

        cache = {
            plainText: textParts.join('').replace(/\s+/g, ' ').trim(),
            plainTextEligible: eligibleParts.join('').replace(/\s+/g, ' ').trim(),
            headings,
            links,
            images,
            paragraphs,
            blockquotes,
            lists,
            tables,
        };

        return cache;
    };

    return {
        /** @returns {PmDoc} */
        json() {
            return normalized;
        },
        walk(visitor) {
            walk(normalized, visitor);
        },
        plainText() {
            return build().plainText;
        },
        /** Eligible article prose (excludes image alt via skip image children). */
        plainTextEligible() {
            return build().plainTextEligible;
        },
        wordCount({ eligible = true } = {}) {
            const text = eligible ? build().plainTextEligible : build().plainText;
            return countWordsInPlainText(text);
        },
        headings() {
            return build().headings.slice();
        },
        links() {
            return build().links.slice();
        },
        images() {
            return build().images.slice();
        },
        paragraphs() {
            return build().paragraphs.slice();
        },
        blockquotes() {
            return build().blockquotes.slice();
        },
        lists() {
            return build().lists.slice();
        },
        tables() {
            return build().tables.slice();
        },
        findNode(predicate) {
            let found = null;
            walk(normalized, (node, path, parent) => {
                if (found) {
                    return false;
                }
                if (predicate(node, path, parent)) {
                    found = { node, path, parent };
                    return false;
                }
                return undefined;
            });
            return found;
        },
        /** Invalidate memo after in-place mutation (rare). */
        invalidate() {
            cache = null;
        },
    };
}

/**
 * @param {PmNode|null|undefined} node
 * @returns {string}
 */
export function collectText(node) {
    if (!node || typeof node !== 'object') {
        return '';
    }
    if (typeof node.text === 'string') {
        return node.text;
    }
    if (!Array.isArray(node.content)) {
        return '';
    }

    return node.content.map((child) => {
        if (child?.type === 'hardBreak') {
            return ' ';
        }

        return collectText(child);
    }).join('');
}

/**
 * @param {string} text
 * @returns {number}
 */
export function countWordsInPlainText(text) {
    const value = String(text ?? '').replace(/\s+/g, ' ').trim();
    if (value === '') {
        return 0;
    }
    const matches = value.match(/[\p{L}][\p{L}\p{N}\-]*/gu);

    return matches ? matches.length : 0;
}

/**
 * First N words of eligible plain text (intro checks).
 *
 * @param {ReturnType<typeof createDocumentModel>} model
 * @param {number} wordLimit
 */
export function sliceFirstWordsFromModel(model, wordLimit) {
    const text = model.plainTextEligible();
    const matches = text.match(/[\p{L}][\p{L}\p{N}\-]*/gu) ?? [];

    return matches.slice(0, Math.max(1, wordLimit)).join(' ');
}

export default {
    normalizeDocument,
    walk,
    createDocumentModel,
    collectText,
    countWordsInPlainText,
    sliceFirstWordsFromModel,
};
