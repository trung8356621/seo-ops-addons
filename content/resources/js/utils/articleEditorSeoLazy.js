import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';

/** @type {Promise<[unknown, unknown]>|null} */
let inflight = null;
let inflightKey = '';

/**
 * One in-flight GET pair per article. Do not pass AbortSignal — Livewire
 * remount / Strict Mode must not paint these as Network (canceled).
 *
 * @param {{
 *   articleId: number|string|null,
 *   seoSummaryUrl: string,
 *   settingsUrl: string,
 * }} args
 * @returns {Promise<[{response: Response, data: object}, {response: Response, data: object}]>}
 */
export function loadArticleEditorSeoLazy({ articleId, seoSummaryUrl, settingsUrl }) {
    const key = `${Number(articleId) || 0}|${seoSummaryUrl}|${settingsUrl}`;
    if (inflight !== null && inflightKey === key) {
        return inflight;
    }

    inflightKey = key;
    inflight = Promise.all([
        seoArticleApiFetch(seoSummaryUrl),
        seoArticleApiFetch(settingsUrl),
    ]).finally(() => {
        if (inflightKey === key) {
            inflight = null;
            inflightKey = '';
        }
    });

    return inflight;
}
