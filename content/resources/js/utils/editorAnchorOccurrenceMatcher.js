/**
 * Exact/normalized anchor occurrence matcher for Editor Domain Link suggestions.
 * No soft/proximity/semantic matching — phrase must appear as contiguous tokens.
 *
 * Suggestion eligibility (before Link Assistant display):
 * - skip occurrences inside h1–h6
 * - skip occurrences that span multiple text/formatting nodes
 * Only single-segment, non-heading hits remain — same constraint as reliable
 * highlight (surroundContents) / wrap. Does not broaden the highlighter.
 */

import { normalizePhraseForMatch, normalizeHrefForCompare } from './articleLinkSuggestionFilter.js';

const HEADING_CLOSE_RE = /^\/h[1-6]$/i;
const HEADING_OPEN_RE = /^h[1-6]\b/i;
const ANCHOR_CLOSE_RE = /^\/a$/i;
const ANCHOR_OPEN_RE = /^a\b/i;

/**
 * @param {Node|null|undefined} node
 * @returns {boolean}
 */
function isInsideHeadingNode(node) {
    const el = node?.nodeType === 3 ? node.parentElement : /** @type {Element|null} */ (node);
    return Boolean(el?.closest?.('h1,h2,h3,h4,h5,h6'));
}

/**
 * @param {Node|null|undefined} node
 * @returns {boolean}
 */
function isInsideAnchorNode(node) {
    const el = node?.nodeType === 3 ? node.parentElement : /** @type {Element|null} */ (node);
    return Boolean(el?.closest?.('a[href]'));
}

/**
 * DOM path — unlinked text-join indexing (same as insert/highlight) plus
 * single-text-node + non-heading eligibility. Self-contained to keep node --test
 * free of extensionless articlePlainTextRange imports.
 *
 * @param {string} html
 * @param {string} phrase
 * @param {number} matchIndex
 * @returns {boolean|null} null when DOM path unavailable
 */
function isEligibleSuggestionOccurrenceDom(html, phrase, matchIndex) {
    if (typeof DOMParser === 'undefined') {
        return null;
    }

    try {
        const doc = new DOMParser().parseFromString(String(html), 'text/html');
        const root = doc.body;
        if (!root) {
            return false;
        }

        const target = String(phrase ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
        if (target === '') {
            return false;
        }

        /** @type {Text[]} */
        const textNodes = [];
        const walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode(textNode) {
                if (!textNode.textContent?.length) {
                    return NodeFilter.FILTER_REJECT;
                }
                if (isInsideAnchorNode(textNode)) {
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_ACCEPT;
            },
        });

        let current = walker.nextNode();
        while (current) {
            textNodes.push(/** @type {Text} */ (current));
            current = walker.nextNode();
        }

        if (textNodes.length === 0) {
            return false;
        }

        const parts = textNodes.map((node) => node.textContent ?? '');
        const lowerFull = parts.join('').toLowerCase();

        let searchFrom = 0;
        let found = 0;
        let startIdx = -1;

        while (searchFrom <= lowerFull.length) {
            const idx = lowerFull.indexOf(target, searchFrom);
            if (idx === -1) {
                break;
            }
            if (found === matchIndex) {
                startIdx = idx;
                break;
            }
            found += 1;
            searchFrom = idx + Math.max(1, target.length);
        }

        if (startIdx < 0) {
            return false;
        }

        const endIdx = startIdx + target.length;
        let pos = 0;
        /** @type {Text|null} */
        let startNode = null;
        /** @type {Text|null} */
        let endNode = null;

        for (let i = 0; i < textNodes.length; i += 1) {
            const partStart = pos;
            const partEnd = pos + parts[i].length;
            if (startNode === null && startIdx >= partStart && startIdx < partEnd) {
                startNode = textNodes[i];
            }
            if (endIdx > partStart && endIdx <= partEnd) {
                endNode = textNodes[i];
                break;
            }
            pos = partEnd;
        }

        if (!startNode || !endNode || startNode !== endNode) {
            return false;
        }
        if (isInsideHeadingNode(startNode)) {
            return false;
        }
        return true;
    } catch {
        return null;
    }
}

