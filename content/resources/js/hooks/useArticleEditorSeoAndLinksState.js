import { DEFAULT_WIKI_TRUST_DOMAINS } from '@seo-addon/utils/wikiTrustDomains.js';
import { LINKS_RESCAN_REQUEST_EVENT, isAbortError } from '../utils/articleEditorModules';
import { filterSuggestedInternalLinks, isSpecialOrContactHref, mergeSuggestionCatalog } from '../utils/articleLinkSuggestionFilter';
import { normalizeSeoSummary } from '../utils/articleEditorPayloadAdapters';
import { seoActions, seoApi } from '@seo-addon/editor/domains/seo/state.js';
import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import { t } from '../utils/i18n';
import { useEffect, useRef, useState } from 'react';
import { useSeoEditor } from '@seo-addon/editor/domains/seo/useSeoEditor.js';

/**
 * useArticleEditorSeoAndLinksState - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorSeoAndLinksState({ activeHeavyModuleRef, articleId, articleTitle, editorSettings, initialPostType, initialSeo, seoPanelActive, seoSummaryAbortRef, seoSummaryLoadedRef, setSeoSummaryError, setSeoSummaryLoading }) {
    const [siteDomain] = useState(() => String(initialSeo?.site_domain ?? '').trim());
    const [articleType, setArticleType] = useState(
        () => String(initialSeo?.article_type ?? initialPostType ?? 'post').trim(),
    );
    const wikiTrustDomains = Array.isArray(editorSettings?.wiki_trust_domains)
        ? editorSettings.wiki_trust_domains
        : DEFAULT_WIKI_TRUST_DOMAINS;
    const scoringMessages =
        editorSettings?.seo_rule_messages && typeof editorSettings.seo_rule_messages === 'object'
            ? editorSettings.seo_rule_messages
            : editorSettings?.seo_scoring_messages && typeof editorSettings.seo_scoring_messages === 'object'
              ? editorSettings.seo_scoring_messages
              : {};
    const seoScoringRules = Array.isArray(editorSettings?.seo_scoring_rules)
        ? editorSettings.seo_scoring_rules
        : Array.isArray(initialSeo?.seo_scoring_rules)
          ? initialSeo.seo_scoring_rules
          : [];
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
    const seoPreviewAbortRef = useRef(null);
    const [mediaHealthTick, setMediaHealthTick] = useState(0);

    useEffect(() => {
        seoApi.adopt(initialSeo?.analysis ?? null, initialSeo?.focus_keyword ?? '');
        seoActions.markClean();
    }, []);

    useEffect(() => {
        const onSeoSummary = (event) => {
            const detail = event?.detail ?? {};
            if (!detail || typeof detail !== 'object') {
                return;
            }
            const summary = normalizeSeoSummary(detail);
            seoSummaryLoadedRef.current = true;
            setSeoSummaryError(null);
            setSeoSummaryLoading(false);
            /** @type {Partial<{ focusKeyword: string, analysis: object, seoScore: number|null }>} */
            const seoPatch = {
                analysis: {
                    score: summary.score,
                    violations: summary.violations,
                },
                seoScore: summary.score == null || !Number.isFinite(Number(summary.score))
                    ? null
                    : Number(summary.score),
            };
            if (summary.focusKeyword != null) {
                seoPatch.focusKeyword = String(summary.focusKeyword);
            }
            seoDomain.patch(seoPatch);
            if (summary.seoTitle || summary.metaDescription || summary.articleSlug) {
                seoMetaRef.current = {
                    ...seoMetaRef.current,
                    seoTitle: summary.seoTitle || seoMetaRef.current.seoTitle,
                    metaDescription: summary.metaDescription || seoMetaRef.current.metaDescription,
                    slug: summary.articleSlug || seoMetaRef.current.slug,
                };
            }
            if (summary.siteDomain) {
                siteDomainRef.current = summary.siteDomain;
                // Domain arrived after first scan � republish classification for Links panel.
                window.dispatchEvent(new CustomEvent(LINKS_RESCAN_REQUEST_EVENT));
            }
        };
        window.addEventListener('seo-editor-seo-summary-loaded', onSeoSummary);
        return () => window.removeEventListener('seo-editor-seo-summary-loaded', onSeoSummary);
    }, []);

    // SEO Assistant: fetch summary when panel active if not yet loaded; always terminate loading.
    useEffect(() => {
        if (!seoPanelActive || !articleId) {
            seoSummaryAbortRef.current?.abort();
            setSeoSummaryLoading(false);
            return undefined;
        }

        if (seoSummaryLoadedRef.current || analysis != null) {
            setSeoSummaryLoading(false);
            return undefined;
        }

        const controller = new AbortController();
        seoSummaryAbortRef.current = controller;
        setSeoSummaryLoading(true);
        setSeoSummaryError(null);

        void (async () => {
            let settled = false;
            try {
                const url =
                    window.__SEO_EDITOR_LAZY_ENDPOINTS__?.seoSummary
                    || `/api/seo/articles/${articleId}/editor/seo-summary`;
                const settingsUrl =
                    window.__SEO_EDITOR_LAZY_ENDPOINTS__?.settings
                    || `/api/seo/articles/${articleId}/editor/settings`;
                const [seoRes, settingsRes] = await Promise.all([
                    seoArticleApiFetch(url, { signal: controller.signal }),
                    seoArticleApiFetch(settingsUrl, { signal: controller.signal }),
                ]);
                if (controller.signal.aborted || activeHeavyModuleRef.current !== 'seo') {
                    return;
                }
                if (settingsRes.response.ok && settingsRes.data?.success !== false) {
                    const settingsData = settingsRes.data?.data ?? {};

                }
                if (!seoRes.response.ok || seoRes.data?.success === false) {
                    settled = true;
                    setSeoSummaryError(t('editor_seo_load_error'));
                    return;
                }
                const summary = normalizeSeoSummary(seoRes.data);
                settled = true;
                seoSummaryLoadedRef.current = true;
                window.dispatchEvent(
                    new CustomEvent('seo-editor-seo-summary-loaded', { detail: summary.raw }),
                );
            } catch (error) {
                if (isAbortError(error) || controller.signal.aborted) {
                    return;
                }
                if (activeHeavyModuleRef.current === 'seo') {
                    settled = true;
                    setSeoSummaryError(t('editor_seo_load_error'));
                }
            } finally {
                if (!controller.signal.aborted && activeHeavyModuleRef.current === 'seo') {
                    setSeoSummaryLoading(false);
                    if (!settled && !seoSummaryLoadedRef.current) {
                        setSeoSummaryError(t('editor_seo_load_error'));
                    }
                }
            }
        })();

        return () => {
            controller.abort();
            if (seoSummaryAbortRef.current === controller) {
                seoSummaryAbortRef.current = null;
            }
        };
    }, [seoPanelActive, articleId, analysis]);

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
    const siteDomainRef = useRef(String(initialSeo?.site_domain ?? '').trim());

    return { analysis, articleType, domainLinkCatalogRef, extractedLinks, focusKeyword, hasHydratedSeoFromServerRef, lastSeoAnalysisRef, mediaHealthTick, savedSeoScore, scoringMessages, seoDomain, seoMetaRef, seoPreviewAbortRef, seoScoreSource, seoScoringRules, setArticleType, setExtractedLinks, setMediaHealthTick, setSavedSeoScore, setSeoScoreSource, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomain, siteDomainRef, suggestedExternalLinks, suggestedInternalLinks, suggestionExternalCatalogRef, suggestionKeywordCatalogRef, wikiTrustDomains };
}
