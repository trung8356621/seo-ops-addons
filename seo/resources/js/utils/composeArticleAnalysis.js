/**
 * Phase 2B — single entry for immediate article analysis.
 * Widgets must consume composed output; do not re-parse thresholds locally.
 */

import { computeSeoAnalysis } from './seoAnalyzer';
import {
    getAnalysisPolicy,
    normalizeReasonCode,
    normalizeViolationList,
} from './articleAnalysisOwnership';

/**
 * @param {{
 *   documentHtml?: string,
 *   document?: object|null,
 *   documentModel?: object|null,
 *   blocks?: array|null,
 *   plainText?: string,
 *   focusKeyword?: string,
 *   articleType?: string,
 *   articleId?: number|string|null,
 *   mediaSnapshot?: object|null,
 *   externalFacts?: object|null,
 *   policy?: object|null,
 *   seoTitle?: string,
 *   metaDescription?: string,
 *   slug?: string,
 *   siteDomain?: string,
 *   faqs?: array,
 *   wikiTrustDomains?: array,
 *   scoringMessages?: object,
 *   seoScoringRules?: array,
 * }} input
 */
export function composeImmediateArticleAnalysis(input = {}) {
    const policy = input.policy || getAnalysisPolicy();
    const result = computeSeoAnalysis({
        html: input.documentHtml ?? input.html ?? '',
        document: input.document ?? null,
        documentModel: input.documentModel ?? null,
        blocks: input.blocks ?? null,
        focusKeyword: input.focusKeyword,
        seoTitle: input.seoTitle,
        metaDescription: input.metaDescription,
        slug: input.slug,
        siteDomain: input.siteDomain,
        faqs: input.faqs,
        wikiTrustDomains: input.wikiTrustDomains,
        scoringMessages: input.scoringMessages,
        seoScoringRules: input.seoScoringRules,
        postType: input.articleType ?? input.postType,
        articleLengthSettings: input.articleLengthSettings,
        featuredSnippetThresholds: input.featuredSnippetThresholds,
        analysisPolicy: policy,
        articleId: input.articleId,
        mediaSnapshot: input.mediaSnapshot,
        externalFacts: input.externalFacts,
    });

    const violations = normalizeViolationList(result.violations, policy);

    return {
        ...result,
        violations,
        reasons: violations.map((code) => ({
            code: normalizeReasonCode(code, policy),
            severity: policy?.reason_codes?.[normalizeReasonCode(code, policy)]?.default_severity ?? 'warning',
            target: policy?.reason_codes?.[normalizeReasonCode(code, policy)]?.widget ?? 'seo',
            params: result.metrics ?? {},
        })),
        score: result.score ?? result.seo_score ?? 0,
        summary: {
            word_count: result.metrics?.content_length?.current_word_count
                ?? result.metrics?.image_ratio?.current_word_count
                ?? null,
            image_ratio: result.metrics?.image_ratio ?? null,
            content_length: result.metrics?.content_length ?? null,
        },
        policy_version: Number(policy?.version) || Number(result.policy_version) || 1,
        analysis_owner: 'react_immediate',
        updated_at: Date.now(),
    };
}

export default composeImmediateArticleAnalysis;
