import { normalizeUrlKey } from './storage';

const SOCIAL_HOSTS = [
    'facebook.com', 'fb.com', 'fb.watch',
    'tiktok.com',
    'instagram.com',
    'youtube.com', 'youtu.be',
    'reddit.com',
    'threads.net', 'threads.com',
    'x.com', 'twitter.com',
    'linkedin.com',
];

/**
 * Extract URLs from plain text + optional HTML anchors.
 * Returns snapshot-ready link objects (deduped).
 *
 * @param {string} plainText
 * @param {string|null|undefined} html
 * @returns {Array<{ url: string, normalized_url: string, detected_at: string }>}
 */
export function extractLinksFromPaste(plainText, html = null) {
    const now = new Date().toISOString();
    /** @type {Map<string, { url: string, normalized_url: string, detected_at: string }>} */
    const map = new Map();

    const push = (raw) => {
        const url = cleanupUrl(raw);
        if (!url || !/^https?:\/\//i.test(url)) return;
        const key = normalizeUrlKey(url);
        if (!key || map.has(key)) return;
        map.set(key, { url, normalized_url: key, detected_at: now });
    };

    const htmlSrc = String(html || '');
    if (htmlSrc && /<a\b/i.test(htmlSrc)) {
        const re = /<a\b[^>]*\bhref\s*=\s*(["'])(.*?)\1[^>]*>/gi;
        let m;
        while ((m = re.exec(htmlSrc)) !== null) {
            push(decodeHtmlEntities(m[2]));
        }
    }

    const text = String(plainText || '');
    const plainRe = /https?:\/\/[^\s<>"'）)\]]+/gi;
    let pm;
    while ((pm = plainRe.exec(text)) !== null) {
        push(pm[0]);
    }

    return [...map.values()];
}

/**
 * First known social URL from a link list (for autofill).
 * @param {Array<{ url: string }>} links
 */
export function suggestSocialUrl(links) {
    for (const link of links || []) {
        const host = hostOf(link.url);
        if (!host) continue;
        if (SOCIAL_HOSTS.some((h) => host === h || host.endsWith(`.${h}`))) {
            return link.url;
        }
    }
    return null;
}

export function hostOf(url) {
    try {
        return new URL(String(url)).host.replace(/^www\./, '').toLowerCase();
    } catch {
        return null;
    }
}

export function detectPlatformLabel(url) {
    const host = hostOf(url) || '';
    if (!host) return null;
    if (host.includes('facebook') || host.includes('fb.com') || host.includes('fb.watch')) return 'Facebook';
    if (host.includes('tiktok')) return 'TikTok';
    if (host.includes('instagram')) return 'Instagram';
    if (host.includes('youtube') || host.includes('youtu.be')) return 'YouTube';
    if (host.includes('reddit')) return 'Reddit';
    if (host.includes('threads')) return 'Threads';
    if (host === 'x.com' || host.includes('twitter')) return 'X';
    if (host.includes('linkedin')) return 'LinkedIn';
    return 'Web';
}

function cleanupUrl(raw) {
    return String(raw || '')
        .trim()
        .replace(/&amp;/gi, '&')
        .replace(/[),.;!?]+$/g, '');
}

function decodeHtmlEntities(value) {
    return String(value || '')
        .replace(/&amp;/g, '&')
        .replace(/&quot;/g, '"')
        .replace(/&#39;/g, "'")
        .replace(/&lt;/g, '<')
        .replace(/&gt;/g, '>');
}
