import { normalizePhraseForMatch } from './articleLinkSuggestionFilter';
import { scrollToPlainTextInBlock } from './articleLinkScroll';
import {
    enclosingAnchorForPlainTextRange,
    findPlainTextRangeInRoot,
} from './articlePlainTextRange';

/**
 * Collect live editor blocks from mounted block slots.
 * @returns {Array<{ id: string, content: string }>}
 */
export function collectEditorBlocksFromDom() {
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
 * @param {string} plain
 * @param {string} normalizedNeedle
 * @returns {number[]}
 */
function findNormalizedIndices(plain, normalizedNeedle) {
    const normalizedHaystack = normalizePhraseForMatch(plain);
    if (normalizedHaystack === '' || normalizedNeedle === '') {
        return [];
    }

    const indices = [];
    let searchFrom = 0;

    while (searchFrom <= normalizedHaystack.length) {
        const idx = normalizedHaystack.indexOf(normalizedNeedle, searchFrom);
        if (idx === -1) {
            break;
        }
        indices.push(idx);
        searchFrom = idx + Math.max(1, normalizedNeedle.length);
    }

    return indices;
}

/**
 * @param {string} plain
 * @param {number} normalizedIndex
 * @param {number} normalizedLength
 * @returns {string}
 */
function previewAroundNormalizedIndex(plain, normalizedIndex, normalizedLength) {
    const text = String(plain ?? '');
    if (text === '') {
        return '';
    }

    const start = Math.max(0, normalizedIndex - 24);
    const end = Math.min(text.length, normalizedIndex + normalizedLength + 24);
    const slice = text.slice(start, end).replace(/\s+/g, ' ').trim();

    return start > 0 ? `…${slice}` : slice;
}

/**
 * @param {string} html
 * @param {string} phrase
 * @param {number} matchIndex
 * @returns {string}
 */
function resolveOccurrenceHref(html, phrase, matchIndex) {
    try {
        const doc = new DOMParser().parseFromString(String(html ?? ''), 'text/html');
        const match = findPlainTextRangeInRoot(doc.body, phrase, matchIndex, { includeLinkedText: true });
        const anchor = enclosingAnchorForPlainTextRange(match);
        return String(anchor?.getAttribute?.('href') ?? '').trim();
    } catch {
        return '';
    }
}

/**
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {string} phrase
 * @param {number} [maxCount=2]
 * @returns {Array<{ blockId: string, matchIndex: number, preview: string, phrase: string, href: string }>}
 */
export function findPhraseOccurrencesInBlocks(blocks, phrase, maxCount = 2) {
    const needle = normalizePhraseForMatch(phrase);
    if (needle === '') {
        return [];
    }

    const limit = Number.isFinite(maxCount) && maxCount > 0 ? Math.floor(maxCount) : 2;
    const occurrences = [];
    const phraseText = String(phrase ?? '').trim();

    for (const block of Array.isArray(blocks) ? blocks : []) {
        if (block?.type === 'image') {
            continue;
        }

        const blockId = String(block?.id ?? '').trim();
        if (blockId === '') {
            continue;
        }

        const html = String(block?.content ?? '');
        const plain = plainTextFromHtml(html);
        if (plain.trim() === '') {
            continue;
        }

        const indices = findNormalizedIndices(plain, needle);
        for (let matchIndex = 0; matchIndex < indices.length; matchIndex += 1) {
            occurrences.push({
                blockId,
                matchIndex,
                preview: previewAroundNormalizedIndex(plain, indices[matchIndex], needle.length),
                phrase: phraseText,
                href: resolveOccurrenceHref(html, phraseText, matchIndex),
            });

            if (occurrences.length >= limit) {
                return occurrences;
            }
        }
    }

    return occurrences;
}

/**
 * @param {{ blockId?: string, matchIndex?: number, phrase?: string, preview?: string }|null|undefined} occurrence
 */
export function scrollToPhraseOccurrence(occurrence) {
    const blockId = String(occurrence?.blockId ?? '').trim();
    const phrase = String(occurrence?.phrase ?? occurrence?.preview ?? '').trim();
    if (blockId === '' || phrase === '') {
        return;
    }

    const matchIndex = Number(occurrence?.matchIndex ?? 0);
    scrollToPlainTextInBlock(blockId, phrase, Number.isFinite(matchIndex) ? matchIndex : 0);
}
