/**
 * Permanent merge of Shift-selected paragraph blocks into one paragraph.
 * Uses the block list model; joins paragraph inners via DOM when available.
 * Kept free of editorHtmlUtils so node:test can import without Vite aliases.
 */

const SPECIAL_BLOCK_RE =
    /<(?:h[1-6]|ul|ol|table|thead|tbody|tr|td|th|blockquote|pre|img|figure|figcaption|video|iframe|hr)\b|omi-faq-placeholder|\[omi_faq\]|seo-managed-cta|\[seo_ops_cta\b|\[\/seo_ops_cta\]/i;

const FAQ_PLACEHOLDER_RE = /omi-faq-placeholder|\[omi_faq\]/i;
const STANDALONE_HEADING_RE = /^<h([1-6])\b[^>]*>[\s\S]*<\/h\1>\s*(?:<p\b[^>]*>\s*<\/p>\s*)?$/i;

function isFaqPlaceholderHtml(html) {
    return FAQ_PLACEHOLDER_RE.test(String(html ?? ''));
}

function isCanonicalLockedHeadingHtml(html) {
    const trimmed = String(html ?? '').trim();
    if (!trimmed) {
        return false;
    }
    const match = trimmed.match(STANDALONE_HEADING_RE);
    if (!match) {
        return false;
    }
    const level = Number(match[1]);
    return level >= 2 && level <= 4;
}

/**
 * @param {string} html
 * @returns {boolean}
 */
function htmlLooksLikeSpecial(html) {
    return SPECIAL_BLOCK_RE.test(String(html ?? ''));
}

/**
 * @param {string} html
 * @returns {boolean}
 */
function endsWithSpaceish(html) {
    const text = String(html ?? '')
        .replace(/<[^>]+>/g, '')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\u00a0/g, ' ');
    return text.length === 0 || /\s$/u.test(text);
}

/**
 * @param {string} html
 * @returns {boolean}
 */
function startsWithSpaceish(html) {
    const text = String(html ?? '')
        .replace(/<[^>]+>/g, '')
        .replace(/&nbsp;/gi, ' ')
        .replace(/\u00a0/g, ' ');
    return text.length === 0 || /^\s/u.test(text);
}

/**
 * @param {string} leftInner
 * @param {string} rightInner
 * @returns {boolean}
 */
export function needsParagraphJoinSpace(leftInner, rightInner) {
    if (!String(leftInner ?? '').trim() || !String(rightInner ?? '').trim()) {
        return false;
    }
    return !endsWithSpaceish(leftInner) && !startsWithSpaceish(rightInner);
}

/**
 * @param {string[]} inners
 * @returns {string}
 */
export function joinParagraphInners(inners) {
    const parts = (Array.isArray(inners) ? inners : [])
        .map((part) => String(part ?? ''))
        .filter((part) => part.replace(/<br\s*\/?>/gi, '').replace(/&nbsp;/gi, ' ').trim() !== '');

    if (parts.length === 0) {
        return '<p></p>';
    }

    let merged = parts[0];
    for (let i = 1; i < parts.length; i += 1) {
        const next = parts[i];
        merged += (needsParagraphJoinSpace(merged, next) ? ' ' : '') + next;
    }

    return `<p>${merged}</p>`;
}

/**
 * @param {string} html
 * @returns {{ ok: boolean, inner: string, reason: string|null }}
 */
