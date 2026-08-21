import { DEFAULT_WIKI_TRUST_DOMAINS } from '@seo-addon/utils/wikiTrustDomains.js';
import { filterSuggestedInternalLinks, isSpecialOrContactHref, mergeSuggestionCatalog } from '../utils/articleLinkSuggestionFilter';
import { seoActions, seoApi, getSeoState } from '@seo-addon/editor/domains/seo/state.js';
import { isCachedSeoAnalysisValid, isCompletedSeoAnalysis } from '../utils/seoAnalysisReadiness';
import { useEffect, useRef, useState } from 'react';
import { useSeoEditor } from '@seo-addon/editor/domains/seo/useSeoEditor.js';

/**
 * useArticleEditorSeoAndLinksState - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorSeoAndLinksState({ articleTitle, editorSettings, initialPostType, initialSeo, setSeoSummaryError, setSeoSummaryLoading }) {
    const [siteDomain] = useState(() => String(initialSeo?.site_domain ?? '').trim());
    const [articleType, setArticleType] = useState(
        () => String(initialSeo?.article_type ?? initialPostType ?? 'post').trim(),
    );
    const wikiTrustDomains = Array.isArray(editorSettings?.wiki_trust_domains)
        ? editorSettings.wiki_trust_domains
        : DEFAULT_WIKI_TRUST_DOMAINS;
    const [scoringMessages, setScoringMessages] = useState(() => (
        editorSettings?.seo_rule_messages && typeof editorSettings.seo_rule_messages === 'object'
            ? editorSettings.seo_rule_messages
            : editorSettings?.seo_scoring_messages && typeof editorSettings.seo_scoring_messages === 'object'
              ? editorSettings.seo_scoring_messages
              : {}
    ));
    const [seoScoringRules, setSeoScoringRules] = useState(() => (Array.isArray(editorSettings?.seo_scoring_rules)
        ? editorSettings.seo_scoring_rules
        : Array.isArray(editorSettings?.analysis_policy?.seo_scoring_rules)
          ? editorSettings.analysis_policy.seo_scoring_rules
        : Array.isArray(initialSeo?.seo_scoring_rules)
          ? initialSeo.seo_scoring_rules
          : []
    ));
    const seoMetaRef = useRef({
        seoTitle: String(articleTitle ?? initialSeo?.google_serp_preview?.title ?? '').trim(),
        metaDescription: String(
            initialSeo?.google_serp_preview?.description
            ?? initialSeo?.meta_description
            ?? '',
        ).trim(),
        slug: String(initialSeo?.article_slug ?? '').trim(),
    });
    const lastSeoAnalysisRef = useRef(null);
    const hasHydratedSeoFromServerRef = useRef(false);
    const { focusKeyword, analysis, seoScore, actions: seoDomain } = useSeoEditor();
    const [savedSeoScore, setSavedSeoScore] = useState(() => (
        initialSeo?.score === null || initialSeo?.score === undefined
            ? null
            : Number(initialSeo.score)
    ));
    const [seoScoreSource, setSeoScoreSource] = useState('saved');
    const [mediaHealthTick, setMediaHealthTick] = useState(0);
    // Declared before effects that close over it — avoids TDZ after minify (`const` used before init).
    const siteDomainRef = useRef(String(initialSeo?.site_domain ?? '').trim());

    useEffect(() => {
        const currentHash = String(initialSeo?.content_hash ?? '').trim();
        const cacheValid = isCachedSeoAnalysisValid(initialSeo, {
            contentHash: currentHash,
            bodyHash: currentHash,
        });
        if (cacheValid) {
            seoApi.adopt(initialSeo.analysis, initialSeo?.focus_keyword ?? '');
            setSeoScoreSource('saved');
        } else {
            seoActions.clearAnalysis();
            seoApi.adopt(null, initialSeo?.focus_keyword ?? '');
        }
        seoActions.markClean();
        setSeoSummaryLoading(false);
        setSeoSummaryError(null);
    }, []);

    useEffect(() => {
        const onSeoSettings = (event) => {
            const detail = event?.detail ?? {};
            if (!detail || typeof detail !== 'object') {
                return;
            }
            if (Array.isArray(detail.seo_scoring_rules)) {
                setSeoScoringRules(detail.seo_scoring_rules);
            }
            const messages = detail.seo_rule_messages ?? detail.seo_scoring_messages;
            if (messages && typeof messages === 'object') {
                setScoringMessages(messages);
            }
        };
        window.addEventListener('seo-editor-seo-settings-loaded', onSeoSettings);
        return () => window.removeEventListener('seo-editor-seo-settings-loaded', onSeoSettings);
    }, []);

    useEffect(() => {
        const onSavePatched = (event) => {
            const persistedScore = event?.detail?.article?.seo_score;
            if (persistedScore !== null && persistedScore !== undefined) {
                const value = Number(persistedScore);
                if (Number.isFinite(value)) {
                    // Saved score is supplemental comparison state only. Never replace
                    // the live current-draft analysis after an ACK.
                    setSavedSeoScore(value);
                }
            }

            const contentHash = String(
                event?.detail?.article?.content_hash
                ?? event?.detail?.content_hash
                ?? '',
            ).trim();
            const current = getSeoState()?.analysis;
            if (contentHash !== '' && isCompletedSeoAnalysis(current)) {
                seoActions.patch({
                    analysis: {
                        ...current,
                        content_hash: contentHash,
                    },
                });
                seoActions.markClean();
            }
        };
        window.addEventListener('article-editor-save-patched', onSavePatched);
        return () => window.removeEventListener('article-editor-save-patched', onSavePatched);
    }, []);

    const [extractedLinks, setExtractedLinks] = useState(() => {
        const source = initialSeo?.extracted_links ?? { internal: [], external: [] };

        return {
            internal: Array.isArray(source.internal) ? source.internal : [],
            external: (Array.isArray(source.external) ? source.external : []).filter(
                (item) => !isSpecialOrContactHref(item?.href),
            ),
        };
    });
    const [suggestedInternalLinks, setSuggestedInternalLinks] = useState(() =>
        filterSuggestedInternalLinks(
            initialSeo?.suggested_internal_links ?? [],
            initialSeo?.extracted_links?.internal ?? [],
            initialSeo?.extracted_links?.external ?? [],
        ),
    );
    const [suggestedExternalLinks, setSuggestedExternalLinks] = useState(() =>
        filterSuggestedInternalLinks(
            initialSeo?.suggested_external_links ?? [],
            initialSeo?.extracted_links?.internal ?? [],
            initialSeo?.extracted_links?.external ?? [],
        ),
    );
    const domainLinkCatalogRef = useRef(
        Array.isArray(initialSeo?.domain_link_list_catalog) ? initialSeo.domain_link_list_catalog : [],
    );
    const suggestionKeywordCatalogRef = useRef(
        Array.isArray(initialSeo?.suggested_internal_links_catalog)
            ? initialSeo.suggested_internal_links_catalog
            : [],
    );
    const suggestionExternalCatalogRef = useRef(
        mergeSuggestionCatalog(
            initialSeo?.suggested_external_links_catalog ?? [],
            initialSeo?.suggested_external_links ?? [],
        ),
    );

    return { analysis, articleType, domainLinkCatalogRef, extractedLinks, focusKeyword, hasHydratedSeoFromServerRef, lastSeoAnalysisRef, mediaHealthTick, savedSeoScore, scoringMessages, seoDomain, seoMetaRef, seoScoreSource, seoScoringRules, setArticleType, setExtractedLinks, setMediaHealthTick, setSavedSeoScore, setSeoScoreSource, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomain, siteDomainRef, suggestedExternalLinks, suggestedInternalLinks, suggestionExternalCatalogRef, suggestionKeywordCatalogRef, wikiTrustDomains };
}
