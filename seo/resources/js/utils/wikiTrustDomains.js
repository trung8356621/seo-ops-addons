export const DEFAULT_WIKI_TRUST_DOMAINS = ['wikipedia.org', '*.gov', '*.edu'];

export function normalizeDomainHost(domain) {
    let value = String(domain ?? '').trim().toLowerCase();
    value = value.replace(/^https?:\/\//, '').replace(/\/+$/, '');

    return value.startsWith('www.') ? value.slice(4) : value;
}

export function resolveLinkHost(href) {
    let value = String(href ?? '').trim();
    if (value === '') {
        return '';
    }

    if (value.startsWith('//')) {
        value = `https:${value}`;
    }

    try {
        if (value.startsWith('/')) {
            return '';
        }

        const url = new URL(value, 'https://placeholder.local');
        return normalizeDomainHost(url.hostname);
    } catch {
        return '';
    }
}

function hostMatchesPattern(host, pattern) {
    const normalizedHost = normalizeDomainHost(host);
    const normalizedPattern = normalizeDomainHost(pattern);

    if (normalizedHost === '' || normalizedPattern === '') {
        return false;
    }

    if (normalizedPattern.startsWith('*.')) {
        const suffix = normalizedPattern.slice(1);

        return normalizedHost === normalizedPattern.slice(2) || normalizedHost.endsWith(suffix);
    }

    if (normalizedPattern.includes('*')) {
        const escaped = normalizedPattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*');
        const regex = new RegExp(`^${escaped}$`, 'i');

        return regex.test(normalizedHost);
    }

    return normalizedHost === normalizedPattern || normalizedHost.endsWith(`.${normalizedPattern}`);
}

export function isWikiTrustHost(host, customDomains = DEFAULT_WIKI_TRUST_DOMAINS) {
    const normalizedHost = normalizeDomainHost(host);
    if (normalizedHost === '') {
        return false;
    }

    const patterns = Array.isArray(customDomains) && customDomains.length > 0
        ? customDomains
        : DEFAULT_WIKI_TRUST_DOMAINS;

    return patterns.some((pattern) => hostMatchesPattern(normalizedHost, pattern));
}

export function isWikiTrustUrl(href, customDomains = DEFAULT_WIKI_TRUST_DOMAINS) {
    return isWikiTrustHost(resolveLinkHost(href), customDomains);
}

export function parseWikiTrustDomainsTextarea(text) {
    return String(text ?? '')
        .split(/\r?\n/u)
        .map((line) => line.trim())
        .filter(Boolean);
}

export function wikiTrustDomainsToTextarea(domains) {
    if (!Array.isArray(domains)) {
        return DEFAULT_WIKI_TRUST_DOMAINS.join('\n');
    }

    return domains.join('\n');
}
