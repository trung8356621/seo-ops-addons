/**
 * Cached / live SEO analysis is READY only when it is a real completed result,
 * not an empty default that would score as 100/100.
 */

/**
 * @param {unknown} analysis
 * @returns {boolean}
 */
export function isCompletedSeoAnalysis(analysis) {
    if (!analysis || typeof analysis !== 'object') {
        return false;
    }

    if (!Object.prototype.hasOwnProperty.call(analysis, 'violations')) {
        return false;
    }

    if (!Array.isArray(analysis.violations)) {
        return false;
    }

    const raw = analysis.total_score ?? analysis.score ?? analysis.seo_score;
    if (raw === null || raw === undefined || raw === '') {
        return false;
    }

    return Number.isFinite(Number(raw));
}

/**
 * Restore cached analysis only when fingerprint still matches current content.
 *
 * @param {object|null|undefined} initialSeo
 * @param {{ contentHash?: string, bodyHash?: string }} [current]
 * @returns {boolean}
 */
export function isCachedSeoAnalysisValid(initialSeo, current = {}) {
    const analysis = initialSeo?.analysis ?? null;
    if (!isCompletedSeoAnalysis(analysis)) {
        return false;
    }

    const analyzedHash = String(
        initialSeo?.analyzed_content_hash
        ?? analysis?.content_hash
        ?? analysis?.analyzed_content_hash
        ?? '',
    ).trim();

    const currentHash = String(
        current.contentHash
        ?? current.bodyHash
        ?? initialSeo?.content_hash
        ?? '',
    ).trim();

    // No fingerprint available → do not treat as READY (avoid fake pass).
    if (analyzedHash === '' || currentHash === '') {
        return false;
    }

    return analyzedHash === currentHash;
}

export default {
    isCompletedSeoAnalysis,
    isCachedSeoAnalysisValid,
};
