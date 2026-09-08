/**
 * Shared link-preview pipeline for Topic + Comment.
 * Single fetch/cache path — no comment-specific duplicate implementation.
 */

import { fetchLinkPreview } from '../api';
import { extractLinksFromPaste } from './linkExtract';
import { normalizeUrlKey } from './storage';

/** In-flight fetches keyed by normalized URL — shared across Topic/Comment mounts. */
/** @type {Map<string, Promise<Record<string, unknown>>>} */
const inflightByUrl = new Map();

/**
 * @param {Partial<Record<string, unknown>> & { url: string, normalized_url?: string }} partial
 */
export function emptyLinkRecord(partial) {
    const url = String(partial.url || '').trim();
    const key = String(partial.normalized_url || normalizeUrlKey(url));
    return {
        url,
        normalized_url: key,
        detected_at: partial.detected_at || new Date().toISOString(),
        preview_url: partial.preview_url ?? null,
        preview_title: partial.preview_title ?? null,
        preview_description: partial.preview_description ?? null,
        preview_image_url: partial.preview_image_url ?? null,
        preview_domain: partial.preview_domain ?? null,
        preview_fetched_at: partial.preview_fetched_at ?? null,
        preview_status: partial.preview_status ?? null,
    };
}

/**
 * Extract URLs from free text (manual / AI / paste) into link stubs.
 * @param {string} text
 * @param {string|null} [html]
 */
export function linksFromContent(text, html = null) {
    return extractLinksFromPaste(text, html).map((l) => emptyLinkRecord(l));
}

/**
 * @param {Record<string, unknown>} link
 * @param {Record<string, unknown>} meta
 */
export function applyPreviewMeta(link, meta) {
    return emptyLinkRecord({
        ...link,
        preview_url: meta.preview_url || link.url,
        preview_title: meta.preview_title ?? null,
        preview_description: meta.preview_description ?? null,
        preview_image_url: meta.preview_image_url ?? null,
        preview_domain: meta.preview_domain ?? null,
        preview_fetched_at: meta.preview_fetched_at || new Date().toISOString(),
        preview_status: meta.preview_status || (meta.ok === false ? 'error' : 'ok'),
    });
}

/**
 * @param {Record<string, unknown>|null|undefined} entry
 */
export function cacheEntryFromLink(entry) {
    if (!entry || !entry.url) return null;
    const key = String(entry.normalized_url || normalizeUrlKey(String(entry.url)));
    if (!key) return null;
    return {
        url: String(entry.url),
        normalized_url: key,
        preview_url: entry.preview_url || entry.url,
        preview_title: entry.preview_title ?? null,
        preview_description: entry.preview_description ?? null,
        preview_image_url: entry.preview_image_url ?? null,
        preview_domain: entry.preview_domain ?? null,
        preview_fetched_at: entry.preview_fetched_at || null,
        preview_status: entry.preview_status || null,
    };
}

/**
 * Merge cache hit into stub links that lack preview_fetched_at.
 * @param {Array<Record<string, unknown>>} links
 * @param {Record<string, Record<string, unknown>>} cache
 */
export function hydrateLinksFromCache(links, cache = {}) {
    return (Array.isArray(links) ? links : []).map((link) => {
        if (link.preview_fetched_at) return emptyLinkRecord(link);
        const key = String(link.normalized_url || normalizeUrlKey(String(link.url || '')));
        const hit = cache[key];
        if (!hit || !hit.preview_fetched_at) return emptyLinkRecord(link);
        return applyPreviewMeta(link, hit);
    });
}

/**
 * Re-detect URLs from text; drop stale URLs; keep / hydrate meta for URLs that remain.
 * @param {string} text
 * @param {Array<Record<string, unknown>>} [previousLinks]
 * @param {Record<string, Record<string, unknown>>} [cache]
 */
export function syncLinksWithText(text, previousLinks = [], cache = {}) {
    const extracted = linksFromContent(text);
    /** @type {Map<string, Record<string, unknown>>} */
    const prev = new Map();
    for (const link of previousLinks || []) {
        const key = String(link.normalized_url || normalizeUrlKey(String(link.url || '')));
        if (key) prev.set(key, link);
    }

    return extracted.map((stub) => {
        const prior = prev.get(stub.normalized_url);
        if (prior?.preview_fetched_at) {
            return applyPreviewMeta(stub, prior);
        }
        const hit = cache[stub.normalized_url];
        if (hit?.preview_fetched_at) {
            return applyPreviewMeta(stub, hit);
        }
        return stub;
    });
}

