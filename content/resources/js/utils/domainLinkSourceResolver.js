/**
 * Domain Link inventory: custom before product_cat, dedupe by URL/anchor.
 */

import { normalizeHrefForCompare, normalizeLinkLabel } from './articleLinkSuggestionFilter.js';

export const DOMAIN_LINK_SOURCE_CUSTOM = 'custom';
export const DOMAIN_LINK_SOURCE_PRODUCT_CAT = 'product_cat';
export const DOMAIN_LINK_SOURCE_MAIN_DOMAIN = 'main_domain';

/**
 * @param {unknown} source
 * @returns {string}
 */
export function normalizeDomainLinkSource(source) {
    const value = String(source ?? '').trim().toLowerCase();
    if (value === DOMAIN_LINK_SOURCE_CUSTOM || value === 'manual') {
        return DOMAIN_LINK_SOURCE_CUSTOM;
    }
    if (value === DOMAIN_LINK_SOURCE_PRODUCT_CAT || value === 'product_category') {
        return DOMAIN_LINK_SOURCE_PRODUCT_CAT;
    }
    if (value === DOMAIN_LINK_SOURCE_MAIN_DOMAIN) {
        return DOMAIN_LINK_SOURCE_MAIN_DOMAIN;
    }
    return value || DOMAIN_LINK_SOURCE_CUSTOM;
}

/**
 * @param {string} source
 * @returns {number}
 */
export function domainLinkSourceRank(source) {
    const normalized = normalizeDomainLinkSource(source);
    if (normalized === DOMAIN_LINK_SOURCE_CUSTOM) {
        return 1;
    }
    if (normalized === DOMAIN_LINK_SOURCE_PRODUCT_CAT) {
        return 2;
    }
    if (normalized === DOMAIN_LINK_SOURCE_MAIN_DOMAIN) {
        return 3;
    }
    return 9;
}

/**
 * @param {Array<{ text?: string, href?: string, target_url?: string, source?: string, priority?: number }>} links
 * @returns {Array<Record<string, unknown>>}
 */
export function resolveDomainLinkInventory(links) {
    const rows = Array.isArray(links) ? links : [];
    const sorted = [...rows].sort((left, right) => {
        const rankDelta =
            domainLinkSourceRank(left?.source)
            - domainLinkSourceRank(right?.source);
        if (rankDelta !== 0) {
            return rankDelta;
        }
        const priorityDelta = (Number(left?.priority) || 99) - (Number(right?.priority) || 99);
        if (priorityDelta !== 0) {
            return priorityDelta;
        }
        return String(left?.text ?? '').localeCompare(String(right?.text ?? ''), 'vi');
    });

    const seenHref = new Set();
    const seenAnchor = new Set();
    /** @type {Array<Record<string, unknown>>} */
    const out = [];

    for (const item of sorted) {
        const text = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();
        if (text === '' || href === '') {
            continue;
        }

        const source = normalizeDomainLinkSource(item?.source);
        const anchorKey = normalizeLinkLabel(text);
        const hrefKey = normalizeHrefForCompare(href);

        if (hrefKey !== '' && seenHref.has(hrefKey)) {
            continue;
        }
        if (anchorKey !== '' && seenAnchor.has(anchorKey)) {
            continue;
        }

        if (hrefKey !== '') {
            seenHref.add(hrefKey);
        }
        if (anchorKey !== '') {
            seenAnchor.add(anchorKey);
        }

        out.push({
            ...item,
            text,
            href,
            target_url: String(item?.target_url ?? href).trim() || href,
            source,
            priority: Number(item?.priority) || domainLinkSourceRank(source),
        });
    }

    return out;
}
