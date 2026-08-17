/**
 * Plan article-block splits from a ProseMirror selection.
 * Does not dispatch a transaction. Host replaces blocks[] atomically.
 */

import { isSplittableTextblock, textblockDepth } from './headingSplitEngine.js';

const TITLE_SEPARATOR_RE = /^[\s]*([–—\-:：])(?:\s+|(?=\S))/u;

/**
 * @param {string} text
 * @returns {{ text: string, stripped: boolean }}
 */
export function stripLeadingTitleSeparator(text) {
    const source = String(text ?? '');
    const match = TITLE_SEPARATOR_RE.exec(source);
    if (!match) {
        return { text: source, stripped: false };
    }

    return {
        text: source.slice(match[0].length),
        stripped: true,
    };
}

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function markName(mark) {
    return String(mark?.type?.name ?? mark?.type ?? '');
}

function serializeMarks(textHtml, marks) {
    let html = textHtml;
    const list = [];
    if (marks && typeof marks.forEach === 'function') {
        marks.forEach((mark) => list.push(mark));
    } else if (Array.isArray(marks)) {
        list.push(...marks);
    }

    for (const mark of list) {
        const name = markName(mark);
        if (name === 'bold' || name === 'strong') {
            html = `<strong>${html}</strong>`;
        } else if (name === 'italic' || name === 'em') {
            html = `<em>${html}</em>`;
        } else if (name === 'underline') {
            html = `<u>${html}</u>`;
        } else if (name === 'strike' || name === 'strikeThrough') {
            html = `<s>${html}</s>`;
        } else if (name === 'code') {
            html = `<code>${html}</code>`;
        } else if (name === 'link') {
            const href = escapeHtml(mark.attrs?.href ?? '');
            html = `<a href="${href}">${html}</a>`;
        }
    }

    return html;
}

function serializeInline(fragment) {
    if (!fragment || fragment.size === 0) {
        return '';
    }

    let html = '';
    fragment.forEach((child) => {
        if (child.isText) {
            html += serializeMarks(escapeHtml(child.text), child.marks);
            return;
        }
        const name = String(child.type?.name ?? '');
        if (name === 'hardBreak' || name === 'hard_break') {
            html += '<br>';
            return;
        }
        html += serializePmNodeToHtml(child);
    });

    return html;
}

/**
 * @param {import('@tiptap/pm/model').Node} node
 * @returns {string}
 */
export function serializePmNodeToHtml(node) {
    if (!node) {
        return '';
    }
    if (node.isText) {
        return serializeMarks(escapeHtml(node.text), node.marks);
    }

    const name = String(node.type?.name ?? '');
    const inner = node.content ? serializeInline(node.content) : '';

    if (name === 'paragraph') {
        return `<p>${inner}</p>`;
    }
    if (name === 'heading') {
        const level = Math.min(6, Math.max(1, Number(node.attrs?.level) || 2));
        return `<h${level}>${inner}</h${level}>`;
    }
    if (name === 'blockquote') {
        return `<blockquote>${inner}</blockquote>`;
    }
    if (name === 'bulletList' || name === 'bullet_list') {
        return `<ul>${inner}</ul>`;
    }
    if (name === 'orderedList' || name === 'ordered_list') {
        return `<ol>${inner}</ol>`;
    }
    if (name === 'listItem' || name === 'list_item') {
        return `<li>${inner}</li>`;
    }
    if (name === 'hardBreak' || name === 'hard_break') {
        return '<br>';
    }
    if (name === 'image') {
        const src = escapeHtml(node.attrs?.src ?? '');
        const alt = escapeHtml(node.attrs?.alt ?? '');
        return src ? `<img src="${src}" alt="${alt}">` : '';
    }
    if (name === 'doc') {
        return serializeBlockNodes(topLevelNodes(node));
    }

    return inner;
}

function topLevelNodes(doc) {
    const nodes = [];
    doc.forEach((child) => {
        nodes.push(child);
    });

    return nodes;
}

function serializeBlockNodes(nodes) {
    return (nodes ?? []).map((node) => serializePmNodeToHtml(node)).join('');
}

