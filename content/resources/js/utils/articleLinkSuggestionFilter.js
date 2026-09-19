export function normalizeLinkLabel(text) {
    return String(text ?? '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();
}

/** Bỏ apostrophe / dấu câu khi so khớp từ khóa (KID'S ≈ kids). */
export function normalizePhraseForMatch(text) {
    return String(text ?? '')
        .toLowerCase()
        .replace(/[\u0027\u2018\u2019\u201B\u2032\u0060\u00B4\u02BC\uFF07]/g, '')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Non-destination presentation hrefs (# / #fragment / empty / javascript…).
 * Not a real URL identity for dedupe or site partition.
 */
export function isUnresolvedSuggestionHref(href) {
    const value = String(href ?? '').trim();
    if (value === '') {
        return true;
    }

    if (value === '#' || value.startsWith('#')) {
        return true;
    }

    const lower = value.toLowerCase();

    return (
        lower.startsWith('javascript:')
        || lower.startsWith('data:')
        || lower.startsWith('vbscript:')
    );
}

/**
 * Unresolved internal anchor idea: destination_resolved=false wins over href.
 */
export function isUnresolvedSuggestionItem(item) {
    if (!item || typeof item !== 'object') {
        return false;
    }

    if (item.destination_resolved === false) {
        return true;
    }

    const href = String(item.href ?? '').trim();

    return isUnresolvedSuggestionHref(href);
}

export function normalizeHrefForCompare(href) {
    const value = String(href ?? '').trim();
    if (!value || isUnresolvedSuggestionHref(value)) {
        return '';
    }

    try {
        const url = new URL(value, window.location.origin);
        const pathname = url.pathname.replace(/\/+$/, '') || '/';

        return `${pathname.toLowerCase()}${url.search.toLowerCase()}`;
    } catch {
        return value.replace(/\/+$/, '').toLowerCase();
    }
}

const SPECIAL_LINK_SCHEMES = new Set([
    'tel',
    'mailto',
    'sms',
    'fax',
    'callto',
    'geo',
    'skype',
    'whatsapp',
    'viber',
    'data',
    'cid',
]);

/** tel/mailto/… + số ĐT/email trần — không tính internal/external. */
export function isSpecialOrContactHref(href) {
    const value = String(href ?? '').trim();
    if (value === '') {
        return false;
    }

    const lower = value.toLowerCase();
    if (lower.startsWith('javascript:')) {
        return true;
    }

    const schemeMatch = lower.match(/^([a-z][a-z0-9+.-]*):/i);
    if (schemeMatch && SPECIAL_LINK_SCHEMES.has(schemeMatch[1].toLowerCase())) {
        return true;
    }

    if (/^[+]?[\d\s().-]{6,}$/u.test(value)) {
        return true;
    }

    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(value);
}

export function normalizeDomainHost(host) {
    return String(host ?? '')
        .trim()
        .toLowerCase()
        .replace(/^www\./, '');
}

/** Relative path hoặc host trùng domain site → internal. */
export function isInternalHrefForSite(href, siteDomain) {
    const value = String(href ?? '').trim();
    if (value === '' || isUnresolvedSuggestionHref(value)) {
        return false;
    }

    if (value.startsWith('/')) {
        return true;
    }

    if (isSpecialOrContactHref(value)) {
        return false;
    }

    let resolved = value;
    if (resolved.startsWith('//')) {
        resolved = `https:${resolved}`;
    }

    try {
        const url = new URL(resolved, 'https://placeholder.local');
        const host = normalizeDomainHost(url.hostname);
        const domain = normalizeDomainHost(siteDomain);

        return host !== '' && domain !== '' && host === domain;
    } catch {
        return false;
    }
}

/**
 * Chia catalog gợi ý: internal (cùng site) / external (http ngoài) / bỏ tel-mail.
 * Unresolved (# / destination_resolved=false) = internal anchor idea, not external.
 *
 * @param {Array<{ text?: string, href?: string, target_url?: string, destination_resolved?: boolean }>} catalog
 * @param {string} siteDomain
 * @returns {{ internal: Array, external: Array }}
 */
export function partitionSuggestionCatalogBySite(catalog, siteDomain) {
    const internal = [];
    const external = [];

    (Array.isArray(catalog) ? catalog : []).forEach((item) => {
        if (isUnresolvedSuggestionItem(item)) {
            const href = String(item?.href ?? '').trim();
            if (href !== '' && isSpecialOrContactHref(href)) {
                return;
            }
            internal.push(item);
            return;
        }

        const href = String(item?.href ?? item?.target_url ?? '').trim();
        if (href === '') {
            internal.push(item);
            return;
        }

        if (isSpecialOrContactHref(href)) {
            return;
        }

        if (isInternalHrefForSite(href, siteDomain)) {
            internal.push(item);
            return;
        }

        external.push(item);
    });

    return { internal, external };
}

function labelsOverlap(left, right) {
    const a = normalizeLinkLabel(left);
    const b = normalizeLinkLabel(right);
    if (!a || !b) {
        return false;
    }

    if (a === b) {
        return true;
    }

    return a.includes(b) || b.includes(a);
}

function isPhraseAlreadyLinked(phrase, linkedLabels) {
    const normalized = normalizeLinkLabel(phrase);
    if (!normalized) {
        return true;
    }

    return linkedLabels.some((label) => labelsOverlap(label, normalized));
}

function isHrefAlreadyLinked(href, linkedHrefs) {
    const normalized = normalizeHrefForCompare(href);
    if (!normalized) {
        return false;
    }

    return linkedHrefs.includes(normalized);
}

/**
 * @param {Array<{ text?: string, href?: string, target_url?: string }>} suggested
 * @param {Array<{ text?: string, href?: string }>} internal
 */
export function textContainsPhrase(plainText, phrase) {
    const text = normalizePhraseForMatch(plainText);
    const needle = normalizePhraseForMatch(phrase);
    if (!text || !needle) {
        return false;
    }

    return text.includes(needle);
}

/**
 * Domain link list — chỉ gợi ý khi anchor text có trong nội dung bài (plain text).
 *
 * @param {Array<{ text?: string }>} links
 * @param {string} articlePlainText
 */
export function filterDomainLinksInArticleContent(links, articlePlainText) {
    const plain = String(articlePlainText ?? '');
    if (!plain.trim()) {
        return [];
    }

    return (Array.isArray(links) ? links : []).filter((item) => {
        const phrase = String(item?.text ?? '').trim();

        return phrase !== '' && textContainsPhrase(plain, phrase);
    });
}

export function filterSuggestedInternalLinks(suggested, internal, external = []) {
    const existingItems = [
        ...(Array.isArray(internal) ? internal : []),
        ...(Array.isArray(external) ? external : []),
    ];
    const linkedLabels = [];
    const linkedHrefs = [];

    existingItems.forEach((item) => {
        const label = normalizeLinkLabel(item?.text);
        if (label) {
            linkedLabels.push(label);
        }

        const href = normalizeHrefForCompare(item?.href);
        if (href) {
            linkedHrefs.push(href);
        }
    });

    const uniqueLabels = [...new Set(linkedLabels)];
    const uniqueHrefs = [...new Set(linkedHrefs)];

    const filtered = [];
    const seenLabels = [...uniqueLabels];
    const seenHrefs = [...uniqueHrefs];

    (Array.isArray(suggested) ? suggested : []).forEach((item) => {
        const phrase = String(item?.text ?? '').trim();
        const href = String(item?.href ?? item?.target_url ?? '').trim();

        if (isPhraseAlreadyLinked(phrase, seenLabels)) {
            return;
        }

        if (isHrefAlreadyLinked(href, seenHrefs)) {
            return;
        }

        filtered.push(item);

        const label = normalizeLinkLabel(phrase);
        if (label) {
            seenLabels.push(label);
        }

        const hrefKey = normalizeHrefForCompare(href);
        if (hrefKey) {
            seenHrefs.push(hrefKey);
        }
    });

    return filtered;
}

export const MAX_INTERNAL_LINK_SLOTS = 10;
/** Số gợi ý hiển thị khi bài còn < 10 link nội bộ (không trừ theo số link đã có). */
export const MAX_VISIBLE_INTERNAL_SUGGESTIONS = 10;

export function isSuggestionExcluded(phrase, excludedLabels) {
    const normalized = normalizeLinkLabel(phrase);
    if (!normalized) {
        return false;
    }

    return (Array.isArray(excludedLabels) ? excludedLabels : []).some((excluded) =>
        labelsOverlap(excluded, normalized),
    );
}

/**
 * Deterministic relevance ranking shared by catalog dedupe and suggestion-row ordering.
 * Resolved destinations first, then lower numeric source_priority, then higher score.
 * Missing source_priority/score rank after explicitly ranked/scored rows — never invented.
 *
 * @param {{ destination_resolved?: boolean, source_priority?: number, score?: number }} a
 * @param {{ destination_resolved?: boolean, source_priority?: number, score?: number }} b
 * @returns {number} negative when `a` should rank before `b`
 */
export function compareSuggestionCandidateRank(a, b) {
    const resolvedA = a?.destination_resolved === true ? 0 : 1;
    const resolvedB = b?.destination_resolved === true ? 0 : 1;
    if (resolvedA !== resolvedB) {
        return resolvedA - resolvedB;
    }

    const priorityA = Number.isFinite(Number(a?.source_priority)) ? Number(a.source_priority) : Number.POSITIVE_INFINITY;
    const priorityB = Number.isFinite(Number(b?.source_priority)) ? Number(b.source_priority) : Number.POSITIVE_INFINITY;
    if (priorityA !== priorityB) {
        return priorityA - priorityB;
    }

    const scoreA = Number.isFinite(Number(a?.score)) ? Number(a.score) : Number.NEGATIVE_INFINITY;
    const scoreB = Number.isFinite(Number(b?.score)) ? Number(b.score) : Number.NEGATIVE_INFINITY;
    if (scoreA !== scoreB) {
        return scoreB - scoreA;
    }

    return 0;
}

/**
 * @param {Array<{ text?: string, href?: string, target_url?: string|null, keyword_id?: number, can_insert?: boolean, destination_resolved?: boolean }>} sources
 */
export function mergeSuggestionCatalog(...sources) {
    const byLabel = new Map();
    let insertionIndex = 0;

    sources.flat().forEach((item) => {
        const text = String(item?.text ?? '').trim();
        const label = normalizeLinkLabel(text);
        if (!label) {
            return;
        }

        const unresolved = isUnresolvedSuggestionItem(item);
        const href = unresolved
            ? (String(item?.href ?? '').trim() || '#')
            : String(item?.href ?? item?.target_url ?? '').trim();

        let targetUrl = null;
        if (!unresolved) {
            if (item != null && Object.prototype.hasOwnProperty.call(item, 'target_url')) {
                const rawTarget = item.target_url;
                targetUrl = rawTarget == null ? null : (String(rawTarget).trim() || null);
            } else {
                targetUrl = href || null;
            }
        }

        const destinationResolved = unresolved
            ? false
            : (item?.destination_resolved === true
                ? true
                : (href !== '' && !isUnresolvedSuggestionHref(href)));

        // Spread the original item first so backend relevance metadata (score, match_reason,
        // source_priority, candidate_source, provenance, target_article_id, …) survives merge.
        const candidate = {
            ...(item && typeof item === 'object' ? item : {}),
            text,
            href: href || null,
            target_url: targetUrl,
            keyword_id: item?.keyword_id ?? null,
            can_insert: item?.can_insert !== false && href !== '',
            is_suggestion: true,
            destination_resolved: destinationResolved,
            source: item?.source ?? item?.suggestion_source ?? null,
            suggestion_source: item?.suggestion_source ?? item?.source ?? null,
        };

        const existing = byLabel.get(label);
        if (!existing) {
            byLabel.set(label, { candidate, insertionIndex: insertionIndex++ });
            return;
        }

        insertionIndex += 1;
        // Same anchor label from multiple stages/sources — keep the more relevant candidate,
        // not whichever happened to arrive first.
        if (compareSuggestionCandidateRank(candidate, existing.candidate) < 0) {
            byLabel.set(label, { candidate, insertionIndex: existing.insertionIndex });
        }
    });

    return [...byLabel.values()]
        .sort((left, right) => left.insertionIndex - right.insertionIndex)
        .map((entry) => entry.candidate);
}


/**
 * @param {{
 *   catalog?: Array<{ text?: string, href?: string, target_url?: string, keyword_id?: number, can_insert?: boolean }>,
 *   internal?: Array<{ text?: string, href?: string }>,
 *   external?: Array<{ text?: string, href?: string }>,
 *   excludedLabels?: string[],
 *   articlePlainText?: string,
 *   maxSlots?: number,
 *   skipContentFilter?: boolean,
 * }} options
 */
export function buildVisibleInternalSuggestions({
    catalog = [],
    internal = [],
    external = [],
    excludedLabels = [],
    articlePlainText = '',
    maxSlots = MAX_INTERNAL_LINK_SLOTS,
    skipContentFilter = false,
} = {}) {
    const internalCount = Array.isArray(internal) ? internal.length : 0;
    if (internalCount >= maxSlots) {
        return [];
    }

    let pool = Array.isArray(catalog) ? catalog : [];
    const plain = String(articlePlainText ?? '').trim();
    if (!skipContentFilter && plain !== '') {
        pool = filterDomainLinksInArticleContent(pool, plain);
    }

    const withoutExcluded = pool.filter((item) => {
        const phrase = String(item?.text ?? '').trim();

        return phrase !== '' && !isSuggestionExcluded(phrase, excludedLabels);
    });

    return filterSuggestedInternalLinks(withoutExcluded, internal, external).slice(
        0,
        MAX_VISIBLE_INTERNAL_SUGGESTIONS,
    );
}

/**
 * Main-domain catalog candidates must not duplicate current article links.
 *
 * @param {unknown[]} items
 * @param {unknown[]} internal
 * @param {unknown[]} external
 * @returns {unknown[]}
 */
export function filterMainDomainSuggestionItems(items, internal = [], external = []) {
    const linked = new Set(
        [...(Array.isArray(internal) ? internal : []), ...(Array.isArray(external) ? external : [])]
            .map((item) => normalizeHrefForCompare(item?.href ?? item?.target_url))
            .filter(Boolean),
    );
    const seen = new Set();

    return (Array.isArray(items) ? items : []).filter((item) => {
        const href = normalizeHrefForCompare(item?.href ?? item?.target_url);
        if (!href || linked.has(href) || seen.has(href)) {
            return false;
        }
        seen.add(href);

        return true;
    });
}
