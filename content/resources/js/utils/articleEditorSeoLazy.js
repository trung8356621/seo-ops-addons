import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';

/** @type {Promise<unknown>|null} */
let inflight = null;
let inflightKey = '';

/**
 * One in-flight settings GET per article. Do not pass AbortSignal — Livewire
 * remount / Strict Mode must not paint these as Network (canceled).
 *
 * @param {{
 *   articleId: number|string|null,
 *   settingsUrl: string,
 * }} args
 * @returns {Promise<{response: Response, data: object}>}
 */
export function loadArticleEditorSeoSettings({ articleId, settingsUrl }) {
    const key = `${Number(articleId) || 0}|${settingsUrl}`;
    if (inflight !== null && inflightKey === key) {
        return inflight;
    }

    inflightKey = key;
    inflight = seoArticleApiFetch(settingsUrl).finally(() => {
        if (inflightKey === key) {
            inflight = null;
            inflightKey = '';
        }
    });

    return inflight;
}
