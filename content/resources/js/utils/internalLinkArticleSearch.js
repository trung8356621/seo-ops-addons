import { callEditArticleLivewire } from './articleEditorLivewire';
import { normalizePhraseForMatch } from './articleLinkSuggestionFilter';

const ARTICLE_SEARCH_MIN_CHARS = 2;

/** @type {Map<string, Promise<Array<{id: number, title: string, url: string, label: string}>>>} */
const sessionCache = new Map();

/**
 * @param {number} siteId
 * @param {string} phrase
 */
export function internalLinkArticleSearchCacheKey(siteId, phrase) {
    const normalized = normalizePhraseForMatch(phrase);
    return `${Number(siteId) || 0}\u0000${normalized}`;
}

/**
 * Reuses EditArticle::searchInternalLinkArticles (same-domain Find Article).
 * Session-cached by site + normalized phrase. Returns max `limit` deduped rows.
 *
 * @param {string} phrase
 * @param {{ siteId?: number, articleId?: number, limit?: number }} [options]
 * @returns {Promise<Array<{id: number, title: string, url: string, label: string}>>}
 */
export async function searchInternalLinkArticlesCached(phrase, options = {}) {
    const trimmed = String(phrase ?? '').trim();
    const siteId = Number(options.siteId ?? 0);
    const articleId = Number(options.articleId ?? 0);
    const limit = Math.max(1, Math.min(15, Number(options.limit ?? 3) || 3));

    if (trimmed.length < ARTICLE_SEARCH_MIN_CHARS) {
        return [];
    }

    const cacheKey = internalLinkArticleSearchCacheKey(siteId, trimmed);
    let pending = sessionCache.get(cacheKey);
    if (!pending) {
        pending = (async () => {
            const results = await callEditArticleLivewire('searchInternalLinkArticles', trimmed);
            const rows = Array.isArray(results) ? results : [];
            const seenIds = new Set();
            const seenUrls = new Set();
            const out = [];

            for (const row of rows) {
                if (!row || typeof row !== 'object') {
                    continue;
                }
                const id = Number(row.id ?? 0);
                const url = String(row.url ?? '').trim();
                const title = String(row.title ?? '').trim();
                if (id > 0 && id === articleId) {
                    continue;
                }
                if (id > 0 && seenIds.has(id)) {
                    continue;
                }
                if (url !== '' && seenUrls.has(url.toLowerCase())) {
                    continue;
                }
                if (id > 0) {
                    seenIds.add(id);
                }
                if (url !== '') {
                    seenUrls.add(url.toLowerCase());
                }
                out.push({
                    id,
                    title,
                    url,
                    label: String(row.label ?? (title !== '' ? title : url)).trim(),
                });
            }

            return out;
        })().catch((error) => {
            sessionCache.delete(cacheKey);
            throw error;
        });

        sessionCache.set(cacheKey, pending);
    }

    const rows = await pending;
    return rows.slice(0, limit);
}

/** @internal test helper */
export function __clearInternalLinkArticleSearchCacheForTests() {
    sessionCache.clear();
}
