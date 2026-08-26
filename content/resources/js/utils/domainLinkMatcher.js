/**
 * Soft lexical Domain Link matcher (isolated from Internal Links).
 *
 * Levels: exact phrase > contiguous meaningful phrase > token proximity.
 * Accent-insensitive is fallback only (lower score).
 */

import {
    foldDomainLinkAccents,
    meaningfulDomainLinkTokens,
    normalizeDomainLinkText,
    plainTextFromHtmlForDomainLink,
} from './domainLinkTextNormalizer.js';

/** @typedef {{ blockId?: string, from: number, to: number, matchedText: string, score: number, level: 'exact'|'contiguous'|'proximity'|'accent' }} DomainLinkOccurrence */

export const DOMAIN_LINK_MAX_TOKEN_SPAN = 12;
export const DOMAIN_LINK_MIN_COVERAGE_3PLUS = 0.65;

/**
 * @param {string} plain
 * @returns {Array<{ raw: string, normalized: string, folded: string, start: number, end: number }>}
 */
export function tokenizePlainWithOffsets(plain) {
    const text = String(plain ?? '');
    /** @type {Array<{ raw: string, normalized: string, folded: string, start: number, end: number }>} */
    const tokens = [];
    const re = /[\p{L}\p{N}]+/gu;
    let match = re.exec(text);
    while (match) {
        const raw = match[0];
        const normalized = normalizeDomainLinkText(raw);
        if (normalized !== '') {
            tokens.push({
                raw,
                normalized,
                folded: foldDomainLinkAccents(raw),
                start: match.index,
                end: match.index + raw.length,
            });
        }
        match = re.exec(text);
    }
    return tokens;
}

/**
 * @param {string[]} needleTokens
 * @returns {{ requiredHits: number, isSingle: boolean, isPair: boolean }}
 */
function coverageRule(needleTokens) {
    const n = needleTokens.length;
    if (n <= 1) {
        return { requiredHits: 1, isSingle: true, isPair: false, isTriple: false };
    }
    if (n === 2) {
        return { requiredHits: 2, isSingle: false, isPair: true, isTriple: false };
    }
    if (n === 3) {
        // Short category phrases must keep all tokens (avoid "túi"+"giữ" false hits).
        return { requiredHits: 3, isSingle: false, isPair: false, isTriple: true };
    }
    return {
        requiredHits: Math.max(2, Math.ceil(n * DOMAIN_LINK_MIN_COVERAGE_3PLUS)),
        isSingle: false,
        isPair: false,
        isTriple: false,
    };
}

/**
 * @param {string[]} haystackNorm
 * @param {string[]} needleNorm
 * @param {number} start
 * @param {number} endExclusive
 * @returns {{ hits: number, orderPreserved: boolean, accentOnlyHits: number }}
 */
function scoreWindowCoverage(haystackNorm, needleNorm, start, endExclusive, haystackFolded, needleFolded) {
    const available = new Map();
    for (let i = start; i < endExclusive; i += 1) {
        const key = haystackNorm[i];
        available.set(key, (available.get(key) ?? 0) + 1);
    }

    let hits = 0;
    let accentOnlyHits = 0;
    const matchedPositions = [];

    for (let n = 0; n < needleNorm.length; n += 1) {
        const want = needleNorm[n];
        let foundAt = -1;
        for (let i = start; i < endExclusive; i += 1) {
            if (haystackNorm[i] === want && (available.get(want) ?? 0) > 0) {
                foundAt = i;
                break;
            }
        }
        if (foundAt >= 0) {
            hits += 1;
            available.set(want, (available.get(want) ?? 1) - 1);
            matchedPositions.push(foundAt);
            continue;
        }

        const foldedWant = needleFolded[n];
        for (let i = start; i < endExclusive; i += 1) {
            if (haystackFolded[i] === foldedWant) {
                const normAt = haystackNorm[i];
                if ((available.get(normAt) ?? 0) > 0) {
                    hits += 1;
                    accentOnlyHits += 1;
                    available.set(normAt, (available.get(normAt) ?? 1) - 1);
                    matchedPositions.push(i);
                    break;
                }
            }
        }
    }

    let orderPreserved = true;
    for (let i = 1; i < matchedPositions.length; i += 1) {
        if (matchedPositions[i] < matchedPositions[i - 1]) {
            orderPreserved = false;
            break;
        }
    }

    return { hits, orderPreserved, accentOnlyHits };
}

