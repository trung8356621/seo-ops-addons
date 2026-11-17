/**
 * Plan auto-split of one paragraph into 2–3-sentence paragraphs.
 * Preserves inline marks via PM fragment slicing + existing HTML serialize.
 */

import { isSplittableTextblock, textblockDepth } from './headingSplitEngine.js';
import { serializePmNodeToHtml } from './canonicalArticleBlockSplit.js';

const ABBREVIATIONS = new Set([
    'mr', 'mrs', 'ms', 'dr', 'prof', 'sr', 'jr', 'vs', 'etc', 'approx',
    'tp', 'hcm', 'hn', 'đl', 'bn', 'tp.hcm', 'tp.hn',
    'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
    'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
]);

const UNSAFE_TEXT_RE = /\[\/?seo_ops_cta\b|\[\/?faq\b|\[\/?\w[\w-]*\b[^\]]*\]|<\/?(?:table|pre|code|script|iframe)\b/i;

/**
 * @param {string} text
 * @param {number} dotIndex index of `.` `!` or `?`
 * @returns {boolean}
 */
export function isSafeSentenceBoundary(text, dotIndex) {
    const source = String(text ?? '');
    const ch = source[dotIndex];
    if (ch !== '.' && ch !== '!' && ch !== '?') {
        return false;
    }

    const before = source.slice(0, dotIndex);
    const after = source.slice(dotIndex + 1);

    // Decimal: 3.5
    if (ch === '.' && /\d$/u.test(before) && /^\d/u.test(after)) {
        return false;
    }

    // Ellipsis / multiple punctuation stay together
    if (/^[.!?]/u.test(after)) {
        return false;
    }

    // Email local@domain.tld — boundary inside domain
    if (ch === '.' && /[^\s@]+@[^\s@]*$/u.test(before) && /^[a-z0-9-]/iu.test(after)) {
        return false;
    }

    // URL / domain-ish: word.word without space before next capital/letter that continues host
    if (ch === '.') {
        const hostLeft = before.match(/(?:https?:\/\/|www\.)?[a-z0-9-]+$/iu);
        const hostRight = after.match(/^[a-z0-9-]+(?:\/|\.|\?|#|$)/iu);
        if (hostLeft && hostRight && !/^\s/u.test(after)) {
            // Allow sentence end when next char after spaces is uppercase Vietnamese/Latin.
            // Bare host continuation (no space) is unsafe.
            return false;
        }
    }

    // Abbreviation: "Dr." "Tp." or dotted initials "U.S." / "A.B."
    if (ch === '.') {
        const word = (before.match(/([A-Za-zÀ-ỹĐđ.]{1,12})$/u) || [])[1] || '';
        const normalized = word.replace(/\.$/u, '').toLowerCase();
        if (ABBREVIATIONS.has(normalized) || /^[a-zà-ỹđ](\.[a-zà-ỹđ])+\.?$/iu.test(word)) {
            return false;
        }
    }

    // Numbered list marker "1. " at start or after newline — not a sentence end when very short
    if (ch === '.' && /(?:^|\n)\s*\d{1,3}$/u.test(before) && /^\s/u.test(after)) {
        return false;
    }

    // Require whitespace or end after terminator (allow closing quotes)
    if (after !== '' && !/^[\s"'”’)\]]/u.test(after)) {
        return false;
    }

    return true;
}

/**
 * @param {string} text
 * @returns {number[]} absolute character offsets AFTER the terminator (inclusive end of sentence)
 */
export function findSentenceEndOffsets(text) {
    const source = String(text ?? '');
    /** @type {number[]} */
    const ends = [];
    for (let i = 0; i < source.length; i += 1) {
        const ch = source[i];
        if (ch !== '.' && ch !== '!' && ch !== '?') {
            continue;
        }
        // Consume trailing !?… and closing quotes
        let end = i;
        while (end + 1 < source.length && /[.!?]/u.test(source[end + 1])) {
            end += 1;
        }
        while (end + 1 < source.length && /["'”’)\]]/u.test(source[end + 1])) {
            end += 1;
        }
        if (!isSafeSentenceBoundary(source, i)) {
            i = end;
            continue;
        }
        ends.push(end + 1);
        i = end;
    }
    if (ends.length === 0 || ends[ends.length - 1] < source.length) {
        const trimmedEnd = source.replace(/\s+$/u, '').length;
        if (trimmedEnd > 0 && (ends.length === 0 || ends[ends.length - 1] < trimmedEnd)) {
            ends.push(trimmedEnd);
        }
    }
    return ends;
}

/**
 * Group sentence end offsets into chunks of 2–3 sentences.
 * @param {number[]} ends
 * @param {number} [targetSize=2]
 * @returns {number[]} chunk end offsets
 */
export function groupSentenceEnds(ends, targetSize = 2) {
    const list = Array.isArray(ends) ? ends.filter((n) => Number.isFinite(n) && n > 0) : [];
    if (list.length <= 1) {
        return list.slice();
    }
    const size = Math.min(3, Math.max(2, Number(targetSize) || 2));
    /** @type {number[]} */
    const chunks = [];
    for (let i = size - 1; i < list.length; i += size) {
        chunks.push(list[i]);
    }
    const last = list[list.length - 1];
    if (chunks.length === 0 || chunks[chunks.length - 1] !== last) {
        // Remainder of 1 sentence: merge into previous chunk when possible
        if (chunks.length > 0 && list.length % size === 1) {
            chunks[chunks.length - 1] = last;
        } else {
            chunks.push(last);
        }
    }
    return chunks;
}

/**
 * Map plain-text offsets inside a textblock to content positions (0..content.size).
 * @param {import('@tiptap/pm/model').Node} parent
 * @returns {{ text: string, map: number[] }} map[textOffset] => contentPos
 */
function buildTextContentMap(parent) {
    let text = '';
    /** @type {number[]} */
    const map = [0];
    let contentPos = 0;
    parent.forEach((child) => {
        const name = String(child.type?.name ?? '');
        if (child.isText) {
            const value = String(child.text ?? '');
            for (let i = 0; i < value.length; i += 1) {
                contentPos += 1;
                text += value[i];
                map.push(contentPos);
            }
            return;
        }
        if (name === 'hardBreak' || name === 'hard_break') {
            contentPos += 1;
            text += '\n';
            map.push(contentPos);
            return;
        }
        // Non-text inline (emoji node, mention, etc.): keep as opaque single char placeholder
        const size = child.nodeSize;
        contentPos += size;
        text += '\uFFFC';
        map.push(contentPos);
    });
    return { text, map };
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

/**
 * @param {import('@tiptap/pm/state').EditorState} state
 * @param {{ sentencesPerParagraph?: number }} [options]
 */
export function planSentenceParagraphSplit(state, options = {}) {
    const { selection } = state;
    const $from = selection.$from;
    const depth = textblockDepth($from);
    const parent = depth > 0 ? $from.node(depth) : null;
    const meta = {
        mode: 'sentences',
        parentType: parent?.type?.name ?? null,
        parentOffset: depth > 0 ? selection.from - $from.start(depth) : 0,
        parentSize: parent?.content?.size ?? 0,
        sentenceCount: 0,
        chunkCount: 0,
    };

    if (!parent || parent.type?.name !== 'paragraph' || !isSplittableTextblock(parent)) {
        return fail('unsupported_block', meta);
    }

    if (listItemDepthSafe($from) > 0) {
        return fail('inside_list', meta);
    }

    const parentPos = $from.before(depth);
    const start = $from.start(depth);
    const end = $from.end(depth);
    const htmlProbe = serializePmNodeToHtml(parent);
    if (UNSAFE_TEXT_RE.test(htmlProbe) || UNSAFE_TEXT_RE.test(parent.textContent || '')) {
        return fail('unsafe_content', {
            ...meta,
            disableReason: 'shortcode_or_special',
        });
    }

    // Reject paragraphs that contain non-inline structural children beyond hardBreak
    let hasUnsafeChild = false;
    parent.forEach((child) => {
        const name = String(child.type?.name ?? '');
        if (!child.isText && name !== 'hardBreak' && name !== 'hard_break' && name !== 'emoji') {
            // allow image? no — disable
            if (name !== 'text') {
                hasUnsafeChild = true;
            }
        }
    });
    if (hasUnsafeChild) {
        return fail('unsafe_content', { ...meta, disableReason: 'complex_inline' });
    }

    const { text, map } = buildTextContentMap(parent);
    const ends = findSentenceEndOffsets(text);
    meta.sentenceCount = ends.length;
    if (ends.length <= 1) {
        return fail('no_change', meta);
    }

    const chunks = groupSentenceEnds(ends, options.sentencesPerParagraph ?? 2);
    meta.chunkCount = chunks.length;
    if (chunks.length <= 1) {
        return fail('no_change', meta);
    }

    /** @type {string[]} */
    const contents = [];
    let prevTextOffset = 0;
    for (const endOffset of chunks) {
        const fromContent = map[prevTextOffset] ?? 0;
        const toContent = map[Math.min(endOffset, map.length - 1)] ?? parent.content.size;
        const slice = parent.cut(fromContent, toContent);
        const html = serializePmNodeToHtml(
            state.schema.nodes.paragraph.create(parent.attrs, slice.content),
        );
        if (html && html.replace(/<[^>]+>/g, '').replace(/\s+/g, '') !== '') {
            contents.push(html);
        }
        // Skip whitespace between sentences for next start
        let next = endOffset;
        while (next < text.length && /\s/u.test(text[next])) {
            next += 1;
        }
        prevTextOffset = next;
    }

    if (contents.length <= 1) {
        return fail('no_change', meta);
    }

    return {
        ok: true,
        reason: null,
        contents,
        focusIndex: 0,
        meta: {
            ...meta,
            from: start,
            to: end,
            parentPos,
            sourceHtml: htmlProbe,
        },
    };
}

function listItemDepthSafe($pos) {
    for (let depth = $pos.depth; depth > 0; depth -= 1) {
        const name = String($pos.node(depth)?.type?.name ?? '');
        if (name === 'listItem' || name === 'list_item') {
            return depth;
        }
    }
    return 0;
}

/**
 * @param {import('@tiptap/pm/state').EditorState} state
 * @returns {{ canSplit: boolean, reason: string|null }}
 */
export function canSplitParagraphBySentences(state) {
    const plan = planSentenceParagraphSplit(state);
    return {
        canSplit: plan.ok === true,
        reason: plan.ok ? null : (plan.meta?.disableReason || plan.reason || 'unsupported'),
    };
}

export default {
    canSplitParagraphBySentences,
    findSentenceEndOffsets,
    groupSentenceEnds,
    isSafeSentenceBoundary,
    planSentenceParagraphSplit,
};
