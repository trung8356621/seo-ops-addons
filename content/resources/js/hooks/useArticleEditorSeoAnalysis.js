import { buildSeoAnalysisPayload } from '@seo-addon/utils/seoAnalyzer.js';
import { composeImmediateArticleAnalysis } from '@seo-addon/utils/composeArticleAnalysis.js';
import { documentJsonFromEditorsOrBlocks } from '../utils/editorDocumentBridge';
import { filterSuggestedInternalLinks, isSpecialOrContactHref } from '../utils/articleLinkSuggestionFilter';
import { flattenClientOutlineNodes } from '../utils/articleEditorClientOutline';
import { getAnalysisPolicy, getExternalFacts } from '@seo-addon/utils/articleAnalysisOwnership.js';
import { openPanel } from '../editor/runtime/editorRuntimeNavigation';
import { previewSeoScoreViaApi } from '../utils/articleEditorApi';
import { sanitizeViolations, scoreFromViolations } from '@seo-addon/utils/seoScoreCalculator.js';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';

/**
 * useArticleEditorSeoAnalysis - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorSeoAnalysis({ articleId, articleTitle, articleType, blockEditorsRef, blockFlushRef, blocksRef, canGenerateFaq, clientOutline, editorSettings, focusKeyword, getExportHtml, lastSeoAnalysisRef, panelFaqsRef, pendingFaqGenerateRef, publishExtractedLinks, requestAnalyzeRef, scoringMessages, seoDomain, seoMetaRef, seoPreviewAbortRef, seoScoringRules, setAnalyzing, setExtractedLinks, setFeaturedSnippetPreviewHtml, setFeaturedSnippetPromptContext, setFeaturedSnippetPromptOpen, setSeoAnalyzeError, setSeoScoreSource, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomain, siteDomainRef, tempMergeRef, utilitySchedulerRef, wikiTrustDomains }) {
    const applySeoAnalysisResult = useCallback((result, source = 'live') => {
        if (!result || typeof result !== 'object') {
            setAnalyzing(false);
            return;
        }

        const payload = buildSeoAnalysisPayload(result);
        lastSeoAnalysisRef.current = payload;

        const violations = result.violations ?? payload?.violations ?? [];
        const score = Number.isFinite(Number(result.total_score ?? result.score ?? result.seo_score))
            ? Number(result.total_score ?? result.score ?? result.seo_score)
            : scoreFromViolations(
                sanitizeViolations(violations, seoScoringRules),
                seoScoringRules,
            );

        const nextAnalysis = {
            violations,
            score,
            seo_score: score,
            errors: result.errors ?? [],
            good: result.good ?? [],
            warnings: result.warnings ?? [],
            score_version: result.score_version ?? null,
            content_hash: result.content_hash ?? null,
            calculated_at: result.calculated_at ?? null,
        };
        seoDomain.patch({
            analysis: nextAnalysis,
            seoScore: score,
        });
        setSeoScoreSource(source);
        setAnalyzing(false);


        if (payload.extracted_links) {
            setSuggestedInternalLinks((prevSuggested) => {
                const filteredSuggested = filterSuggestedInternalLinks(
                    prevSuggested,
                    payload.extracted_links.internal ?? [],
                    payload.extracted_links.external ?? [],
                );
                setSuggestedExternalLinks((prevExternalSuggested) => {
                    const filteredExternalSuggested = filterSuggestedInternalLinks(
                        prevExternalSuggested,
                        payload.extracted_links.internal ?? [],
                        payload.extracted_links.external ?? [],
                    ).filter((item) => {
                        const href = String(item?.href ?? item?.target_url ?? '').trim();

                        return href !== '' && !isSpecialOrContactHref(href);
                    });
                    setExtractedLinks({
                        internal: payload.extracted_links.internal ?? [],
                        external: (payload.extracted_links.external ?? []).filter(
                            (item) => !isSpecialOrContactHref(item?.href),
                        ),
                    });
                    publishExtractedLinks(
                        payload.extracted_links,
                        filteredSuggested,
                        filteredExternalSuggested,
                    );

                    return filteredExternalSuggested;
                });

                return filteredSuggested;
            });
        }
    }, [publishExtractedLinks, seoScoringRules]);

    const resolveArticleFaqsSnapshot = useCallback(() => {
        const fromFaqEditor = window.__seoCollectArticleFaqs?.();
        if (Array.isArray(fromFaqEditor)) {
            return fromFaqEditor;
        }

        return panelFaqsRef.current;
    }, []);

    // Perf Phase 2B: immediate local analysis debounce 250ms (policy-driven).
    // Kh�ng g?i server. Kh�ng remount TipTap.
    const analyzedBlocksRef = useRef(null);
    const [seoStale, setSeoStale] = useState(false);

    const runLocalSeoAnalysis = useCallback(() => {
        if (!tempMergeRef.current) {
            blockFlushRef.current?.();
        }
        const meta = seoMetaRef.current;
        const policy = getAnalysisPolicy() || editorSettings?.analysis_policy || null;
        const result = composeImmediateArticleAnalysis({
            documentHtml: getExportHtml(),
            document: documentJsonFromEditorsOrBlocks(blockEditorsRef.current, blocksRef.current),
            blocks: blocksRef.current,
            focusKeyword,
            seoTitle: meta.seoTitle || articleTitle,
            metaDescription: meta.metaDescription,
            slug: meta.slug,
            siteDomain,
            faqs: resolveArticleFaqsSnapshot(),
            wikiTrustDomains,
            scoringMessages,
            seoScoringRules,
            articleType: articleType,
            articleLengthSettings: {
                article_length_product: policy?.content?.article_length_product
                    ?? editorSettings?.article_length_product,
                article_length_default: policy?.content?.article_length_default
                    ?? editorSettings?.article_length_default,
            },
            featuredSnippetThresholds: policy?.featured_snippet_thresholds
                ?? editorSettings?.featured_snippet_thresholds
                ?? {},
            policy,
            articleId,
            externalFacts: getExternalFacts() || editorSettings?.external_facts || null,
        });

        applySeoAnalysisResult(result);
    }, [
        applySeoAnalysisResult,
        articleId,
        articleTitle,
        articleType,
        editorSettings?.analysis_policy,
        editorSettings?.article_length_default,
        editorSettings?.article_length_product,
        editorSettings?.external_facts,
        editorSettings?.featured_snippet_thresholds,
        focusKeyword,
        getExportHtml,
        resolveArticleFaqsSnapshot,
        scoringMessages,
        seoScoringRules,
        siteDomain,
        wikiTrustDomains,
    ]);

    const runPhpSeoPreview = useCallback(async () => {
        if (!articleId) {
            return;
        }
        if (seoPreviewAbortRef.current) {
            seoPreviewAbortRef.current.abort();
        }
        const controller = new AbortController();
        seoPreviewAbortRef.current = controller;
        const meta = seoMetaRef.current;
        try {
            setAnalyzing(true);
            setSeoAnalyzeError(null);
            const data = await previewSeoScoreViaApi(articleId, {
                title: meta.seoTitle || articleTitle,
                slug: meta.slug,
                meta_description: meta.metaDescription,
                focus_keyword: focusKeyword,
                content: getExportHtml(),
            }, { signal: controller.signal });
            if (controller.signal.aborted) {
                return;
            }
            applySeoAnalysisResult(data, 'live');
            setSeoStale(false);
        } catch (error) {
            if (error?.name === 'AbortError') {
                return;
            }
            // Keep local JS score; surface soft error.
            setSeoAnalyzeError(error?.message ?? 'seo_preview_failed');
            setAnalyzing(false);
        }
    }, [applySeoAnalysisResult, articleId, articleTitle, focusKeyword, getExportHtml]);

    const requestAnalyze = useCallback(() => {
        try {
            setAnalyzing(true);
            setSeoAnalyzeError(null);
            runLocalSeoAnalysis();
            analyzedBlocksRef.current = blocksRef.current;
            setSeoStale(false);
            void runPhpSeoPreview();
        } catch (error) {
            setAnalyzing(false);
            setSeoAnalyzeError(error?.message ?? 'seo_analyze_failed');
        }
    }, [runLocalSeoAnalysis, runPhpSeoPreview]);

    requestAnalyzeRef.current = requestAnalyze;

    const scheduleIdleSeoAnalysis = useCallback(() => {
        const scheduler = utilitySchedulerRef.current;
        if (!scheduler) {
            return;
        }
        setSeoStale(true);
        setSeoAnalyzeError(null);
        scheduler.schedule({
            id: 'seo-idle-analyze',
            debounceMs: 600,
            priority: 'normal',
            run: ({ version, signal }) => {
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                if (blocksRef.current === analyzedBlocksRef.current) {
                    setSeoStale(false);
                    return;
                }
                try {
                    setAnalyzing(true);
                    setSeoAnalyzeError(null);
                    runLocalSeoAnalysis();
                    if (signal.aborted || version !== scheduler.getVersion()) {
                        return;
                    }
                    analyzedBlocksRef.current = blocksRef.current;
                    setSeoStale(false);
                    void runPhpSeoPreview();
                } catch (error) {
                    setAnalyzing(false);
                    setSeoAnalyzeError(error?.message ?? 'seo_analyze_failed');
                }
            },
        });
    }, [runLocalSeoAnalysis, runPhpSeoPreview]);

    useEffect(() => {
        const onMediaSnapshotAnalyze = (event) => {
            const detail = event?.detail ?? {};
            const aid = Number(detail.article_id ?? 0);
            if (aid > 0 && aid !== Number(articleId)) {
                return;
            }
            scheduleIdleSeoAnalysis();
        };
        window.addEventListener('article-editor-media-snapshot-changed', onMediaSnapshotAnalyze);
        return () => {
            window.removeEventListener('article-editor-media-snapshot-changed', onMediaSnapshotAnalyze);
        };
    }, [articleId, scheduleIdleSeoAnalysis]);

    const openFaqModule = useCallback((options = {}) => {
        if (options?.autoGenerate) {
            pendingFaqGenerateRef.current = true;
        }
        // Defer past TipTap activate / shortcode mousedown � tr�nh race mount FAQ l?n d?u.
        window.setTimeout(() => {
            openPanel('faq', {
                source: options?.source ?? 'faq-shortcode',
                autoGenerate: Boolean(options?.autoGenerate),
            });
        }, 0);
    }, []);

    const createFaqFromShortcode = useCallback(() => {
        openFaqModule({ autoGenerate: canGenerateFaq });
        if (canGenerateFaq) {
            window.setTimeout(() => {
                window.dispatchEvent(new CustomEvent('generate-article-faqs'));
            }, 400);
        }
    }, [canGenerateFaq, openFaqModule]);

    const openFeaturedSnippetPrompt = useCallback(() => {
        const outline = flattenClientOutlineNodes(clientOutline ?? [])
            .map((node) => `${'#'.repeat(Math.max(1, Number(node.level) || 2))} ${String(node.heading_text ?? '').trim()}`)
            .filter((line) => line.replace(/#/g, '').trim() !== '')
            .join('\n');
        const sectionContent = String(getExportHtml?.() ?? '').slice(0, 8000);
        setFeaturedSnippetPreviewHtml('');
        setFeaturedSnippetPromptContext({
            title: articleTitle,
            focusKeyword,
            outline,
            sectionContent,
            language: window.__SEO_I18N_LOCALE__ ?? 'vi',
            domain: siteDomainRef.current,
        });
        setFeaturedSnippetPromptOpen(true);
    }, [articleTitle, clientOutline, focusKeyword, getExportHtml]);

    const handleSeoViolationAction = useCallback((action) => {
        if (!action?.action) {
            return;
        }
        if (action.action === 'open-faq-generator') {
            openFaqModule({ autoGenerate: canGenerateFaq });
            if (canGenerateFaq) {
                window.setTimeout(() => {
                    window.dispatchEvent(new CustomEvent('generate-article-faqs'));
                }, 500);
            }
            return;
        }
        if (action.action === 'open-featured-snippet-prompt') {
            openFeaturedSnippetPrompt();
        }
    }, [canGenerateFaq, openFaqModule, openFeaturedSnippetPrompt]);

    return { analyzedBlocksRef, applySeoAnalysisResult, createFaqFromShortcode, handleSeoViolationAction, openFaqModule, requestAnalyze, resolveArticleFaqsSnapshot, runLocalSeoAnalysis, scheduleIdleSeoAnalysis, seoStale, setSeoStale };
}