/**
 * Contiguous: needle tokens appear as an unbroken run (allowing only exact consecutive matches).
 *
 * @param {string[]} haystackNorm
 * @param {string[]} needleNorm
 * @returns {number} start index or -1
 */
function findContiguousRun(haystackNorm, needleNorm) {
    if (needleNorm.length === 0) {
        return -1;
    }
    outer: for (let i = 0; i <= haystackNorm.length - needleNorm.length; i += 1) {
        for (let j = 0; j < needleNorm.length; j += 1) {
            if (haystackNorm[i + j] !== needleNorm[j]) {
                continue outer;
            }
        }
        return i;
    }
    return -1;
}

/**
 * Contiguous allowing skipped filler tokens between matched needle tokens (still "near").
 * Used as soft contiguous when exact contiguous fails — e.g. "may túi đựng mỹ phẩm"
 * vs "may nhiều mẫu túi mỹ phẩm".
 *
 * @param {string[]} haystackNorm
 * @param {string[]} needleNorm
 * @param {number} maxSpan
 * @returns {{ start: number, end: number, hits: number }|null}
 */
function findBestSoftContiguous(haystackNorm, needleNorm, maxSpan) {
    const { requiredHits } = coverageRule(needleNorm);
    let best = null;

    for (let start = 0; start < haystackNorm.length; start += 1) {
        let needleIdx = 0;
        let end = start;
        let hits = 0;
        for (let i = start; i < haystackNorm.length && i - start < maxSpan; i += 1) {
            if (needleIdx < needleNorm.length && haystackNorm[i] === needleNorm[needleIdx]) {
                needleIdx += 1;
                hits += 1;
                end = i + 1;
                continue;
            }
            // Allow skipping a needle token (soft) only when later tokens still match.
            if (needleIdx + 1 < needleNorm.length && haystackNorm[i] === needleNorm[needleIdx + 1]) {
                needleIdx += 2;
                hits += 1;
                end = i + 1;
            }
        }

        if (hits < requiredHits) {
            continue;
        }

        const span = end - start;
        if (!best || hits > best.hits || (hits === best.hits && span < best.end - best.start)) {
            best = { start, end, hits };
        }
    }

    return best;
}

/**
 * @param {string} plain
 * @param {string} anchor
 * @returns {DomainLinkOccurrence[]}
 */