function fragmentText(fragment) {
    if (!fragment || fragment.size === 0) {
        return '';
    }

    return fragment.textBetween(0, fragment.size, '');
}

function isEmptyInline(fragment) {
    if (!fragment || fragment.size === 0) {
        return true;
    }

    let hasAtom = false;
    fragment.forEach((child) => {
        const name = String(child.type?.name ?? '');
        if (!child.isText && name !== 'hardBreak' && name !== 'hard_break') {
            hasAtom = true;
        }
    });
    if (hasAtom) {
        return false;
    }

    return fragmentText(fragment).replace(/\s+/g, '').length === 0;
}

function isEmptyBlockNode(node) {
    if (!node) {
        return true;
    }
    if (node.type?.name === 'image') {
        return false;
    }

    return isEmptyInline(node.content);
}

function headingHtml(level, text) {
    const safeLevel = Math.min(4, Math.max(2, Number(level) || 3));
    const body = escapeHtml(String(text ?? '').replace(/\s+/g, ' ').trim());

    return `<h${safeLevel}>${body}</h${safeLevel}>`;
}

function paragraphHtmlFromInline(schema, fragment) {
    if (isEmptyInline(fragment)) {
        return '';
    }
    const node = schema.nodes.paragraph.create(null, fragment);

    return serializePmNodeToHtml(node);
}

function stripSeparatorFromInline(schema, fragment) {
    if (!fragment || fragment.size === 0 || !schema?.text) {
        return { fragment, stripped: false };
    }

    const children = [];
    fragment.forEach((child) => children.push(child));
    const first = children[0];
    if (!first?.isText) {
        return { fragment, stripped: false };
    }

    const result = stripLeadingTitleSeparator(first.text);
    if (!result.stripped) {
        return { fragment, stripped: false };
    }

    const next = [];
    if (result.text !== '') {
        next.push(schema.text(result.text, first.marks));
    }
    for (let index = 1; index < children.length; index += 1) {
        next.push(children[index]);
    }

    const wrapped = schema.nodes.paragraph.create(null, next.length > 0 ? next : undefined);

    return { fragment: wrapped.content, stripped: true };
}

function fail(reason, meta) {
    return {
        ok: false,
        reason,
        contents: [],
        focusIndex: null,
        meta,
    };
}

function serializeDocRange(doc, from, to) {
    const size = doc.content.size;
    const start = Math.max(0, Math.min(Number(from) || 0, size));
    const end = Math.max(start, Math.min(Number(to) || start, size));
    if (end <= start) {
        return '';
    }

    return serializePmNodeToHtml(doc.cut(start, end)).trim();
}

function isBlankHtml(html) {
    const text = String(html ?? '')
        .replace(/<br\s*\/?>/gi, '')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    return text === '';
}

/**
 * @param {string} html
 * @returns {{ html: string, stripped: boolean }}
 */
export function stripLeadingTitleSeparatorHtml(html) {
    const source = String(html ?? '');
    const next = source.replace(/^(<p\b[^>]*>)(\s*)([–—\-:：])(?:\s+|(?=\S))/u, '$1');
    if (next === source) {
        return { html: source, stripped: false };
    }

    return { html: isBlankHtml(next) ? '' : next, stripped: true };
}

function finishPlan(mode, beforeHtml, middleHtml, afterHtml, meta) {
    const contents = [beforeHtml, middleHtml, afterHtml].filter((html) => html && !isBlankHtml(html));
    if (contents.length === 0) {
        return fail('empty_result', meta);
    }
    if ((mode === 'paragraph' || mode === 'cursor') && contents.length < 2) {
        return fail('no_change', meta);
    }

    let focusIndex = contents.length - 1;
    if (mode === 'heading') {
        const headingIndex = beforeHtml && !isBlankHtml(beforeHtml) ? 1 : 0;
        focusIndex = afterHtml && !isBlankHtml(afterHtml) ? headingIndex + 1 : null;
    } else if (mode === 'paragraph') {
        focusIndex = beforeHtml && !isBlankHtml(beforeHtml) ? 1 : 0;
    } else {
        focusIndex = beforeHtml && !isBlankHtml(beforeHtml) ? 1 : 0;
    }

    return {
        ok: true,
        reason: null,
        contents,
        focusIndex,
        meta,
    };
}