/**
 * No-DOM mirror of unlinked text-join indexing + single-segment / non-heading checks.
 *
 * @param {string} html
 * @param {string} phrase
 * @param {number} matchIndex
 * @returns {boolean}
 */
function isEligibleSuggestionOccurrenceNoDom(html, phrase, matchIndex) {
    const target = String(phrase ?? '').replace(/\s+/g, ' ').trim().toLowerCase();
    if (target === '') {
        return false;
    }

    const source = String(html ?? '');
    /** @type {Array<{ text: string, inHeading: boolean }>} */
    const segments = [];
    let inAnchor = 0;
    let headingDepth = 0;
    let i = 0;

    const pushText = (raw) => {
        if (inAnchor > 0 || raw === '') {
            return;
        }
        segments.push({
            text: raw,
            inHeading: headingDepth > 0,
        });
    };

    while (i < source.length) {
        if (source[i] === '<') {
            const close = source.indexOf('>', i + 1);
            if (close === -1) {
                break;
            }
            const inner = source.slice(i + 1, close).trim();
            if (inner.startsWith('!--')) {
                const endComment = source.indexOf('-->', i + 4);
                i = endComment === -1 ? source.length : endComment + 3;
                continue;
            }
            if (ANCHOR_OPEN_RE.test(inner) && !inner.endsWith('/')) {
                inAnchor += 1;
            } else if (ANCHOR_CLOSE_RE.test(inner)) {
                inAnchor = Math.max(0, inAnchor - 1);
            } else if (HEADING_OPEN_RE.test(inner) && !inner.endsWith('/')) {
                headingDepth += 1;
            } else if (HEADING_CLOSE_RE.test(inner)) {
                headingDepth = Math.max(0, headingDepth - 1);
            }
            i = close + 1;
            continue;
        }

        const nextTag = source.indexOf('<', i);
        const end = nextTag === -1 ? source.length : nextTag;
        pushText(source.slice(i, end));
        i = end;
    }

    if (segments.length === 0) {
        return false;
    }

    const parts = segments.map((row) => row.text);
    const lowerFull = parts.join('').toLowerCase();

    let searchFrom = 0;
    let found = 0;
    let startIdx = -1;

    while (searchFrom <= lowerFull.length) {
        const idx = lowerFull.indexOf(target, searchFrom);
        if (idx === -1) {
            break;
        }
        if (found === matchIndex) {
            startIdx = idx;
            break;
        }
        found += 1;
        searchFrom = idx + Math.max(1, target.length);
    }

    if (startIdx < 0) {
        return false;
    }

    const endIdx = startIdx + target.length;
    let pos = 0;
    let startSeg = -1;
    let endSeg = -1;

    for (let s = 0; s < parts.length; s += 1) {
        const partStart = pos;
        const partEnd = pos + parts[s].length;
        if (startSeg < 0 && startIdx >= partStart && startIdx < partEnd) {
            startSeg = s;
        }
        if (endIdx > partStart && endIdx <= partEnd) {
            endSeg = s;
            break;
        }
        pos = partEnd;
    }

    if (startSeg < 0 || endSeg < 0 || startSeg !== endSeg) {
        return false;
    }

    return !segments[startSeg].inHeading;
}

/**
 * True when the Nth unlinked plain-text occurrence can be highlighted/inserted
 * reliably: complete phrase in one text node, not inside h1–h6.
 * Preserves matchIndex identity used by wrapPlainTextWithLink / scroll highlight.
 *
 * @param {string} html
 * @param {string} phrase
 * @param {number} matchIndex
 * @returns {boolean}
 */