export function findDomainLinkOccurrencesInPlainText(plain, anchor) {
    const text = String(plain ?? '');
    const phrase = String(anchor ?? '').trim();
    if (text.trim() === '' || phrase === '') {
        return [];
    }

    const needleTokens = meaningfulDomainLinkTokens(phrase);
    if (needleTokens.length === 0) {
        return [];
    }

    const needleFolded = needleTokens.map((token) => foldDomainLinkAccents(token));
    const normalizedNeedle = normalizeDomainLinkText(phrase);
    const foldedNeedle = foldDomainLinkAccents(phrase);
    const tokens = tokenizePlainWithOffsets(text);
    if (tokens.length === 0) {
        return [];
    }

    const haystackNorm = tokens.map((row) => row.normalized);
    const haystackFolded = tokens.map((row) => row.folded);
    /** @type {DomainLinkOccurrence[]} */
    const occurrences = [];

    const pushOccurrence = (from, to, score, level) => {
        if (from < 0 || to <= from || to > text.length) {
            return;
        }
        const matchedText = text.slice(from, to).replace(/\s+/g, ' ').trim();
        if (matchedText === '') {
            return;
        }
        // Dedup overlapping same span.
        if (occurrences.some((row) => row.from === from && row.to === to)) {
            return;
        }
        occurrences.push({ from, to, matchedText, score, level });
    };

    // Level 1 — exact normalized phrase (accented).
    const normalizedHaystack = normalizeDomainLinkText(text);
    if (normalizedNeedle !== '' && normalizedHaystack.includes(normalizedNeedle)) {
        // Map back via token windows covering the phrase token count.
        const phraseTokenCount = needleTokens.length;
        for (let i = 0; i <= tokens.length - phraseTokenCount; i += 1) {
            const sliceNorm = haystackNorm.slice(i, i + phraseTokenCount).join(' ');
            if (sliceNorm === normalizedNeedle) {
                const from = tokens[i].start;
                const to = tokens[i + phraseTokenCount - 1].end;
                pushOccurrence(from, to, 1000 - (to - from), 'exact');
            }
        }
    }

    // Contiguous meaningful run (all tokens in order, no gaps).
    let searchFrom = 0;
    while (searchFrom <= haystackNorm.length - needleTokens.length) {
        const runStart = findContiguousRun(haystackNorm.slice(searchFrom), needleTokens);
        if (runStart < 0) {
            break;
        }
        const absStart = searchFrom + runStart;
        const absEnd = absStart + needleTokens.length;
        const from = tokens[absStart].start;
        const to = tokens[absEnd - 1].end;
        if (!occurrences.some((row) => row.from === from && row.to === to)) {
            pushOccurrence(from, to, 900 - (to - from), 'contiguous');
        }
        searchFrom = absStart + 1;
    }

    // Soft contiguous / proximity windows.
    const { requiredHits, isSingle, isPair } = coverageRule(needleTokens);
    const maxSpan = Math.min(
        DOMAIN_LINK_MAX_TOKEN_SPAN,
        Math.max(needleTokens.length + 6, needleTokens.length * 3, 8),
    );

    if (isSingle) {
        // 1-token: exact token boundary only (already covered by exact/contiguous).
        // Accent-insensitive single token — weak, only if no accented hit for that span.
        for (let i = 0; i < tokens.length; i += 1) {
            if (haystackFolded[i] === needleFolded[0] && haystackNorm[i] !== needleTokens[0]) {
                pushOccurrence(tokens[i].start, tokens[i].end, 200, 'accent');
            }
        }
    } else {
        for (let start = 0; start < tokens.length; start += 1) {
            const endLimit = Math.min(tokens.length, start + maxSpan);
            for (let end = start + (isPair ? 2 : requiredHits); end <= endLimit; end += 1) {
                const span = end - start;
                if (span > maxSpan) {
                    continue;
                }
                const coverage = scoreWindowCoverage(
                    haystackNorm,
                    needleTokens,
                    start,
                    end,
                    haystackFolded,
                    needleFolded,
                );
                if (coverage.hits < requiredHits) {
                    continue;
                }

                // Pair/triple: reject distant token co-occurrence.
                if (isPair && span > 3 && coverage.hits === 2) {
                    const positions = [];
                    for (let i = start; i < end; i += 1) {
                        if (needleTokens.includes(haystackNorm[i]) || needleFolded.includes(haystackFolded[i])) {
                            positions.push(i);
                        }
                    }
                    if (positions.length >= 2 && positions[positions.length - 1] - positions[0] > 2) {
                        continue;
                    }
                }
                if (coverageRule(needleTokens).isTriple && span > 8) {
                    continue;
                }

                const from = tokens[start].start;
                const to = tokens[end - 1].end;
                const coverageRatio = coverage.hits / needleTokens.length;
                const proximityBonus = Math.max(0, 40 - span * 2);
                const orderBonus = coverage.orderPreserved ? 25 : 0;
                const accentPenalty = coverage.accentOnlyHits * 30;
                const level = coverage.accentOnlyHits > 0 && coverage.hits === coverage.accentOnlyHits
                    ? 'accent'
                    : 'proximity';
                const base = level === 'accent' ? 250 : 500;
                const score = Math.round(
                    base + coverageRatio * 200 + proximityBonus + orderBonus - accentPenalty - span,
                );

                // Skip if exact/contiguous already covers same range.
                if (occurrences.some((row) => row.from <= from && row.to >= to && row.level !== 'proximity' && row.level !== 'accent')) {
                    continue;
                }
                pushOccurrence(from, to, score, level);
            }
        }
    }

    // Soft contiguous helper (fills gaps like "may … túi … mỹ phẩm").
    if (!isSingle && occurrences.filter((row) => row.level === 'exact' || row.level === 'contiguous').length === 0) {
        const soft = findBestSoftContiguous(haystackNorm, needleTokens, maxSpan);
        if (soft) {
            const from = tokens[soft.start].start;
            const to = tokens[soft.end - 1].end;
            if (!occurrences.some((row) => row.from === from && row.to === to)) {
                pushOccurrence(from, to, 700 - (to - from), 'contiguous');
            }
        }
    }

    // Accent-insensitive full phrase fallback (lower than accented).
    if (foldedNeedle !== '' && foldedNeedle !== normalizedNeedle) {
        const foldedHaystack = foldDomainLinkAccents(text);
        if (foldedHaystack.includes(foldedNeedle) && !normalizedHaystack.includes(normalizedNeedle)) {
            const phraseTokenCount = needleTokens.length;
            for (let i = 0; i <= tokens.length - phraseTokenCount; i += 1) {
                const sliceFolded = haystackFolded.slice(i, i + phraseTokenCount).join(' ');
                if (sliceFolded === foldedNeedle) {
                    const from = tokens[i].start;
                    const to = tokens[i + phraseTokenCount - 1].end;
                    pushOccurrence(from, to, 300 - (to - from), 'accent');
                }
            }
        }
    }

    // Prefer higher score, then earlier position; drop weak overlapping duplicates.
    occurrences.sort((a, b) => b.score - a.score || a.from - b.from);

    /** @type {DomainLinkOccurrence[]} */
    const pruned = [];
    for (const row of occurrences) {
        const overlaps = pruned.some(
            (kept) => !(row.to <= kept.from || row.from >= kept.to),
        );
        if (overlaps) {
            continue;
        }
        pruned.push(row);
    }

    return pruned.sort((a, b) => a.from - b.from);
}

