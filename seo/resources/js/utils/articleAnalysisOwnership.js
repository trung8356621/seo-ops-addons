/**
 * Phase 2B — analysis policy normalize + reason alias + immediate analysis helpers.
 * React owns immediate analysis; Laravel owns policy/thresholds.
 */

import { getMediaSnapshot } from '@content-addon/utils/articleEditorMediaSnapshot.js';
import { TARGET_WORDS_PER_IMAGE as FALLBACK_WORDS_PER_IMAGE } from './seoReasonMetrics';

export const ARTICLE_EDITOR_ANALYSIS_POLICY_EVENT = 'article-editor-analysis-policy-changed';

const DEFAULT_ALIASES = Object.freeze({
    'seo.length': 'content_length_low',
    'seo_rules.content_length_low': 'content_length_low',
    'seo.image_ratio': 'image_ratio_missing',
    focus_keyword_missing: 'missing_focus_keyword',
});

/** @type {object|null} */
let cachedPolicy = null;

export function setAnalysisPolicy(policy) {
    cachedPolicy = policy && typeof policy === 'object' ? policy : null;
    window.__SEO_ANALYSIS_POLICY__ = cachedPolicy;
    window.dispatchEvent(new CustomEvent(ARTICLE_EDITOR_ANALYSIS_POLICY_EVENT, {
        detail: { policy: cachedPolicy },
    }));

    return cachedPolicy;
}

export function getAnalysisPolicy() {
    return cachedPolicy || window.__SEO_ANALYSIS_POLICY__ || null;
}

/** @type {object|null} */
let cachedExternalFacts = null;

export function setExternalFacts(facts) {
    cachedExternalFacts = facts && typeof facts === 'object' ? facts : null;
    window.__SEO_EXTERNAL_FACTS__ = cachedExternalFacts;

    return cachedExternalFacts;
}

export function getExternalFacts() {
    return cachedExternalFacts || window.__SEO_EXTERNAL_FACTS__ || null;
}

export function normalizeReasonCode(code, policy = getAnalysisPolicy()) {
    const raw = String(code ?? '').trim();
    if (raw === '') {
        return '';
    }
    const aliases = {
        ...DEFAULT_ALIASES,
        ...(policy?.reason_aliases && typeof policy.reason_aliases === 'object' ? policy.reason_aliases : {}),
    };

    return aliases[raw] || raw;
}

export function normalizeViolationList(violations, policy = getAnalysisPolicy()) {
    if (!Array.isArray(violations)) {
        return [];
    }
    const seen = new Set();
    const out = [];
    violations.forEach((code) => {
        const normalized = normalizeReasonCode(code, policy);
        if (!normalized || seen.has(normalized)) {
            return;
        }
        seen.add(normalized);
        out.push(normalized);
    });

    return out;
}

export function wordsPerImageFromPolicy(policy = getAnalysisPolicy()) {
    const value = Number(policy?.images?.words_per_image);

    return Number.isFinite(value) && value > 0 ? value : FALLBACK_WORDS_PER_IMAGE;
}

export function minimumValidLinksFromPolicy(policy = getAnalysisPolicy()) {
    const value = Number(policy?.links?.minimum_valid_links);

    return Number.isFinite(value) && value > 0 ? value : 5;
}

export function articleLengthSettingsFromPolicy(policy = getAnalysisPolicy()) {
    return {
        article_length_product: policy?.content?.article_length_product,
        article_length_default: policy?.content?.article_length_default,
    };
}

/**
 * Prefer media snapshot content_images for ratio math.
 */
export function resolveContentImageCounts(articleId, htmlFallbackMetrics = null) {
    const snap = getMediaSnapshot(articleId);
    const content = snap?.content_images;
    if (content && typeof content === 'object') {
        const valid = Math.max(0, Number(content.valid_count) || 0);
        const occurrence = Math.max(0, Number(content.occurrence_count) || valid);

        return {
            valid_image_count: valid,
            current_image_count: valid,
            occurrence_count: occurrence,
            invalid_count: Math.max(0, Number(content.invalid_count) || 0),
            source: 'media_snapshot',
        };
    }

    return {
        valid_image_count: Math.max(0, Number(htmlFallbackMetrics?.valid_image_count) || 0),
        current_image_count: Math.max(0, Number(htmlFallbackMetrics?.current_image_count) || 0),
        occurrence_count: Math.max(0, Number(htmlFallbackMetrics?.current_image_count) || 0),
        invalid_count: 0,
        source: 'html_fallback',
    };
}

export function applyImageCountsToMetrics(metrics, counts, policy = getAnalysisPolicy()) {
    const words = Math.max(0, Number(metrics?.current_word_count) || 0);
    const wordsPerImage = wordsPerImageFromPolicy(policy);
    const valid = Math.max(0, Number(counts?.valid_image_count) || 0);
    const recommended = words > 0 ? Math.max(1, Math.ceil(words / wordsPerImage)) : 0;
    const missing = Math.max(0, recommended - valid);

    return {
        ...(metrics && typeof metrics === 'object' ? metrics : {}),
        current_image_count: valid,
        valid_image_count: valid,
        block_image_count: valid,
        recommended_image_count: recommended,
        missing_image_count: missing,
        target_words_per_image: wordsPerImage,
        current_word_count: words,
        count_source: counts?.source || 'unknown',
    };
}

export default {
    ARTICLE_EDITOR_ANALYSIS_POLICY_EVENT,
    setAnalysisPolicy,
    getAnalysisPolicy,
    setExternalFacts,
    getExternalFacts,
    normalizeReasonCode,
    normalizeViolationList,
    wordsPerImageFromPolicy,
    minimumValidLinksFromPolicy,
    articleLengthSettingsFromPolicy,
    resolveContentImageCounts,
    applyImageCountsToMetrics,
};