export function isEligibleSuggestionOccurrence(html, phrase, matchIndex) {
    const target = String(phrase ?? '').replace(/\s+/g, ' ').trim();
    const index = Math.max(0, Number(matchIndex) || 0);
    if (target === '' || String(html ?? '').trim() === '') {
        return false;
    }

    const domResult = isEligibleSuggestionOccurrenceDom(html, target, index);
    if (domResult !== null) {
        return domResult;
    }

    return isEligibleSuggestionOccurrenceNoDom(html, target, index);
}

/**
 * @param {string} text
 * @returns {string}
 */
function normalizeExactPhrase(text) {
    const value = String(text ?? '');
    const nfc = typeof value.normalize === 'function' ? value.normalize('NFC') : value;
    return normalizePhraseForMatch(nfc);
}

/**
 * @param {string} html
 * @returns {string}
 */
export function plainTextExcludingAnchors(html) {
    const source = String(html ?? '');
    if (source.trim() === '') {
        return '';
    }

    try {
        const doc = new DOMParser().parseFromString(source, 'text/html');
        doc.querySelectorAll('a').forEach((node) => {
            node.replaceWith(doc.createTextNode(' '));
        });
        return String(doc.body?.textContent ?? '')
            .normalize('NFC')
            .replace(/\s+/g, ' ')
            .trim();
    } catch {
        const withoutLinks = source.replace(/<a\b[^>]*>[\s\S]*?<\/a>/gi, ' ');
        return withoutLinks
            .replace(/<[^>]+>/g, ' ')
            .normalize('NFC')
            .replace(/\s+/g, ' ')
            .trim();
    }
}

/**
 * @param {string} plain
 * @returns {Array<{ raw: string, norm: string, start: number, end: number }>}
 */
export function tokenizeExactAnchorPlain(plain) {
    const text = String(plain ?? '');
    /** @type {Array<{ raw: string, norm: string, start: number, end: number }>} */
    const tokens = [];
    const re = /[\p{L}\p{N}]+/gu;
    let match = re.exec(text);
    while (match) {
        const raw = match[0];
        const norm = normalizeExactPhrase(raw);
        if (norm !== '') {
            tokens.push({
                raw,
                norm,
                start: match.index,
                end: match.index + raw.length,
            });
        }
        match = re.exec(text);
    }
    return tokens;
}

/**
 * @param {Array<{ raw: string, norm: string, start: number, end: number }>} tokens
 * @param {string} phrase
 * @returns {Array<{ from: number, to: number, matchedText: string, score: number, level: 'exact' }>}
 */
export function findExactAnchorOccurrences(tokens, phrase) {
    const needle = normalizeExactPhrase(phrase);
    if (needle === '' || tokens.length === 0) {
        return [];
    }
    const needleTokens = needle.split(' ').filter(Boolean);
    if (needleTokens.length === 0) {
        return [];
    }

    const haystack = tokens.map((row) => row.norm);
    /** @type {Array<{ from: number, to: number, matchedText: string, score: number, level: 'exact' }>} */
    const out = [];

    for (let i = 0; i <= haystack.length - needleTokens.length; i += 1) {
        let ok = true;
        for (let j = 0; j < needleTokens.length; j += 1) {
            if (haystack[i + j] !== needleTokens[j]) {
                ok = false;
                break;
            }
        }
        if (!ok) {
            continue;
        }
        const from = tokens[i].start;
        const to = tokens[i + needleTokens.length - 1].end;
        const matchedParts = [];
        for (let j = 0; j < needleTokens.length; j += 1) {
            matchedParts.push(tokens[i + j].raw);
        }
        out.push({
            from,
            to,
            matchedText: matchedParts.join(' '),
            score: 1000 - (to - from),
            level: 'exact',
        });
    }

    return out;
}

/**
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {string} phrase
 * @param {number} [maxCount=50]
 * @returns {Array<{ blockId: string, blockIndex: number, from: number, to: number, matchedText: string, score: number, level: 'exact', matchIndex: number, phrase: string }>}
 */
