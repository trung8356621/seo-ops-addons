/**
 * Exact/normalized anchor occurrence matcher for Editor Domain Link suggestions.
 * No soft/proximity/semantic matching — phrase must appear as contiguous tokens.
 */

import { normalizePhraseForMatch, normalizeHrefForCompare } from './articleLinkSuggestionFilter.js';

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
 * @returns {Array<{ blockId: string, from: number, to: number, matchedText: string, score: number, level: 'exact', matchIndex: number, phrase: string }>}
 */
export function findExactAnchorOccurrencesInBlocks(blocks, phrase, maxCount = 50) {
    const limit = Number.isFinite(maxCount) && maxCount > 0 ? Math.floor(maxCount) : 50;
    /** @type {Array<{ blockId: string, from: number, to: number, matchedText: string, score: number, level: 'exact', matchIndex: number, phrase: string }>} */
    const out = [];

    for (const block of Array.isArray(blocks) ? blocks : []) {
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
        const tokens = tokenizeExactAnchorPlain(plain);
        const local = findExactAnchorOccurrences(tokens, phrase);
        for (let matchIndex = 0; matchIndex < local.length; matchIndex += 1) {
            const row = local[matchIndex];
            out.push({
                ...row,
                blockId,
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
 * Occurrence-based longest-match-wins across all destination anchors in one block stream.
 *
 * @param {Array<{
 *   blockId: string,
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
    rows.sort((a, b) => {
        const blockDelta = String(a.blockId).localeCompare(String(b.blockId));
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

    return selected;
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
        const occurrences = row.occurrences.map((occ, matchIndex) => ({
            ...occ,
            matchIndex,
        }));
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
