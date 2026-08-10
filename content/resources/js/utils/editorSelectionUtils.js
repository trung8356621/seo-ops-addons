import { DOMSerializer } from '@tiptap/pm/model';
import { TextSelection } from '@tiptap/pm/state';
import { isCtaPlainTextType } from './ctaLinkFormat';
import { normalizedLinkAttrs } from './inlineLinkNormalizer';
import { resolveBookmarkSelection } from './editorInsertionContext';
import { SEO_EDITOR_LINK_CLASS } from './articleEditorTransientMarkup';

/**
 * HTML của vùng đang bôi đen trong TipTap (dùng tách FAQ thủ công).
 */
export function getSelectionHtmlFromEditor(editor) {
    if (!editor?.state) {
        return '';
    }

    const { from, to, empty } = editor.state.selection;
    if (empty || to <= from) {
        return '';
    }

    const slice = editor.state.doc.slice(from, to);
    const serializer = DOMSerializer.fromSchema(editor.schema);
    const fragment = serializer.serializeFragment(slice.content);
    const wrap = document.createElement('div');
    wrap.appendChild(fragment);

    return wrap.innerHTML.trim();
}

export function getSelectionTextFromEditor(editor) {
    if (!editor?.state) {
        return '';
    }

    const { from, to, empty } = editor.state.selection;
    if (empty || to <= from) {
        return '';
    }

    return editor.state.doc.textBetween(from, to, '\n\n').trim();
}

/**
 * Restore bookmark when present; otherwise keep live caret.
 * NEVER force doc end when a valid bookmark or live selection exists.
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {{ from: number, to: number, docSize?: number }|null|undefined} bookmark
 * @returns {import('@tiptap/core').ChainedCommands}
 */
function chainWithInsertionTarget(editor, bookmark) {
    const resolved = resolveBookmarkSelection(editor, bookmark);
    if (resolved) {
        // setTextSelection BEFORE focus — plain focus() after blur can jump to end.
        return editor.chain()
            .setTextSelection({ from: resolved.from, to: resolved.to })
            .focus();
    }

    const { from, to } = editor.state.selection;
    return editor.chain()
        .setTextSelection({ from, to })
        .focus();
}

/**
 * Collapse selection to caret after insert; clear link stored marks.
 *
 * @param {import('@tiptap/core').ChainedCommands} chain
 * @returns {import('@tiptap/core').ChainedCommands}
 */
function chainCollapseCaretAfterInsert(chain) {
    return chain.command(({ tr, dispatch }) => {
        if (!dispatch) {
            return true;
        }
        const to = tr.selection.to;
        tr.setSelection(TextSelection.create(tr.doc, to));
        tr.setStoredMarks([]);
        dispatch(tr);
        return true;
    });
}

/**
 * Shared inline insert at bookmark. Keeps parent (paragraph / quote / list / cell).
 * Never lift(), setParagraph(), article-cta block, or append to doc end.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {Array<{ text: string, href?: string|null }>} parts
 * @param {{ from: number, to: number, docSize?: number }|null} [bookmark]
 * @returns {boolean}
 */
export function insertInlinePartsAtBookmark(editor, parts, bookmark = null) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const inlineContent = (Array.isArray(parts) ? parts : [])
        .map((part) => {
            const text = String(part?.text ?? '');
            if (text === '') {
                return null;
            }
            const href = String(part?.href ?? '').trim();
            if (href) {
                return {
                    type: 'text',
                    text,
                    marks: [
                        {
                            type: 'link',
                            attrs: {
                                ...normalizedLinkAttrs(href),
                                class: SEO_EDITOR_LINK_CLASS,
                            },
                        },
                    ],
                };
            }
            return { type: 'text', text };
        })
        .filter(Boolean);

    if (inlineContent.length === 0) {
        return false;
    }

    let chain = chainWithInsertionTarget(editor, bookmark);
    const resolved = resolveBookmarkSelection(editor, bookmark);
    if (resolved && resolved.to > resolved.from) {
        chain = chain.deleteRange({ from: resolved.from, to: resolved.to });
    } else if (!bookmark) {
        const { from, to, empty } = editor.state.selection;
        if (!empty && to > from) {
            chain = chain.deleteRange({ from, to });
        }
    }

    // Do NOT unsetAllMarks / lift / setParagraph — preserve quote/list/heading parent.
    return chainCollapseCaretAfterInsert(
        chain.insertContent(inlineContent),
    ).run();
}