/**
 * Build a comment record with links derived from text (manual + AI share this path).
 * @param {string} text
 * @param {Record<string, unknown>} [fields]
 * @param {Record<string, Record<string, unknown>>} [cache]
 */
export function buildCommentRecord(text, fields = {}, cache = {}) {
    const body = String(text || '').trim();
    return {
        id: fields.id,
        text: body,
        state: fields.state || 'available',
        source: fields.source === 'ai' ? 'ai' : 'manual',
        claimed_by_user_id: fields.claimed_by_user_id ?? null,
        claimed_by_display_name: fields.claimed_by_display_name ?? null,
        claimed_at: fields.claimed_at ?? null,
        completed_at: fields.completed_at ?? null,
        created_at: fields.created_at || new Date().toISOString(),
        author_user_id: fields.author_user_id ?? null,
        author_display_name: fields.author_display_name || '',
        links: syncLinksWithText(body, fields.links || [], cache),
    };
}

/**
 * Fetch missing previews; dedupe by normalized URL across callers via inflight + cache.
 *
 * @param {Array<Record<string, unknown>>} links
 * @param {{
 *   cache?: Record<string, Record<string, unknown>>,
 *   onCacheUpdate?: (cache: Record<string, Record<string, unknown>>) => void,
 * }} [options]
 * @returns {Promise<Array<Record<string, unknown>>>}
 */
export async function ensureLinkPreviews(links, options = {}) {
    const cache = { ...(options.cache || {}) };
    const list = Array.isArray(links) ? links : [];
    /** @type {Array<Record<string, unknown>>} */
    const out = [];
    let cacheDirty = false;

    for (const raw of list) {
        const link = emptyLinkRecord(raw);
        const key = link.normalized_url;

        if (link.preview_fetched_at) {
            out.push(link);
            if (!cache[key]?.preview_fetched_at) {
                const entry = cacheEntryFromLink(link);
                if (entry) {
                    cache[key] = entry;
                    cacheDirty = true;
                }
            }
            continue;
        }

        if (cache[key]?.preview_fetched_at) {
            out.push(applyPreviewMeta(link, cache[key]));
            continue;
        }

        let pending = inflightByUrl.get(key);
        if (!pending) {
            pending = fetchLinkPreview(link.url)
                .then((meta) => {
                    const entry = cacheEntryFromLink(applyPreviewMeta(link, meta));
                    return entry || applyPreviewMeta(link, meta);
                })
                .finally(() => {
                    inflightByUrl.delete(key);
                });
            inflightByUrl.set(key, pending);
        }

        const entry = await pending;
        cache[key] = entry;
        cacheDirty = true;
        out.push(applyPreviewMeta(link, entry));
    }

    if (cacheDirty) {
        options.onCacheUpdate?.(cache);
    }

    return out;
}

/**
 * Collect preview entries from topic + comment links into one cache map.
 * @param {Array<Record<string, unknown>>} topics
 * @param {Record<string, Record<string, unknown>>} [existing]
 */
export function collectLinkPreviewCache(topics, existing = {}) {
    /** @type {Record<string, Record<string, unknown>>} */
    const cache = { ...existing };
    const absorb = (links) => {
        for (const link of links || []) {
            if (!link?.preview_fetched_at) continue;
            const entry = cacheEntryFromLink(link);
            if (!entry) continue;
            const prev = cache[entry.normalized_url];
            if (!prev || String(entry.preview_fetched_at) >= String(prev.preview_fetched_at || '')) {
                cache[entry.normalized_url] = entry;
            }
        }
    };

    for (const topic of topics || []) {
        absorb(topic.links);
        for (const comment of topic.comments || []) {
            absorb(comment.links);
        }
    }
    return cache;
}

/** @internal test helper */
export function __resetInflightForTests() {
    inflightByUrl.clear();
}
