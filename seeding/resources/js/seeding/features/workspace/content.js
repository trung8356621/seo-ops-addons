/**
 * Split topic content into text / URL segments for inline link-preview replacement.
 */

const URL_RE = /https?:\/\/[^\s<>"'）)\]]+/gi;

/**
 * @param {string} text
 * @returns {Array<{ type: 'text'|'url', value: string }>}
 */
export function splitContentByUrls(text) {
    const source = String(text || '');
    if (!source) return [];
    /** @type {Array<{ type: 'text'|'url', value: string }>} */
    const parts = [];
    let last = 0;
    let m;
    const re = new RegExp(URL_RE.source, 'gi');
    while ((m = re.exec(source)) !== null) {
        if (m.index > last) {
            parts.push({ type: 'text', value: source.slice(last, m.index) });
        }
        parts.push({ type: 'url', value: cleanupUrl(m[0]) });
        last = m.index + m[0].length;
    }
    if (last < source.length) {
        parts.push({ type: 'text', value: source.slice(last) });
    }
    return parts;
}

/**
 * True when title is empty or only an auto-copy / prefix of content.
 * @param {string|null|undefined} title
 * @param {string|null|undefined} fullText
 */
export function isAutoCopiedTitle(title, fullText) {
    const t = String(title || '').replace(/\s+/g, ' ').trim();
    const body = String(fullText || '').replace(/\s+/g, ' ').trim();
    if (t === '') return true;
    if (body === '') return false;
    if (t === body) return true;
    if (body.startsWith(t)) return true;
    const clipped = body.length > 80 ? `${body.slice(0, 80)}…` : body;
    if (t === clipped) return true;
    return false;
}

/**
 * Feed content preview — clamp length; URLs stripped for plain preview length estimate.
 * @param {string} fullText
 * @param {number} [max]
 */
export function feedContentPreview(fullText, max = 140) {
    const text = String(fullText || '').replace(/\s+/g, ' ').trim();
    if (text === '') return '';
    if (text.length <= max) return text;
    return `${text.slice(0, max)}…`;
}

/**
 * @param {Array<{ url?: string, normalized_url?: string }>} links
 * @param {string} url
 */
export function findLinkMeta(links, url) {
    const list = Array.isArray(links) ? links : [];
    const needle = String(url || '').trim().toLowerCase();
    if (!needle) return null;
    return list.find((l) => {
        const a = String(l.url || '').trim().toLowerCase();
        const b = String(l.normalized_url || '').trim().toLowerCase();
        return a === needle || b === needle || a === needle.replace(/\/$/, '') || needle.startsWith(a);
    }) || null;
}

/**
 * Has usable rich preview (not just failed fetch).
 * @param {Record<string, unknown>|null|undefined} link
 */
export function hasRichPreview(link) {
    if (!link || typeof link !== 'object') return false;
    if (link.preview_status && link.preview_status !== 'ok') return false;
    return Boolean(link.preview_title || link.preview_image_url || link.preview_domain);
}

/**
 * Latest N comments for feed preview (newest first then reverse for display).
 * @param {Array<Record<string, unknown>>} comments
 * @param {number} [limit]
 */
export function latestCommentsPreview(comments, limit = 2) {
    const list = Array.isArray(comments) ? [...comments] : [];
    list.sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
    return list.slice(0, limit).reverse();
}

function cleanupUrl(raw) {
    return String(raw || '')
        .trim()
        .replace(/&amp;/gi, '&')
        .replace(/[),.;!?]+$/g, '');
}