/**
 * Raw contact value at caret — INLINE only.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} label
 * @param {string} href
 * @param {string} [type]
 * @param {{ from: number, to: number, docSize?: number }|null} [bookmark]
 * @returns {boolean}
 */
export function insertContactValueAtBookmark(editor, label, href, type = '', bookmark = null) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const text = String(label ?? '').trim();
    if (!text) {
        return false;
    }

    const plainText = isCtaPlainTextType(type) || !String(href ?? '').trim();
    if (plainText) {
        return insertInlinePartsAtBookmark(editor, [{ text }], bookmark);
    }

    return insertInlinePartsAtBookmark(editor, [{ text, href }], bookmark);
}

/**
 * Canonical CTA insert from contact sidebar.
 * Inserts article-cta paragraph at bookmark (splits parent textblock; no lift/focus-end).
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {{
 *   contactType?: string,
 *   type?: string,
 *   contactValue?: string,
 *   label?: string,
 *   href?: string,
 *   sentence?: string,
 *   valueLabel?: string,
 *   templateKey?: string|null,
 *   bookmark?: { from: number, to: number, docSize?: number }|null,
 * }} opts
 * @returns {boolean}
 */
/**
 * Position after enclosing blockquote/list (or current textblock) for block-level CTA.
 * @param {import('@tiptap/core').Editor} editor
 * @param {number} from
 * @returns {number}
 */
export function resolveInsertionAfterEnclosingBlock(editor, from) {
    const docSize = Number(editor?.state?.doc?.content?.size ?? 0);
    const safeFrom = Math.max(0, Math.min(Number(from) || 0, docSize));
    try {
        const $pos = editor.state.doc.resolve(safeFrom);
        for (let depth = $pos.depth; depth > 0; depth -= 1) {
            const name = String($pos.node(depth)?.type?.name ?? '');
            if (
                name === 'blockquote'
                || name === 'bulletList'
                || name === 'orderedList'
                || name === 'table'
            ) {
                return $pos.after(depth);
            }
        }
        for (let depth = $pos.depth; depth > 0; depth -= 1) {
            const node = $pos.node(depth);
            if (node?.isTextblock) {
                return $pos.after(depth);
            }
        }
    } catch {
        // fall through
    }
    return safeFrom;
}

