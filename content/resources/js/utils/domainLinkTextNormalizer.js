/**
 * Domain-Link-only text normalization — do not reuse for Internal Links.
 */

/**
 * @param {string} text
 * @returns {string}
 */
export function normalizeDomainLinkText(text) {
    return String(text ?? '')
        .normalize('NFC')
        .toLowerCase()
        .replace(/[\u0027\u2018\u2019\u201B\u2032\u0060\u00B4\u02BC\uFF07]/g, '')
        .replace(/[^\p{L}\p{N}\s]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/**
 * Accent-insensitive fallback (Vietnamese). Never score higher than accented match.
 *
 * @param {string} text
 * @returns {string}
 */
export function foldDomainLinkAccents(text) {
    return normalizeDomainLinkText(text)
        .normalize('NFD')
        .replace(/\p{M}+/gu, '');
}

/**
 * @param {string} text
 * @returns {string[]}
 */
export function tokenizeDomainLinkText(text) {
    const normalized = normalizeDomainLinkText(text);
    if (normalized === '') {
        return [];
    }

    return normalized.split(' ').filter(Boolean);
}

/**
 * Meaningful tokens from an anchor (empty/punct already stripped by normalize).
 *
 * @param {string} anchor
 * @returns {string[]}
 */
export function meaningfulDomainLinkTokens(anchor) {
    return tokenizeDomainLinkText(anchor);
}

/**
 * @param {string} html
 * @returns {string}
 */
export function plainTextFromHtmlForDomainLink(html) {
    const source = String(html ?? '');
    if (source.trim() === '') {
        return '';
    }

    try {
        const doc = new DOMParser().parseFromString(source, 'text/html');
        return String(doc.body?.textContent ?? '').replace(/\s+/g, ' ').trim();
    } catch {
        return source.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    }
}
