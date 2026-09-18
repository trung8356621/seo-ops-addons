/**
 * Domain Link occurrence index builders (no DOM scroll imports).
 */

import {
    isSpecialOrContactHref,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from './articleLinkSuggestionFilter.js';
import { buildActionableDomainLinkSuggestions } from './editorAnchorOccurrenceMatcher.js';

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
 * Exclude suggestions whose label/destination already exists in the article.
 * Does NOT collapse same-destination rows with different actionable phrases.
 *
 * @param {Array<Record<string, unknown>>} suggestions
 * @param {Array<{ text?: string, href?: string }>} internalLinks
 * @param {Array<{ text?: string, href?: string }>} externalLinks
 * @returns {Array<Record<string, unknown>>}
 */
function filterAgainstExistingArticleLinks(suggestions, internalLinks, externalLinks) {
    const existing = [
        ...(Array.isArray(internalLinks) ? internalLinks : []),
        ...(Array.isArray(externalLinks) ? externalLinks : []),
    ];
    const linkedLabels = new Set();
    const linkedHrefs = new Set();
    for (const item of existing) {
        const label = normalizeLinkLabel(item?.text);
        if (label) {
            linkedLabels.add(label);
        }
        const href = normalizeHrefForCompare(item?.href ?? item?.target_url);
        if (href) {
            linkedHrefs.add(href);
        }
    }

    return (Array.isArray(suggestions) ? suggestions : []).filter((item) => {
        const phrase = normalizeLinkLabel(item?.text);
        if (phrase && linkedLabels.has(phrase)) {
            return false;
        }
        const href = normalizeHrefForCompare(item?.href ?? item?.target_url);
        if (href && linkedHrefs.has(href)) {
            return false;
        }
        return true;
    });
}

/**
 * Inventory for UI: only exact/normalized actionable occurrences in current blocks.
 * Soft/proximity matching is intentionally not used here.
 *
 * @param {Array<Record<string, unknown>>} allLinks
 * @param {Array<{ id?: string, content?: string, type?: string }>} blocks
 * @param {Array<{ text?: string, href?: string }>} internalLinks
 * @param {Array<{ text?: string, href?: string }>} externalLinks
 * @returns {Array<Record<string, unknown> & { occurrence_count: number, can_insert: boolean }>}
 */
export function buildDomainLinkListForEditor(allLinks, blocks, internalLinks = [], externalLinks = []) {
    const catalog = (Array.isArray(allLinks) ? allLinks : []).filter(
        (item) => !isSpecialOrContactHref(item?.href ?? item?.target_url),
    );

    const actionable = buildActionableDomainLinkSuggestions(catalog, blocks);

    return filterAgainstExistingArticleLinks(actionable, internalLinks, externalLinks).map((item) => ({
        ...item,
        occurrence_count: Number(item.occurrence_count) || (
            Array.isArray(item._domain_occurrences) ? item._domain_occurrences.length : 0
        ),
        can_insert: item.can_insert !== false && String(item.href ?? item.target_url ?? '').trim() !== '',
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