export function extractMergeableParagraphInner(html) {
    const trimmed = String(html ?? '').trim();
    if (!trimmed) {
        return { ok: true, inner: '', reason: null };
    }
    if (isFaqPlaceholderHtml(trimmed) || isCanonicalLockedHeadingHtml(trimmed) || htmlLooksLikeSpecial(trimmed)) {
        return { ok: false, inner: '', reason: 'special_block' };
    }

    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString(trimmed, 'text/html');
        const nodes = Array.from(doc.body.childNodes).filter((node) => {
            if (node.nodeType === 3) {
                return Boolean(String(node.textContent ?? '').replace(/\u00a0/g, ' ').trim());
            }
            if (node.nodeType !== 1) {
                return false;
            }
            const tag = node.tagName.toLowerCase();
            if (tag === 'br') {
                return false;
            }
            if (tag === 'p' || tag === 'div') {
                const text = String(node.textContent ?? '').replace(/\u00a0/g, ' ').trim();
                const inner = String(node.innerHTML ?? '')
                    .replace(/<br\s*\/?>/gi, '')
                    .replace(/&nbsp;/gi, ' ')
                    .trim();
                return Boolean(text || inner);
            }
            return true;
        });

        if (nodes.length === 0) {
            return { ok: true, inner: '', reason: null };
        }
        if (nodes.length !== 1 || nodes[0].nodeType !== 1) {
            return { ok: false, inner: '', reason: 'special_block' };
        }

        const tag = nodes[0].tagName.toLowerCase();
        if (tag !== 'p' && tag !== 'div') {
            return { ok: false, inner: '', reason: 'special_block' };
        }
        if (htmlLooksLikeSpecial(nodes[0].innerHTML || '')) {
            return { ok: false, inner: '', reason: 'special_block' };
        }

        return { ok: true, inner: String(nodes[0].innerHTML ?? ''), reason: null };
    }

    const match = trimmed.match(/^<(?:p|div)\b[^>]*>([\s\S]*)<\/(?:p|div)>$/i);
    if (!match) {
        return { ok: false, inner: '', reason: 'special_block' };
    }
    if (htmlLooksLikeSpecial(match[1])) {
        return { ok: false, inner: '', reason: 'special_block' };
    }

    return { ok: true, inner: match[1], reason: null };
}

/**
 * @param {object} block
 * @returns {{ ok: boolean, reason: string|null }}
 */
export function classifyMergeableParagraphBlock(block) {
    if (!block) {
        return { ok: false, reason: 'special_block' };
    }
    if (String(block.type || '') === 'image') {
        return { ok: false, reason: 'special_block' };
    }
    if (String(block.type || 'text') !== 'text') {
        return { ok: false, reason: 'special_block' };
    }

    const extracted = extractMergeableParagraphInner(block.content);
    if (!extracted.ok) {
        return { ok: false, reason: extracted.reason || 'special_block' };
    }

    return { ok: true, reason: null };
}

/**
 * @param {{
 *   blocks: Array<object>,
 *   rangeIds: string[],
 *   sectionByBlockId?: Map<string, string>|Record<string, string>|null,
 * }} input
 * @returns {{
 *   ok: boolean,
 *   reason: string|null,
 *   reasonKey: string|null,
 *   orderedBlocks: Array<object>,
 *   keepBlockId: string|null,
 * }}
 */
export function evaluateMergeSelection({ blocks, rangeIds, sectionByBlockId = null } = {}) {
    const list = Array.isArray(blocks) ? blocks : [];
    const ids = (Array.isArray(rangeIds) ? rangeIds : [])
        .map((id) => String(id ?? '').trim())
        .filter(Boolean);

    if (ids.length < 2) {
        return {
            ok: false,
            reason: 'need_two',
            reasonKey: 'editor_merge_need_two',
            orderedBlocks: [],
            keepBlockId: null,
        };
    }

    const indexById = new Map(list.map((block, index) => [String(block?.id ?? ''), index]));
    const indexes = ids.map((id) => indexById.get(id));
    if (indexes.some((index) => index == null || !Number.isFinite(index))) {
        return {
            ok: false,
            reason: 'missing',
            reasonKey: 'editor_merge_not_contiguous',
            orderedBlocks: [],
            keepBlockId: null,
        };
    }

    const sortedIndexes = [...indexes].sort((a, b) => a - b);
    const uniqueSorted = [...new Set(sortedIndexes)];
    if (uniqueSorted.length !== ids.length) {
        return {
            ok: false,
            reason: 'duplicate',
            reasonKey: 'editor_merge_not_contiguous',
            orderedBlocks: [],
            keepBlockId: null,
        };
    }

    for (let i = 1; i < uniqueSorted.length; i += 1) {
        if (uniqueSorted[i] !== uniqueSorted[i - 1] + 1) {
            return {
                ok: false,
                reason: 'not_contiguous',
                reasonKey: 'editor_merge_not_contiguous',
                orderedBlocks: [],
                keepBlockId: null,
            };
        }
    }

    const orderedBlocks = uniqueSorted.map((index) => list[index]);

    const resolveSection = (blockId) => {
        if (!sectionByBlockId) {
            return null;
        }
        if (typeof sectionByBlockId.get === 'function') {
            return sectionByBlockId.get(String(blockId)) ?? null;
        }
        return sectionByBlockId[String(blockId)] ?? null;
    };

    if (sectionByBlockId) {
        const sections = orderedBlocks.map((block) => resolveSection(block.id));
        if (sections.some((section) => section == null) || new Set(sections).size > 1) {
            return {
                ok: false,
                reason: 'cross_section',
                reasonKey: 'editor_merge_cross_section',
                orderedBlocks,
                keepBlockId: orderedBlocks[0]?.id ?? null,
            };
        }
    }

    for (const block of orderedBlocks) {
        const classified = classifyMergeableParagraphBlock(block);
        if (!classified.ok) {
            return {
                ok: false,
                reason: classified.reason || 'special_block',
                reasonKey: 'editor_merge_special_blocks',
                orderedBlocks,
                keepBlockId: orderedBlocks[0]?.id ?? null,
            };
        }
    }

    return {
        ok: true,
        reason: null,
        reasonKey: null,
        orderedBlocks,
        keepBlockId: orderedBlocks[0]?.id ?? null,
    };
}