export function findExactAnchorOccurrencesInBlocks(blocks, phrase, maxCount = 50) {
    const limit = Number.isFinite(maxCount) && maxCount > 0 ? Math.floor(maxCount) : 50;
    const list = Array.isArray(blocks) ? blocks : [];
    /** @type {Array<{ blockId: string, blockIndex: number, from: number, to: number, matchedText: string, score: number, level: 'exact', matchIndex: number, phrase: string }>} */
    const out = [];

    for (let blockIndex = 0; blockIndex < list.length; blockIndex += 1) {
        const block = list[blockIndex];
        if (block?.type === 'image') {
            continue;
        }
        const blockId = String(block?.id ?? '').trim();
        if (blockId === '') {
            continue;
        }
        const plain = plainTextExcludingAnchors(block?.content ?? '');
        if (plain === '') {
            continue;
        }
        const html = String(block?.content ?? '');
        const tokens = tokenizeExactAnchorPlain(plain);
        const local = findExactAnchorOccurrences(tokens, phrase);
        for (let matchIndex = 0; matchIndex < local.length; matchIndex += 1) {
            const row = local[matchIndex];
            // Keep original unlinked matchIndex (insert/highlight SSOT). Drop only
            // ineligible hits (heading / cross-formatting-node) before suggestions.
            if (!isEligibleSuggestionOccurrence(html, row.matchedText, matchIndex)) {
                continue;
            }
            out.push({
                ...row,
                blockId,
                blockIndex,
                matchIndex,
                phrase: row.matchedText,
            });
            if (out.length >= limit) {
                return out;
            }
        }
    }

    return out;
}

/**
 * Group catalog rows by destination; keep every known anchor phrase.
 *
 * @param {Array<Record<string, unknown>>} links
 * @returns {Map<string, { href: string, anchors: Map<string, { phrase: string, item: Record<string, unknown> }> }>}
 */
export function groupCatalogAnchorsByDestination(links) {
    /** @type {Map<string, { href: string, anchors: Map<string, { phrase: string, item: Record<string, unknown> }> }>} */
    const byHref = new Map();

    for (const item of Array.isArray(links) ? links : []) {
        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();
        if (text === '' || href === '') {
            continue;
        }
        const hrefKey = normalizeHrefForCompare(href) || href.toLowerCase();
        const anchorKey = normalizeExactPhrase(text);
        if (anchorKey === '') {
            continue;
        }
        if (!byHref.has(hrefKey)) {
            byHref.set(hrefKey, { href, anchors: new Map() });
        }
        const group = byHref.get(hrefKey);
        if (!group.anchors.has(anchorKey)) {
            group.anchors.set(anchorKey, { phrase: text, item });
        }
    }

    return byHref;
}

/**
 * @param {{ blockIndex?: number }} row
 * @returns {number}
 */
function documentBlockIndex(row) {
    const value = Number(row?.blockIndex);
    return Number.isFinite(value) ? value : Number.MAX_SAFE_INTEGER;
}

/**
 * Occurrence-based longest-match-wins across all destination anchors in one block stream.
 * Document order uses blocks[] index — never blockId lexical order.
 *
 * @param {Array<{
 *   blockId: string,
 *   blockIndex?: number,
 *   from: number,
 *   to: number,
 *   matchedText: string,
 *   score?: number,
 *   level?: string,
 *   matchIndex?: number,
 *   phrase?: string,
 *   hrefKey: string,
 *   href: string,
 *   item: Record<string, unknown>,
 * }>} matches
 * @returns {typeof matches}
 */
export function selectLongestNonOverlappingMatches(matches) {
    const rows = Array.isArray(matches) ? [...matches] : [];
    // Prefer longer phrases first within the same start, then keep document order after.
    rows.sort((a, b) => {
        const blockDelta = documentBlockIndex(a) - documentBlockIndex(b);
        if (blockDelta !== 0) {
            return blockDelta;
        }
        if (a.from !== b.from) {
            return a.from - b.from;
        }
        return (b.to - b.from) - (a.to - a.from);
    });

    /** @type {typeof matches} */
    const selected = [];
    for (const row of rows) {
        const overlaps = selected.some(
            (kept) => kept.blockId === row.blockId
                && !(row.to <= kept.from || row.from >= kept.to),
        );
        if (overlaps) {
            continue;
        }
        selected.push(row);
    }

    return selected.sort((a, b) => {
        const blockDelta = documentBlockIndex(a) - documentBlockIndex(b);
        if (blockDelta !== 0) {
            return blockDelta;
        }
        return a.from - b.from;
    });
}

