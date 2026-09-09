/**
 * Resolve locate phrase + insert match identity for Internal Link suggestions.
 * Pure helpers — no i18n / editor command side effects.
 */

import { normalizePhraseForMatch } from './articleLinkSuggestionFilter.js';

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
 * @param {string} html
 * @returns {string}
 */
function plainTextFromHtml(html) {
    const source = String(html ?? '');
    if (source.trim() === '') {
        return '';
    }
    try {
        const doc = new DOMParser().parseFromString(source, 'text/html');
        return doc.body?.textContent ?? '';
    } catch {
        return source.replace(/<[^>]+>/g, ' ');
    }
}

/**
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {string} phrase
 * @param {number} [maxCount=64]
 * @returns {Array<{ blockId: string, matchIndex: number, phrase: string }>}
 */
export function findSuggestionPhraseOccurrences(blocks, phrase, maxCount = 64) {
    const needle = normalizePhraseForMatch(phrase);
    const phraseText = String(phrase ?? '').trim();
    if (needle === '' || phraseText === '') {
        return [];
    }

    const limit = Number.isFinite(maxCount) && maxCount > 0 ? Math.floor(maxCount) : 64;
    const out = [];

    for (const block of Array.isArray(blocks) ? blocks : []) {
        if (block?.type === 'image') {
            continue;
        }
        const blockId = String(block?.id ?? '').trim();
        if (blockId === '') {
            continue;
        }
        const plain = plainTextFromHtml(String(block?.content ?? ''));
        const haystack = normalizePhraseForMatch(plain);
        if (haystack === '') {
            continue;
        }

        let searchFrom = 0;
        let matchIndex = 0;
        while (searchFrom <= haystack.length) {
            const idx = haystack.indexOf(needle, searchFrom);
            if (idx === -1) {
                break;
            }
            out.push({
                blockId,
                matchIndex,
                phrase: phraseText,
            });
            if (out.length >= limit) {
                return out;
            }
            matchIndex += 1;
            searchFrom = idx + Math.max(1, needle.length);
        }
    }

    return out;
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

    if (stored) {
        const storedPhrase = String(stored.matchedText ?? stored.phrase ?? locatePhrase).trim();
        const storedBlockId = String(stored.blockId ?? '').trim();
        const storedIndex = Math.max(0, Number(stored.matchIndex) || 0);
        if (storedBlockId !== '' && storedPhrase !== '') {
            const block = blocks.find((row) => String(row?.id ?? '') === storedBlockId);
            if (block) {
                const inBlock = findSuggestionPhraseOccurrences([block], storedPhrase, storedIndex + 1);
                const hit = inBlock.find((row) => row.matchIndex === storedIndex)
                    ?? (inBlock.length === 1 ? inBlock[0] : null);
                if (hit) {
                    return {
                        blockId: hit.blockId,
                        matchIndex: hit.matchIndex,
                        phrase: String(hit.phrase ?? storedPhrase).trim(),
                    };
                }
                const refreshed = findSuggestionPhraseOccurrences([block], storedPhrase, 8);
                if (refreshed.length === 1) {
                    return {
                        blockId: refreshed[0].blockId,
                        matchIndex: refreshed[0].matchIndex,
                        phrase: String(refreshed[0].phrase ?? storedPhrase).trim(),
                    };
                }
                if (refreshed.length === 0) {
                    return null;
                }
                return null;
            }
        }
    }

    const occurrences = findSuggestionPhraseOccurrences(blocks, locatePhrase, 64);
    if (occurrences.length === 0) {
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
        blockId: occurrences[0].blockId,
        matchIndex: occurrences[0].matchIndex,
        phrase: String(occurrences[0].phrase ?? locatePhrase).trim(),
    };
}
