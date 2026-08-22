import { buildSeoAnalysisPayload } from '@seo-addon/utils/seoAnalyzer.js';
import { composeImmediateArticleAnalysis } from '@seo-addon/utils/composeArticleAnalysis.js';
import { documentJsonFromEditorsOrBlocks, htmlFromEditorsOrBlocks } from '../utils/editorDocumentBridge';
import { createCurrentDraftAnalysisSnapshot } from '../utils/currentDraftAnalysisSnapshot';
import { filterSuggestedInternalLinks, isSpecialOrContactHref } from '../utils/articleLinkSuggestionFilter';
import { flattenClientOutlineNodes } from '../utils/articleEditorClientOutline';
import { getAnalysisPolicy, getExternalFacts } from '@seo-addon/utils/articleAnalysisOwnership.js';
import { openPanel } from '../editor/runtime/editorRuntimeNavigation';
import { sanitizeViolations, scoreFromViolations } from '@seo-addon/utils/seoScoreCalculator.js';
import { isCompletedSeoAnalysis } from '../utils/seoAnalysisReadiness';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';

/**
 * useArticleEditorSeoAnalysis - local SEO analysis for Edit Article.
 * Empty violations must never imply READY/100 until a real analysis completed.
 */
export default function useArticleEditorSeoAnalysis({ articleId, articleTitle, articleType, blockEditorsRef, blockFlushRef, blocksRef, canGenerateFaq, clientOutline, editorSettings, faqCount = 0, faqsCanonicalKnownRef, focusKeyword, getExportHtml, lastSeoAnalysisRef, panelFaqsRef, pendingFaqGenerateRef, publishExtractedLinks, requestAnalyzeRef, scoringMessages, seoDomain, seoMetaRef, seoScoringRules, setAnalyzing, setExtractedLinks, setFeaturedSnippetPreviewHtml, setFeaturedSnippetPromptContext, setFeaturedSnippetPromptOpen, setSeoAnalyzeError, setSeoScoreSource, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomain, siteDomainRef, tempMergeRef, wikiTrustDomains }) {
    const [seoStale, setSeoStale] = useState(false);
    const [seoStaleRevision, setSeoStaleRevision] = useState(1);
    const [seoAnalysisReady, setSeoAnalysisReady] = useState(false);
    const seoAnalysisReadyRef = useRef(false);
    const analyzedBlocksRef = useRef(null);

    useEffect(() => {
        seoAnalysisReadyRef.current = seoAnalysisReady;
    }, [seoAnalysisReady]);

    const applySeoAnalysisResult = useCallback((result, source = 'live') => {
        if (!result || typeof result !== 'object') {
            setAnalyzing(false);
            return;
        }

        // Realtime editor owns draft scoring. Async PHP/persisted patches must not
        // overwrite a newer FAQ-aware live analysis (score-one-save-behind / faq_missing flicker).
        if (
            source !== 'live'
            && lastSeoAnalysisRef.current
            && (lastSeoAnalysisRef.current.analysis_owner === 'react_immediate'
                || Number(lastSeoAnalysisRef.current.updated_at ?? 0) > 0)
        ) {
            setAnalyzing(false);
            return;
        }

        // Incomplete server/async patches (no violations key) must not wipe last-stable SEO diagnostics.
        if (!Object.prototype.hasOwnProperty.call(result, 'violations') && lastSeoAnalysisRef.current) {
            setAnalyzing(false);
            return;
        }

        const payload = buildSeoAnalysisPayload(result);
        lastSeoAnalysisRef.current = payload;

        const violations = result.violations ?? payload?.violations ?? [];
        const metrics = result.metrics ?? payload?.metrics ?? {};
        const score = Number.isFinite(Number(result.total_score ?? result.score ?? result.seo_score))
            ? Number(result.total_score ?? result.score ?? result.seo_score)
            : scoreFromViolations(
                sanitizeViolations(violations, seoScoringRules, metrics),
                seoScoringRules,
                metrics,
            );

        const nextAnalysis = {
            violations,
            score,
            seo_score: score,
            metrics,
            errors: result.errors ?? [],
            good: result.good ?? [],
            warnings: result.warnings ?? [],
            score_version: result.score_version ?? null,
            content_hash: result.content_hash ?? null,
            calculated_at: result.calculated_at ?? null,
            analysis_owner: result.analysis_owner ?? 'react_immediate',
            updated_at: result.updated_at ?? Date.now(),
        };
        seoDomain.patch({
            analysis: nextAnalysis,
            seoScore: score,
        });
        setSeoScoreSource(source);
        setSeoAnalysisReady(true);
        seoAnalysisReadyRef.current = true;
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
        // editor | panel | none — same ownership idea as resolveFaqsPersistPayload().
        const collectorOpen = typeof window.__seoCollectArticleFaqs === 'function';
        if (collectorOpen) {
            const fromFaqEditor = window.__seoCollectArticleFaqs();
            return Array.isArray(fromFaqEditor) ? fromFaqEditor : [];
        }

        if (faqsCanonicalKnownRef?.current === true) {
            return Array.isArray(panelFaqsRef.current) ? panelFaqsRef.current : [];
        }

        // Unhydrated — allow DocumentModel / [omi_faq] compatibility fallback in analyzer.
        return null;
    }, [faqsCanonicalKnownRef, panelFaqsRef]);

    const runLocalSeoAnalysis = useCallback((options = {}) => {
        if (!tempMergeRef.current) {
            blockFlushRef.current?.();
        }
        const meta = seoMetaRef.current;
        const policy = getAnalysisPolicy() || editorSettings?.analysis_policy || null;
        const startedAt = typeof performance !== 'undefined' ? performance.now() : Date.now();
        const liveDocument = documentJsonFromEditorsOrBlocks(blockEditorsRef.current, blocksRef.current);
        const liveHtml = htmlFromEditorsOrBlocks(blockEditorsRef.current, blocksRef.current)
            || getExportHtml();
        const snapshot = createCurrentDraftAnalysisSnapshot({
            html: liveHtml,
            document: liveDocument,
            blocks: blocksRef.current,
        });
        const result = composeImmediateArticleAnalysis({
            documentHtml: snapshot.html,
            document: snapshot.document,
            documentModel: snapshot.documentModel,
            blocks: blocksRef.current,
            focusKeyword,
            seoTitle: meta.seoTitle || articleTitle,
            metaDescription: meta.metaDescription,
            slug: meta.slug,
            siteDomain,
            faqs: resolveArticleFaqsSnapshot(),
            faqCountHint: Number(faqCount) || 0,
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
        if (options.force === true) {
            result.manual = true;
            result.updated_at = Date.now();
        }

        applySeoAnalysisResult(result, 'live');
        const finishedAt = typeof performance !== 'undefined' ? performance.now() : Date.now();
        const durationMs = Math.max(0, finishedAt - startedAt);
        window.__SEO_EDITOR_LAST_LOCAL_ANALYSIS_MS__ = durationMs;
        if (editorSettings?.perf_debug || import.meta.env?.DEV) {
            console.debug('[EditPerf] seo.local', {
                article_id: Number(articleId) || 0,
                duration_ms: Number(durationMs.toFixed(2)),
                source: result?.metrics?.draft_structure?.source ?? 'current_editor_draft',
                word_count: result?.metrics?.content_length?.current_word_count ?? 0,
            });
        }

        return result;
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
        faqCount,
        focusKeyword,
        getExportHtml,
        resolveArticleFaqsSnapshot,
        scoringMessages,
        seoScoringRules,
        siteDomain,
        wikiTrustDomains,
    ]);

    const requestAnalyze = useCallback(() => {
        setAnalyzing(true);
        setSeoAnalyzeError(null);
        window.requestAnimationFrame(() => {
            try {
                runLocalSeoAnalysis({ force: true });
                analyzedBlocksRef.current = blocksRef.current;
                setSeoStale(false);
            } catch (error) {
                setAnalyzing(false);
                setSeoAnalysisReady(false);
                seoAnalysisReadyRef.current = false;
                setSeoAnalyzeError(error?.message ?? 'seo_analyze_failed');
            }
        });
    }, [runLocalSeoAnalysis]);

    requestAnalyzeRef.current = requestAnalyze;

    const markSeoStale = useCallback(() => {
        // Debounced re-analysis only after a real READY analysis exists.
        if (!seoAnalysisReadyRef.current) {
            return;
        }
        setSeoStale(true);
        setSeoStaleRevision((current) => current + 1);
    }, []);

    const markSeoAnalysisReady = useCallback((ready = true) => {
        const next = ready === true;
        setSeoAnalysisReady(next);
        seoAnalysisReadyRef.current = next;
        if (!next) {
            setSeoStale(false);
        }
    }, []);

    useEffect(() => {
        if (!seoStale || !seoAnalysisReady) {
            return undefined;
        }

        const timer = window.setTimeout(() => {
            try {
                runLocalSeoAnalysis();
                analyzedBlocksRef.current = blocksRef.current;
                setSeoStale(false);
            } catch (error) {
                setAnalyzing(false);
                setSeoAnalyzeError(error?.message ?? 'seo_analyze_failed');
            }
        }, 450);

        return () => window.clearTimeout(timer);
    }, [runLocalSeoAnalysis, seoAnalysisReady, seoStale, seoStaleRevision]);

    useEffect(() => {
        const onMediaSnapshotAnalyze = (event) => {
            const detail = event?.detail ?? {};
            const aid = Number(detail.article_id ?? 0);
            if (aid > 0 && aid !== Number(articleId)) {
                return;
            }
            markSeoStale();
        };
        window.addEventListener('article-editor-media-snapshot-changed', onMediaSnapshotAnalyze);
        return () => {
            window.removeEventListener('article-editor-media-snapshot-changed', onMediaSnapshotAnalyze);
        };
    }, [articleId, markSeoStale]);

    const openFaqModule = useCallback((options = {}) => {
        if (options?.autoGenerate) {
            pendingFaqGenerateRef.current = true;
        }
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

    return {
        analyzedBlocksRef,
        applySeoAnalysisResult,
        createFaqFromShortcode,
        handleSeoViolationAction,
        isCompletedSeoAnalysis,
        markSeoAnalysisReady,
        markSeoStale,
        openFaqModule,
        requestAnalyze,
        resolveArticleFaqsSnapshot,
        runLocalSeoAnalysis,
        seoAnalysisReady,
        seoStale,
        setSeoAnalysisReady,
        setSeoStale,
    };
}