/**
 * Build actionable Domain Link suggestion rows for the existing UI.
 *
 * @param {Array<Record<string, unknown>>} catalogLinks
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @returns {Array<Record<string, unknown> & { occurrence_count: number, can_insert: boolean, _domain_occurrences: unknown[], _domain_candidate_id: string }>}
 */
export function buildActionableDomainLinkSuggestions(catalogLinks, blocks) {
    const groups = groupCatalogAnchorsByDestination(catalogLinks);
    /** @type {Array<{
     *   blockId: string,
     *   blockIndex: number,
     *   from: number,
     *   to: number,
     *   matchedText: string,
     *   score: number,
     *   level: string,
     *   matchIndex: number,
     *   phrase: string,
     *   hrefKey: string,
     *   href: string,
     *   item: Record<string, unknown>,
     * }>} */
    const allMatches = [];

    for (const [hrefKey, group] of groups.entries()) {
        for (const [, meta] of group.anchors.entries()) {
            const hits = findExactAnchorOccurrencesInBlocks(blocks, meta.phrase);
            for (const hit of hits) {
                allMatches.push({
                    ...hit,
                    hrefKey,
                    href: group.href,
                    item: meta.item,
                });
            }
        }
    }

    const selected = selectLongestNonOverlappingMatches(allMatches);

    /** @type {Map<string, {
     *   item: Record<string, unknown>,
     *   href: string,
     *   matchedText: string,
     *   occurrences: Array<Record<string, unknown>>,
     * }>} */
    const byDedupe = new Map();

    for (const match of selected) {
        const matchedText = String(match.matchedText ?? '').trim();
        const matchedKey = normalizeExactPhrase(matchedText);
        if (matchedKey === '') {
            continue;
        }
        const dedupeKey = `${match.hrefKey}|${matchedKey}`;
        const occurrence = {
            blockId: match.blockId,
            blockIndex: documentBlockIndex(match),
            from: match.from,
            to: match.to,
            matchedText,
            score: match.score ?? 1000,
            level: 'exact',
            matchIndex: match.matchIndex ?? 0,
            phrase: matchedText,
        };

        if (!byDedupe.has(dedupeKey)) {
            byDedupe.set(dedupeKey, {
                item: match.item,
                href: match.href,
                matchedText,
                occurrences: [occurrence],
            });
            continue;
        }

        byDedupe.get(dedupeKey).occurrences.push(occurrence);
    }

    const out = [];
    let index = 0;
    for (const row of byDedupe.values()) {
        const href = String(row.href ?? row.item?.href ?? row.item?.target_url ?? '').trim();
        const occurrences = [...row.occurrences]
            .sort((a, b) => {
                const blockDelta = documentBlockIndex(a) - documentBlockIndex(b);
                if (blockDelta !== 0) {
                    return blockDelta;
                }
                return (Number(a.from) || 0) - (Number(b.from) || 0);
            });
        // Keep each occurrence.matchIndex as the local unlinked index inside its block
        // (required by wrapPlainTextWithLink). Array order = document order for UI cycling.
        out.push({
            ...row.item,
            text: row.matchedText,
            href,
            target_url: String(row.item?.target_url ?? href).trim() || href,
            matched_phrase: row.matchedText,
            occurrence_count: occurrences.length,
            can_insert: row.item?.can_insert !== false && href !== '',
            _domain_occurrences: occurrences,
            _domain_candidate_id: `domain:${href || row.matchedText || index}`,
        });
        index += 1;
    }

    return out;
}
