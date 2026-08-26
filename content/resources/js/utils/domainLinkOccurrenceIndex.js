/**
 * Domain Link occurrence index builders (no DOM scroll imports).
 */

import { findDomainLinkOccurrencesInBlocks } from './domainLinkMatcher.js';
import { resolveDomainLinkInventory } from './domainLinkSourceResolver.js';
import {
    filterSuggestedInternalLinks,
    isSpecialOrContactHref,
} from './articleLinkSuggestionFilter.js';

/**
 * @typedef {{ blockId: string, from?: number, to?: number, matchedText: string, score: number, matchIndex?: number, phrase?: string }} DomainLinkOccurrence
 * @typedef {{ id: string, anchor: string, url: string, source: string, occurrences: DomainLinkOccurrence[], item: Record<string, unknown> }} DomainLinkCandidate
 */

/**
 * @param {{ text?: string, href?: string, target_url?: string, source?: string }} item
 * @param {number} index
 * @returns {string}
 */
export function domainLinkCandidateId(item, index = 0) {
    const href = String(item?.href ?? item?.target_url ?? '').trim();
    const text = String(item?.text ?? '').trim();
    return `domain:${href || text || index}`;
}

/**
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {Array<Record<string, unknown>>} inventory
 * @returns {DomainLinkCandidate[]}
 */
export function buildDomainLinkOccurrenceIndex(blocks, inventory) {
    const resolved = resolveDomainLinkInventory(inventory);
    return resolved.map((item, index) => {
        const anchor = String(item.text ?? '').trim();
        const url = String(item.href ?? item.target_url ?? '').trim();
        const occurrences = findDomainLinkOccurrencesInBlocks(blocks, anchor);
        return {
            id: domainLinkCandidateId(item, index),
            anchor,
            url,
            source: String(item.source ?? 'custom'),
            occurrences,
            item,
        };
    });
}

/**
 * Inventory for UI: only rows with ≥1 soft/exact occurrence in the article.
 *
 * @param {Array<Record<string, unknown>>} allLinks
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {Array<{ text?: string, href?: string }>} internalLinks
 * @param {Array<{ text?: string, href?: string }>} externalLinks
 * @returns {Array<Record<string, unknown> & { occurrence_count: number, can_insert: boolean }>}
 */
export function buildDomainLinkListForEditor(allLinks, blocks, internalLinks = [], externalLinks = []) {
    const inventory = resolveDomainLinkInventory(allLinks).filter(
        (item) => !isSpecialOrContactHref(item?.href ?? item?.target_url),
    );
    const unlinked = filterSuggestedInternalLinks(inventory, internalLinks, externalLinks);
    const indexed = buildDomainLinkOccurrenceIndex(blocks, unlinked);

    return indexed
        .filter((candidate) => candidate.occurrences.length > 0)
        .map((candidate) => ({
            ...candidate.item,
            text: candidate.anchor,
            href: candidate.url,
            target_url: candidate.url,
            source: candidate.source,
            occurrence_count: candidate.occurrences.length,
            can_insert: candidate.item.can_insert !== false && candidate.url !== '',
            _domain_occurrences: candidate.occurrences,
            _domain_candidate_id: candidate.id,
        }));
}

/**
 * @param {number} currentCycle
 * @param {number} length
 * @returns {number}
 */
export function nextDomainLinkOccurrenceIndex(currentCycle, length) {
    const total = Number(length) || 0;
    if (total <= 0) {
        return 0;
    }
    const cycle = Math.max(0, Number(currentCycle) || 0);
    return cycle % total;
}