/**
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {string} anchor
 * @param {number} [maxCount=50]
 * @returns {Array<DomainLinkOccurrence & { blockId: string, matchIndex: number, phrase: string }>}
 */
export function findDomainLinkOccurrencesInBlocks(blocks, anchor, maxCount = 50) {
    const limit = Number.isFinite(maxCount) && maxCount > 0 ? Math.floor(maxCount) : 50;
    /** @type {Array<DomainLinkOccurrence & { blockId: string, matchIndex: number, phrase: string }>} */
    const out = [];
    const phrase = String(anchor ?? '').trim();

    for (const block of Array.isArray(blocks) ? blocks : []) {
        if (block?.type === 'image') {
            continue;
        }
        const blockId = String(block?.id ?? '').trim();
        if (blockId === '') {
            continue;
        }
        const plain = plainTextFromHtmlForDomainLink(block?.content ?? '');
        if (plain === '') {
            continue;
        }

        const local = findDomainLinkOccurrencesInPlainText(plain, phrase);
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
 * @param {DomainLinkOccurrence[]} occurrences
 * @returns {number}
 */
export function bestDomainLinkScore(occurrences) {
    if (!Array.isArray(occurrences) || occurrences.length === 0) {
        return 0;
    }
    return Math.max(...occurrences.map((row) => Number(row.score) || 0));
}