/**
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {{ mode?: 'heading'|'paragraph'|'cursor', level?: number }} [options]
 */
export function planCanonicalArticleBlockSplit(state, options = {}) {
    const { selection, doc } = state;
    const { from, to, empty } = selection;
    const $from = selection.$from;
    const depth = textblockDepth($from);
    const parent = depth > 0 ? $from.node(depth) : null;
    const parentOffset = depth > 0 ? from - $from.start(depth) : 0;
    const parentSize = parent?.content?.size ?? 0;
    const mode = options.mode === 'heading' ? 'heading' : (options.mode === 'cursor' ? 'cursor' : 'paragraph');

    const meta = {
        from,
        to,
        empty,
        parentType: parent?.type?.name ?? null,
        parentOffset,
        parentSize,
        textBefore: '',
        selectedText: empty ? '' : doc.textBetween(from, to, ' '),
        textAfter: '',
        mode,
        level: Number(options.level) || 3,
        splitAt: from,
    };

    if (mode === 'heading' && empty) {
        return fail('selection_required', meta);
    }

    if (mode === 'cursor' && !empty) {
        return planCanonicalArticleBlockSplit(state, { ...options, mode: 'paragraph' });
    }

    if (mode === 'heading' || mode === 'paragraph') {
        if (empty || to <= from) {
            return fail('empty_slice', meta);
        }
        const beforeHtml = serializeDocRange(doc, 0, from);
        let afterHtml = serializeDocRange(doc, to, doc.content.size);
        let stripped = false;
        if (mode === 'heading') {
            const strippedAfter = stripLeadingTitleSeparatorHtml(afterHtml);
            afterHtml = strippedAfter.html;
            stripped = strippedAfter.stripped;
        }
        meta.textBefore = beforeHtml.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        meta.textAfter = afterHtml.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        meta.separatorStripped = stripped;
        const middleHtml = mode === 'heading'
            ? headingHtml(options.level, meta.selectedText)
            : serializeDocRange(doc, from, to);
        if (mode === 'heading' && String(meta.selectedText ?? '').replace(/\s+/g, ' ').trim() === '') {
            return fail('empty_heading', meta);
        }

        return finishPlan(mode, beforeHtml, middleHtml, afterHtml, meta);
    }

    let splitAt = from;
    if (parent && isSplittableTextblock(parent)) {
        const parentPos = $from.before(depth);
        const atStart = parentOffset <= 0;
        const atEnd = parentOffset >= parentSize;
        const hasPrev = parentPos > 0;
        const hasNext = (parentPos + parent.nodeSize) < doc.content.size;
        if (atStart && hasPrev) {
            splitAt = parentPos;
        } else if (atEnd && hasNext) {
            splitAt = parentPos + parent.nodeSize;
        } else if (atStart || atEnd) {
            return fail('boundary', meta);
        } else {
            splitAt = from;
        }
    } else if (from > 0 && from < doc.content.size) {
        splitAt = from;
    } else {
        return fail('not_textblock', meta);
    }

    meta.splitAt = splitAt;
    const beforeHtml = serializeDocRange(doc, 0, splitAt);
    const afterHtml = serializeDocRange(doc, splitAt, doc.content.size);
    meta.textBefore = beforeHtml.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    meta.textAfter = afterHtml.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();

    return finishPlan('cursor', beforeHtml, '', afterHtml, meta);
}

/**
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {{ empty?: boolean }} [snapshot]
 */
export function canSplitIntoCanonicalBlocks(state, snapshot = {}) {
    const empty = snapshot.empty == null ? Boolean(state?.selection?.empty) : snapshot.empty === true;
    const plan = planCanonicalArticleBlockSplit(state, { mode: empty ? 'cursor' : 'paragraph' });

    return Boolean(plan.ok && plan.contents.length >= 2);
}

export default {
    canSplitIntoCanonicalBlocks,
    planCanonicalArticleBlockSplit,
    serializePmNodeToHtml,
    stripLeadingTitleSeparator,
    stripLeadingTitleSeparatorHtml,
};