export function insertContactCtaAtBookmark(editor, opts = {}) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const type = String(opts.contactType ?? opts.type ?? '').toLowerCase();
    const valueLabel = String(opts.valueLabel ?? opts.contactValue ?? opts.label ?? '').trim();
    const sentence = String(opts.sentence ?? '').trim();
    const href = String(opts.href ?? '').trim();
    const bookmark = opts.bookmark ?? null;
    const plainText = isCtaPlainTextType(type) || !href;

    let labelPart = '';
    let linkedPart = valueLabel;
    let suffixPart = '';

    if (sentence && valueLabel && sentence.includes(valueLabel)) {
        const idx = sentence.indexOf(valueLabel);
        labelPart = sentence.slice(0, idx).trim();
        linkedPart = valueLabel;
        suffixPart = sentence.slice(idx + valueLabel.length);
    } else if (sentence) {
        labelPart = '';
        linkedPart = sentence;
    }

    const safeType = type.replace(/[^a-z0-9_-]/gi, '') || 'cta';
    const safeLabel = labelPart.trim();
    const safeValue = String(linkedPart || valueLabel || sentence).trim();
    if (!safeValue) {
        return false;
    }

    const labelNeedsColon = safeLabel !== '' && !/[:：]$/.test(safeLabel);
    const labelText = safeLabel
        ? `${safeLabel}${labelNeedsColon ? ':' : ''} `
        : '';

    /** @type {Array<Record<string, unknown>>} */
    const inlineContent = [];
    if (labelText) {
        inlineContent.push({
            type: 'text',
            text: labelText,
            marks: [{ type: 'bold' }],
        });
    }
    if (plainText || !href) {
        inlineContent.push({ type: 'text', text: safeValue });
    } else {
        inlineContent.push({
            type: 'text',
            text: safeValue,
            marks: [
                {
                    type: 'link',
                    attrs: {
                        ...normalizedLinkAttrs(href),
                        class: `${SEO_EDITOR_LINK_CLASS} article-cta__value`.trim(),
                    },
                },
            ],
        });
    }
    if (suffixPart) {
        inlineContent.push({ type: 'text', text: suffixPart });
    }

    const ctaNode = {
        type: 'paragraph',
        attrs: {
            class: 'article-cta',
            'data-cta-type': safeType,
        },
        content: inlineContent,
    };
    const trailParagraph = { type: 'paragraph' };

    const resolved = resolveBookmarkSelection(editor, bookmark);
    const docSize = Number(editor.state.doc?.content?.size ?? 0);
    const caretFrom = resolved
        ? resolved.from
        : Math.max(0, Math.min(editor.state.selection.from, docSize));
    // Block-level CTA: after enclosing quote/list/table (never nested inside).
    const insertPos = resolveInsertionAfterEnclosingBlock(editor, caretFrom);

    return editor
        .chain()
        .focus()
        .command(({ tr, commands, state }) => {
            const pos = Math.max(0, Math.min(insertPos, state.doc.content.size));
            return commands.insertContentAt(
                pos,
                [ctaNode, trailParagraph],
                { updateSelection: false },
            );
        })
        .command(({ tr, dispatch }) => {
            if (!dispatch) {
                return true;
            }
            const scanFrom = Math.max(0, Math.min(insertPos, tr.doc.content.size));
            let caretPos = null;
            tr.doc.nodesBetween(scanFrom, tr.doc.content.size, (node, pos) => {
                if (
                    node.type.name === 'paragraph'
                    && String(node.attrs?.class ?? '').includes('article-cta')
                ) {
                    caretPos = pos + node.nodeSize + 1;
                    return false;
                }
                return true;
            });
            const maxPos = tr.doc.content.size;
            const safePos = Math.max(1, Math.min(caretPos ?? maxPos - 1, maxPos));
            try {
                tr.setSelection(TextSelection.near(tr.doc.resolve(safePos), 1));
            } catch {
                tr.setSelection(TextSelection.near(tr.doc.resolve(Math.max(1, maxPos - 1)), -1));
            }
            tr.setStoredMarks([]);
            dispatch(tr.scrollIntoView());
            return true;
        })
        .run();
}

/**
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} text
 * @param {{ from: number, to: number, docSize?: number }|null} [bookmark]
 * @returns {boolean}
 */
export function insertTextInEditor(editor, text, bookmark = null) {
    const value = String(text ?? '');
    if (!value) {
        return false;
    }

    return insertInlinePartsAtBookmark(editor, [{ text: value }], bookmark);
}

/**
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} label
 * @param {string} href
 * @param {{ from: number, to: number, docSize?: number }|null} [bookmark]
 * @returns {boolean}
 */
export function insertLinkInEditor(editor, label, href, bookmark = null) {
    const linkLabel = String(label ?? '').trim();
    const linkHref = String(href ?? '').trim();
    if (!linkLabel || !linkHref) {
        return false;
    }

    return insertInlinePartsAtBookmark(editor, [{ text: linkLabel, href: linkHref }], bookmark);
}

/**
 * Insert arbitrary HTML at bookmark / caret.
 *
 * @param {import('@tiptap/core').Editor|null|undefined} editor
 * @param {string} html
 * @param {{ from: number, to: number, docSize?: number }|null} [bookmark]
 * @returns {boolean}
 */
export function insertHtmlInEditor(editor, html, bookmark = null) {
    if (!editor?.state || editor.isDestroyed) {
        return false;
    }

    const value = String(html ?? '').trim();
    if (!value) {
        return false;
    }

    // Strip legacy article-cta wrapper — insert as inline/plain content only.
    const inlineHtml = value
        .replace(/<\/?p\b[^>]*>/giu, '')
        .replace(/\sclass\s*=\s*["']article-cta["']/giu, '')
        .replace(/\sdata-cta-type\s*=\s*["'][^"']*["']/giu, '')
        .trim();

    let chain = chainWithInsertionTarget(editor, bookmark);
    const resolved = resolveBookmarkSelection(editor, bookmark);
    if (resolved && resolved.to > resolved.from) {
        chain = chain.deleteRange({ from: resolved.from, to: resolved.to });
    }

    return chainCollapseCaretAfterInsert(
        chain.insertContent(inlineHtml || value),
    ).run();
}
