/**
 * Resolve locate phrase + insert match identity for Internal Link suggestions.
 * Pure helpers — no i18n / editor command side effects.
 *
 * Occurrence semantics SSOT: editorAnchorOccurrenceMatcher
 * (exact contiguous tokens, exclude existing <a>, local matchIndex per block).
 */

import { findExactAnchorOccurrencesInBlocks } from './editorAnchorOccurrenceMatcher.js';

/**
 * Phrase used to locate/wrap inside article body (not necessarily sidebar label).
 *
 * @param {Record<string, unknown>|null|undefined} item
 * @returns {string}
 */
export function resolveSuggestionLocatePhrase(item) {
    const provenance = item?.provenance && typeof item.provenance === 'object'
        ? item.provenance
        : null;
    const candidates = [
        item?.matched_phrase,
        provenance?.matched_phrase,
        item?.matchedPhrase,
        item?.text,
        item?.anchor,
        item?.anchor_text,
    ];
    for (const raw of candidates) {
        const phrase = String(raw ?? '').trim();
        if (phrase !== '') {
            return phrase;
        }
    }
    return '';
}

/**
 * Actionable (unlinked) exact occurrences — same semantics as Domain Link suggestions.
 *
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {string} phrase
 * @param {number} [maxCount=64]
 * @returns {Array<{ blockId: string, blockIndex: number, matchIndex: number, phrase: string, matchedText: string, from: number, to: number }>}
 */
export function findSuggestionPhraseOccurrences(blocks, phrase, maxCount = 64) {
    const phraseText = String(phrase ?? '').trim();
    if (phraseText === '') {
        return [];
    }

    return findExactAnchorOccurrencesInBlocks(blocks, phraseText, maxCount).map((row) => ({
        blockId: row.blockId,
        blockIndex: row.blockIndex,
        matchIndex: row.matchIndex,
        phrase: String(row.matchedText ?? row.phrase ?? phraseText).trim(),
        matchedText: String(row.matchedText ?? row.phrase ?? phraseText).trim(),
        from: row.from,
        to: row.to,
    }));
}

/**
 * Live DOM blocks when available; tests pass blocksOverride.
 *
 * @returns {Array<{ id: string, content: string }>}
 */
function collectLiveBlocks() {
    if (typeof document === 'undefined' || typeof document.querySelectorAll !== 'function') {
        return [];
    }
    const slots = document.querySelectorAll('[data-seo-block-id]');
    const blocks = [];
    slots.forEach((slot) => {
        const id = String(slot.getAttribute('data-seo-block-id') ?? '').trim();
        if (id === '') {
            return;
        }
        blocks.push({
            id,
            content: slot.innerHTML ?? '',
        });
    });
    return blocks;
}

/**
 * @param {Record<string, unknown>|null|undefined} item
 * @param {{ blockId?: string, matchIndex?: number, phrase?: string, matchedText?: string }|null} [stored]
 * @param {Array<{ id?: string, content?: string, type?: string }>|null} [blocksOverride]
 * @returns {{ blockId: string, matchIndex: number, phrase: string }|null}
 */
export function resolveSuggestionInsertMatch(item, stored = null, blocksOverride = null) {
    const locatePhrase = resolveSuggestionLocatePhrase(item);
    if (locatePhrase === '') {
        return null;
    }

    const blocks = Array.isArray(blocksOverride) ? blocksOverride : collectLiveBlocks();
    const all = findSuggestionPhraseOccurrences(blocks, locatePhrase, 64);

    if (stored) {
        const storedPhrase = String(stored.matchedText ?? stored.phrase ?? locatePhrase).trim();
        const storedBlockId = String(stored.blockId ?? '').trim();
        const storedIndex = Math.max(0, Number(stored.matchIndex) || 0);

        if (storedBlockId !== '' && storedPhrase !== '') {
            const inBlock = storedPhrase === locatePhrase
                ? all.filter((row) => row.blockId === storedBlockId)
                : findSuggestionPhraseOccurrences(
                    blocks.filter((row) => String(row?.id ?? '') === storedBlockId),
                    storedPhrase,
                    storedIndex + 8,
                );

            const hit = inBlock.find((row) => row.matchIndex === storedIndex);
            if (hit) {
                return {
                    blockId: hit.blockId,
                    matchIndex: hit.matchIndex,
                    phrase: String(hit.phrase ?? storedPhrase).trim(),
                };
            }

            // Stored index stale but exactly one actionable left in that block → use it.
            if (inBlock.length === 1) {
                return {
                    blockId: inBlock[0].blockId,
                    matchIndex: inBlock[0].matchIndex,
                    phrase: String(inBlock[0].phrase ?? storedPhrase).trim(),
                };
            }

            // Ambiguous stale index inside a block that still has multiple hits → do not guess.
            if (inBlock.length > 1) {
                return null;
            }

            // Block has zero actionable hits (e.g. stored became linked) → relocate document-wide.
        }
    }

    if (all.length === 0) {
        const label = String(item?.text ?? '').trim();
        if (label !== '' && label !== locatePhrase) {
            const byLabel = findSuggestionPhraseOccurrences(blocks, label, 64);
            if (byLabel.length > 0) {
                return {
                    blockId: byLabel[0].blockId,
                    matchIndex: byLabel[0].matchIndex,
                    phrase: String(byLabel[0].phrase ?? label).trim(),
                };
            }
        }
        return null;
    }

    return {
        blockId: all[0].blockId,
        matchIndex: all[0].matchIndex,
        phrase: String(all[0].phrase ?? locatePhrase).trim(),
    };
}