/**
 * @param {Array<object>} rangeBlocks
 * @returns {string}
 */
export function mergeParagraphBlocksHtml(rangeBlocks) {
    const inners = (Array.isArray(rangeBlocks) ? rangeBlocks : []).map((block) => {
        const extracted = extractMergeableParagraphInner(block?.content);
        return extracted.ok ? extracted.inner : '';
    });

    if (typeof DOMParser !== 'undefined') {
        const doc = new DOMParser().parseFromString('<p></p>', 'text/html');
        const paragraph = doc.body.querySelector('p');
        if (paragraph) {
            inners.forEach((inner, index) => {
                if (!inner) {
                    return;
                }
                if (index > 0 && needsParagraphJoinSpace(paragraph.innerHTML, inner)) {
                    paragraph.appendChild(doc.createTextNode(' '));
                }
                const frag = new DOMParser().parseFromString(`<div>${inner}</div>`, 'text/html');
                const host = frag.body.firstElementChild;
                while (host?.firstChild) {
                    paragraph.appendChild(host.firstChild);
                }
            });
            return paragraph.outerHTML;
        }
    }

    return joinParagraphInners(inners);
}

/**
 * @param {Array<object>} blocks
 * @param {string[]} rangeIds
 * @param {{ sectionByBlockId?: Map<string, string>|Record<string, string>|null }} [options]
 * @returns {{
 *   ok: boolean,
 *   reason: string|null,
 *   reasonKey: string|null,
 *   blocks: Array<object>,
 *   keepBlockId: string|null,
 *   removedIds: string[],
 * }}
 */
export function applyMergeParagraphBlocks(blocks, rangeIds, options = {}) {
    const evaluation = evaluateMergeSelection({
        blocks,
        rangeIds,
        sectionByBlockId: options.sectionByBlockId ?? null,
    });

    if (!evaluation.ok) {
        return {
            ok: false,
            reason: evaluation.reason,
            reasonKey: evaluation.reasonKey,
            blocks: Array.isArray(blocks) ? blocks : [],
            keepBlockId: null,
            removedIds: [],
        };
    }

    const ordered = evaluation.orderedBlocks;
    const keepBlockId = String(ordered[0].id);
    const removedIds = ordered.slice(1).map((block) => String(block.id));
    const removeSet = new Set(removedIds);
    const mergedHtml = mergeParagraphBlocksHtml(ordered);
    const epoch = Date.now();

    const next = (Array.isArray(blocks) ? blocks : [])
        .filter((block) => !removeSet.has(String(block?.id ?? '')))
        .map((block) => {
            if (String(block?.id ?? '') !== keepBlockId) {
                return block;
            }
            return {
                ...block,
                content: mergedHtml,
                editorDocument: undefined,
                editor_document: undefined,
                editorEpoch: epoch,
            };
        });

    return {
        ok: true,
        reason: null,
        reasonKey: null,
        blocks: next,
        keepBlockId,
        removedIds,
    };
}

export default {
    applyMergeParagraphBlocks,
    classifyMergeableParagraphBlock,
    evaluateMergeSelection,
    extractMergeableParagraphInner,
    joinParagraphInners,
    mergeParagraphBlocksHtml,
    needsParagraphJoinSpace,
};
