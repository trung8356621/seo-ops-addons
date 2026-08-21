import React, { lazy, Suspense, useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import BlockFormatToolbar from './BlockFormatToolbar';
import { BlockInsertBar, BlockInsertMenuBar } from './BlockInsertMenu';
import BlockEditorResizeHandle, { useBlockEditorHeight } from './BlockEditorResizeHandle';
import { EditorInspectorBubbleHost } from '../editor/host/EditorInspectorBubbleHost';
import ArticleHtmlInspectorModal from './ArticleHtmlInspectorModal';
import { resolveLinkEditorAnchorRect } from '../utils/linkEditorAnchor';
import { normalizeInlineLinks, analyzeInlineLinks } from '../utils/inlineLinkNormalizer';
import ImageBlockEditor from '@media-addon/components/ImageBlockEditor.jsx';
import {
    countMatchingAnchorsInHtml,
    countPlainTextInHtml,
    extractLinksFromBlocks,
    findBlockIdForExportOffset,
    removeMatchingAnchorsFromHtml,
    scrollToFaqByIndex,
    scrollToFaqKeyword,
    scrollToKeywordAnchor,
    scrollToPlainTextInBlock,
} from '../utils/articleLinkScroll';
import { scanExistingLinksCompat } from '../utils/existingLinkScanner';
const FeaturedSnippetPromptModal = lazy(() => import('@seo-addon/components/FeaturedSnippetPromptModal.jsx'));
import {
    wrapPlainTextWithLinkInBlocks,
    replaceFirstPlainTextWithLink,
    replaceFirstPlainTextWithText,
} from '../utils/articleLinkInsert';
import { findPlainTextRangeInRoot } from '../utils/articlePlainTextRange';
import { isCtaPlainTextType } from '../utils/ctaLinkFormat';
import {
    captureEditorInsertionContext,
    getEditorInsertionContext,
    clearFrozenEditorInsertionContext,
    freezeEditorInsertionContext,
    getFrozenEditorInsertionContext,
    getInsertionContextForCommand,
    isAssistantFocusStealTarget,
    resolveEditorForInsertion,
    scrollElementIntoViewIfNeeded,
    syncAndFreezeInsertionContext,
    syncInsertionContextFromLiveEditors,
} from '../utils/editorInsertionContext';
import {
    bindEditorCommandHost,
    executeEditorCommand,
    unbindEditorCommandHost,
} from '../utils/editorCommands';
import { withinSectionMoveAvailability } from '../utils/articleEditorBlockReorder';

import { collectContentImagesFromArticle } from '@media-addon/utils/contentImageCounter.js';
import {
    buildUnifiedArticleImagesInventory,
    rowRequiresLocalSlugFix,
    unifiedInventorySlugFixCandidates,
    unifiedInventoryToImageRows,
} from '@media-addon/utils/unifiedArticleImagesInventory.js';
import { normalizeOrphanQuoteCharacters } from '../utils/orphanQuoteNormalizer';
import {
    TIPTAP_HTML_PARSE_OPTIONS,
    hasInlineWhitespaceCorruption,
    plainTextFromHtmlLoose,
    INLINE_WHITESPACE_CORRUPTION_CODE,
    countGluedInlineMarkBoundaries,
    repairGluedInlineMarkBoundaryWhitespaceWithReport,
} from '../utils/inlineWhitespaceGuard';
import { SEO_EDITOR_LINK_CLASS } from '../utils/articleEditorTransientMarkup';
import {
    filterSuggestedInternalLinks,
    isInternalHrefForSite,
    isSpecialOrContactHref,
    mergeSuggestionCatalog,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';
import { articleShortcutActionFromEvent } from '../utils/articleEditorShortcuts';
import ArticleAssistantWidget from '@ai-prompt-addon/components/ArticleAssistantWidget.jsx';
import ArticleGoogleSerpPreview from '@seo-addon/components/ArticleGoogleSerpPreview.jsx';
import ArticleOutlineTab from './ArticleOutlineTab';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { fetchWordPressProductReviews } from '../utils/articleEditorApi';
import { csrfToken, seoArticleApiHeaders, seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import {
    buildSeoAnalysisPayload,
} from '@seo-addon/utils/seoAnalyzer.js';
import { composeImmediateArticleAnalysis } from '@seo-addon/utils/composeArticleAnalysis.js';
import { getAnalysisPolicy, getExternalFacts } from '@seo-addon/utils/articleAnalysisOwnership.js';
import { documentJsonFromEditorsOrBlocks } from '../utils/editorDocumentBridge';
import { sanitizeViolations, scoreFromViolations, buildFailedViolationItems } from '@seo-addon/utils/seoScoreCalculator.js';
import { isCompletedSeoAnalysis } from '../utils/seoAnalysisReadiness';
import { loadFeaturedImage } from '@media-addon/utils/articleFeaturedImageStorage.js';
import {
    isAbortError,
    isEditorHostedModule,
    LINKS_RESCAN_REQUEST_EVENT,
    normalizeHeavyModuleId,
} from '../utils/articleEditorModules';
import { normalizeSeoSummary, readCoreBootstrap } from '../utils/articleEditorPayloadAdapters';
import { t } from '../utils/i18n';
import { EditorHostApiProvider } from '../editor/host/EditorHostApiContext';
import { EditorSidebarNavigation } from '../editor/host/EditorSidebarNavigation';
import { EditorSidebarPortalHost } from '../editor/host/EditorSidebarPortalHost';
import { LazySharedMediaPicker } from '@media-addon/editor/host/LazySharedMediaPicker.jsx';
import { installEditorShellCompatibilityBridge } from '../editor/runtime/editorShellCompatibilityBridge';
import { installMediaPickerCompatibilityBridge } from '../editor/runtime/mediaPickerCompatibilityBridge';
import {
    getActivePanel,
    openPanel,
    subscribeEditorNavigation,
} from '../editor/runtime/editorRuntimeNavigation';
import { publishPartialRuntimeWidgetHealth } from '../editor/runtime/composeRuntimeWidgetHealth';
import {
    bindDiagnosticsArticleScope,
    getDiagnosticsGeneration,
    installRuntimeHealthBadgeBridge,
    patchRuntimeWidgetHealth,
} from '../editor/runtime/editorRuntimeHealthStore';
import { buildPublishingWidgetHealth } from '@ai-prompt-addon/utils/assistantWidgetHealth.js';
import { resolvePublishingCategoryTaxonomy } from '../utils/publishingTaxonomyResolver';
import { SHELL_BOUNDARY_NAV_ITEMS } from '../editor/runtime/editorShellNavItems';
import {
    discardLegacyMediaLocalStorage,
    featuredFromSnapshot,
    fetchMediaSnapshot,
    galleryFromSnapshot,
    refreshMediaSnapshotIfStale,
    subscribeMediaSnapshot,
} from '../utils/articleEditorMediaSnapshot';
import { DEFAULT_WIKI_TRUST_DOMAINS } from '@seo-addon/utils/wikiTrustDomains.js';
import { setArticleAutosaveLock, isArticleAutosaveLocked } from '../utils/articleAutosaveLock';
import {
    appendProductAlbumItems,
    syncProductAlbumToServer,
    loadProductAlbum,
    normalizeProductAlbumList,
    removeProductAlbumItem,
} from '@media-addon/utils/articleProductAlbumStorage.js';
import { clearFeaturedImageStorage, saveFeaturedImage } from '@media-addon/utils/articleFeaturedImageStorage.js';
import { clearArticleMediaPickerCache } from '@media-addon/utils/articleMediaPickerCache.js';
const GenerateImageModal = lazy(() => import('@media-addon/components/GenerateImageModal.jsx'));
import EditorBusyOverlay from './EditorBusyOverlay';
import {
    applyImagePatchToBlocks,
    applyQuickFixAltTitleToBlock,
    applyQuickFixAltTitleToBlocks,
    applyQuickFixSlugToBlock,
    applyQuickFixSlugToBlocks,
    applyRenameMapToFeaturedImageStorage,
    assignInArticleQuickFixIndices,
    buildAltTitleMetaUpdatePayload,
    buildExactRenameUrlMap,
    buildMergedEditorImagesForPicker,
    buildQuickFixIndexByBlockId,
    collectImagesFromBlocks,
    filterSupplementalDuplicatesOfBlockRows,
    computeQuickFixAltTitleSupplementalOutcome,
    computeQuickFixSlugSupplementalOutcome,
    executeSeoMediaSlugRenamesTwoPhase,
    ensureLocalRenameResultsCoverQueue,
    ensureWpRenameResultsCoverQueue,
    enrichWpRenamedWithRequestMeta,
    enrichBlocksWithPostImages,
    finalizeBlocksAfterWpRename,
    buildLocalSlugRenameErrorNotify,
    mapArticleSlugFixReplacementsToLocalResults,
    omitFailedLocalSlugRenameQueueItems,
    reconcileSupplementalImagesWithBlocks,
    resetSupplementalImagesAfterSlugRename,
    resolveWpRenameOldUrl,
    resolveImageRefIds,
    resolveArticleImageRemoveTarget,
    articleImageRowsShareIdentity,
    shouldRenameSlugOnWordPress,
    syncProductAlbumUrlsFromBlockImages,
    slugFromUrl,
} from '@media-addon/utils/articleImagesUtils.js';
import {
    isBulkSlugRenameSafeMedia,
    isWordPressProtectedMedia,
} from '@media-addon/utils/mediaSourceClassification.js';
import {
    confirmSlugRename,
} from '@media-addon/utils/imageSlugRenameConfirm.js';
import { dispatchWordPressAttachmentMetaUpdate } from '@media-addon/utils/imageAttachmentMetaUpdate.js';
import {
    AI_PLACEHOLDER_LOADING_URL,
    createClipboardPasteHandler,
    fetchArticleAiMediaJobs,
    fetchSeoMediaStatus,
    renameSeoMedia,
    renameSeoMediaByUrl,
    fixArticleMediaSlugs,
    updateSeoMediaMeta,
} from '@media-addon/utils/seoMediaApi.js';
import {
    showArticleOperationOverlay,
} from '../utils/articleOperationTracker';
import { getDefaultArticleEditorRuntime } from '../editor/runtime/defaultArticleEditorRuntime';
import { registerDomainSaveOwners, unregisterDomainSaveOwners } from '../editor/domains/registerDomainSaveOwners.js';
import { contentActions, getContentState } from '../editor/domains/content/state.js';
import { useContentSelector } from '../editor/domains/content/useContentEditor.js';
import { useSeoEditor } from '@seo-addon/editor/domains/seo/useSeoEditor.js';
import { seoActions, seoApi } from '@seo-addon/editor/domains/seo/state.js';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { useMediaEditor } from '@media-addon/editor/domains/media/useMediaEditor.js';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';
import { useArticleEditorHistory } from '../hooks/useArticleEditorHistory';
import { useArticleEditorNetworkConnectivity } from '../hooks/useArticleEditorNetworkConnectivity';
import { isArticleEditorNetworkError } from '../utils/articleEditorNetwork';
import {
    clearDraft,
    draftOffersManualChoice,
    hashContent,
    isDraftPersistenceEnabled,
    loadDraft,
    resolveLocalDraftDecision,
    saveDraft,
    setDraftPersistenceEnabled,
    writeSyncedLocalSnapshot,
} from '../utils/articleEditorStorage';
import {
    saveArticleViaApiSingleFlight,
    saveCurrentArticleFromEditor,
    shouldSuppressServerAutosave,
    cancelPendingServerAutosave,
} from '../utils/articleEditorSaveQueue';
import {
    buildArticleEditorApiPayload,
    getEditorConflictTokens,
    setEditorConflictTokens,
    applyEditorDocumentAck,
    logArticleEditorVersionDebug,
} from '../utils/articleEditorApi';
import { buildEditorDocumentEnvelope, blocksFromEditorDocumentEnvelope, isUsableTipTapDocument } from '../utils/articleEditorDocument';
import {
    assertWritableEditorSession,
    canMutateEditor,
    getArticleEditorSessionState,
    runEditorMutation,
} from '../utils/editorSessionState';
import {
    ARTICLE_EDITOR_DRAFT_ALERT_EVENT,
    ARTICLE_EDITOR_OPEN_DRAFT_CHOICE_EVENT,
} from '../utils/articleEditorStickyHeader';
import {
    buildClientOutlineTree,
    extractOutlineHeadingFromBlock,
    flattenClientOutlineNodes,
    normalizeOutlineHeadingText,
    outlineHeadingFingerprint,
} from '../utils/articleEditorClientOutline';
import { createArticleEditorUtilityScheduler } from '../utils/articleEditorUtilityScheduler';
import { countWordsFromHtmlLight } from '../utils/articleEditorMetrics';
import {
    htmlToPlainText,
    isMeaningfulHtml,
    isWordPressImageElement,
    normalizeBlocks,
    parseImageFromBlockContent,
    parseFeaturedSnippetNewSectionBlocks,
    renderImageFigure,
    splitHtmlIntoTextAndImageChunks,
    withDefaultImageInsertAlign,
} from '@media-addon/utils/blockImageUtils.js';
import {
    cleanBlockHtmlForEditorDisplay,
    ensureTiptapHeadingCursorParagraph,
    FAQ_SHORTCODE_HTML,
    flattenHtmlBodyNodes,
    isFaqPlaceholderHtml,
    leadingHeadingLevel,
    normalizeSectionHeadingBlockHtml,
    persistBlockHtmlFromEditor,
    resetTipTapEditorHistory,
} from '../utils/editorHtmlUtils';
import { resolveArticleImageSrc, resolveFullWordPressImageUrl, isLocalSeoMediaSrc, supportsWordPressImageSizes } from '@wordpress-addon/utils/wordpressImageUrl.js';
import { applyWordPressImageSize } from '@wordpress-addon/utils/wordpressImageSize.js';
import {
    SEO_EDITOR_LINK_MARK_CLASS,
    SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS,
    stripEditorTransientMarkup,
} from '../utils/articleEditorTransientMarkup';
import FaqAccordionPreview from './FaqAccordionPreview';
import { Undo2, Redo2, Plus, ChevronDown, ChevronRight, ImageIcon, Table, Link2, AlertTriangle, Search, ListPlus, Sparkles, ListCollapse, Trash2, BarChart3, Star } from 'lucide-react';
import {
    getSelectionHtmlFromEditor,
    getSelectionTextFromEditor,
} from '../utils/editorSelectionUtils';
import useArticleEditorSeoAndLinksState from '../hooks/useArticleEditorSeoAndLinksState';
import useArticleEditorSeoAnalysis from '../hooks/useArticleEditorSeoAnalysis';
import useArticleEditorCoreState from '../hooks/useArticleEditorCoreState';
import useArticleEditorSessionNetwork from '../hooks/useArticleEditorSessionNetwork';
import useArticleEditorSaveQueue from '../hooks/useArticleEditorSaveQueue';
import useArticleEditorBootstrap from '../hooks/useArticleEditorBootstrap';
import useArticleEditorBlockContentCommands from '../hooks/useArticleEditorBlockContentCommands';
import useArticleEditorImageSlugRename from '../hooks/useArticleEditorImageSlugRename';
import useArticleEditorLinksAndSnippets from '../hooks/useArticleEditorLinksAndSnippets';
import useArticleEditorImageLifecycle from '../hooks/useArticleEditorImageLifecycle';
import useArticleEditorBlockActivation from '../hooks/useArticleEditorBlockActivation';
import useArticleEditorImageGeneration from '../hooks/useArticleEditorImageGeneration';
import useArticleEditorInsertAndSections from '../hooks/useArticleEditorInsertAndSections';
import useArticleEditorExternalEventsBridge from '../hooks/useArticleEditorExternalEventsBridge';
import useArticleEditorOutline from '../hooks/useArticleEditorOutline';
import useArticleEditorSearch from '../hooks/useArticleEditorSearch';

import {
    newBlockId,
    createEmptyTextBlock,
    createEmptyImageBlock,
    createFaqShortcodeBlock,
    createEmptySectionBlock,
    articleHasFaqShortcode,
    stripLeadingH1FromHtml,
    requiresClassicInlineRegroup,
    extractSectionHeading,
    truncateOutlineHeadingText,
    extractOutlineApiErrorMessage,
    outlineApiCsrfToken,
    outlineApiRequest,
    flattenOutlineNodes,
    findBlockIdForOutlineHeading,
    flattenOutlineHeadingKeys,
    outlineHeadingKey,
    isSectionHeadingBlock,
    sectionHasOnlyEmptyHeadingBody,
    INTRO_SECTION_ID,
    buildEditorSections,
    introSectionHasImageBlock,
    countKeywordInSectionBlocks,
    buildSectionStats,
    countWordsFromText,
    countWordsFromHtml,
    countImagesFromHtml,
    normalizeImageSrcKey,
    hasBlockH2,
    resolveDistributeImageSrc,
    buildDistributedImage,
    distributeProductImagesToEmptySections,
    buildGallerySupplementalRows,
    resolveSupplementalImagesWithGallery,
    escapeRegExp,
    replaceTextInHtmlContent,
    parseVideoMediaFromHtml,
    parseHtmlToBlocks,
    hoistInlineImagesFromTextBlocks,
    splitHtmlAtH2Sections,
    regroupParsedBlocksByH2,
    hasMeaningfulExportHtml,
    exportBlocksToHtml,
    getBlocksInRange,
    mergeBlockHtmlContents,
    getPlainTextFromBlocks,
    extractHeadingScopedPlainText,
    getActiveBlockContextText,
    getHtmlFromBlocks,
    dispatchActiveBlockContext,
    isSameTiptapBlockContent,
} from '../utils/contentDocumentHelpers';
import SectionHeaderTitle from './SectionHeaderTitle';
import BlockEditor from './BlockEditor';
import ArticleContentSyncRequiredBlocker from './ArticleContentSyncRequiredBlocker';
import {
    CONTENT_LIFECYCLE,
    emitContentLifecycle,
    isContentSyncRequired,
    normalizeContentLifecyclePayload,
} from '../utils/articleEditorContentLifecycle';
export default function SeoArticleEditor({
    articleId,
    siteId = null,
    initialHtml,
    initialEditorDocument = null,
    initialEditorDocumentHash = null,
    initialSeo,
    initialPostImages = [],
    initialSupplementalImages = [],
    initialPostType = '',
    contentRevision = '',
    connectionHash = '',
    expectedUpdatedAt = '',
    expectedContentHash = '',
    documentVersion = 1,
    sessionReadOnly = false,
    contentLifecycle: contentLifecycleProp = null,
    supportsProductGallery: supportsProductGalleryProp = false,
    isCanaryProduct: isCanaryProductProp = false,
    parentChildAllowed: parentChildAllowedProp = false,
    parentChildReason: parentChildReasonProp = '',
    productCategoryOptions = [],
    initialProductGallery = [],
    initialFaqs = undefined,
    initialVirtualReviews = [],
    articleTitle = '',
    editorSettings = {},
    mediaPickerUrl = '',
    initialLoaiSanPham = '',
    initialGalleryDescription = '',
    perfDebug = false,
}) {
    const [supportsProductGallery, setSupportsProductGallery] = useState(() => Boolean(supportsProductGalleryProp));
    const contentLifecycle = normalizeContentLifecyclePayload(
        contentLifecycleProp
        ?? window.__SEO_EDITOR_CONTENT_LIFECYCLE__
        ?? { state: CONTENT_LIFECYCLE.CONTENT_LOADING },
    );
    const syncRequired = isContentSyncRequired(contentLifecycle.state);
    const contentLoading = contentLifecycle.state === CONTENT_LIFECYCLE.CONTENT_LOADING;
    const isCanaryProduct = Boolean(isCanaryProductProp);
    const parentChildAllowed = Boolean(parentChildAllowedProp);
    const parentChildReason = String(parentChildReasonProp ?? '').trim();
    const historyStep = editorSettings?.history_step ?? 20;
    const connectionHashRef = useRef(connectionHash);
    connectionHashRef.current = connectionHash;
    const siteIdRef = useRef(siteId);
    siteIdRef.current = siteId;
    const draftScope = useCallback(() => ({
        siteId: Number(siteIdRef.current ?? 0) || 0,
    }), []);
    const withDraftSite = useCallback((payload = {}) => ({
        ...payload,
        site_id: Number(siteIdRef.current ?? 0) || 0,
    }), []);
    const perfDebugEnabled = Boolean(perfDebug || editorSettings?.perf_debug);
    // Stable bridge — domain-save effect runs before getExportHtml declaration (avoid TDZ).
    const getExportHtmlRef = useRef(() => '');

    useEffect(() => {
        emitContentLifecycle(contentLifecycle);
    }, [
        contentLifecycle.state,
        contentLifecycle.wordpress_linked,
        contentLifecycle.local_content_present,
        contentLifecycle.wp_post_id,
        contentLifecycle.observed_permalink,
        contentLifecycle.allow_fetch_from_wordpress,
    ]);

    useEffect(() => {
        window.__SEO_ARTICLE_MEDIA_PICKER_ENDPOINT__ = mediaPickerUrl;

        return () => {
            delete window.__SEO_ARTICLE_MEDIA_PICKER_ENDPOINT__;
        };
    }, [mediaPickerUrl]);

    useEffect(() => {
        if (!perfDebugEnabled || typeof performance === 'undefined' || typeof performance.mark !== 'function') {
            return;
        }
        performance.mark('seo-article-editor-react-ready');
        try {
            performance.measure(
                'seo-article-editor-mount-to-react-ready',
                'seo-article-editor-mount-start',
                'seo-article-editor-react-ready',
            );
        } catch {
            // Marks có thể thiếu nếu component remount qua livewire:navigated — bỏ qua.
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // CONTENT domain SoT (Task 7): blocks live in the shared content store, not
    // local useState — mirrors legacy `setBlocks` call sites (value or updater fn).
    const blocks = useContentSelector((contentState) => contentState.blocks);
    // Declared immediately — CoreState / callbacks / domain-save close over this (avoid TDZ).
    const blocksRef = useRef(blocks);
    blocksRef.current = blocks;
    const setBlocks = useCallback((updater) => {
        contentActions.replaceBlocks(
            typeof updater === 'function' ? updater(getContentState().blocks) : updater,
        );
    }, []);
    const activeBlockId = useContentSelector((contentState) => contentState.activeBlockId);
    const setActiveBlockId = useCallback((next) => {
        contentActions.setActiveBlockId(
            typeof next === 'function' ? next(getContentState().activeBlockId) : next,
        );
    }, []);
    const tempMerge = useContentSelector((contentState) => contentState.tempMerge);
    const setTempMerge = useCallback((next) => {
        contentActions.setTempMerge(
            typeof next === 'function' ? next(getContentState().tempMerge) : next,
        );
    }, []);
    const globalEditor = useContentSelector((contentState) => contentState.globalEditor);
    const setGlobalEditor = useCallback((next) => {
        contentActions.setGlobalEditor(
            typeof next === 'function' ? next(getContentState().globalEditor) : next,
        );
    }, []);
    const tempMergeRef = useRef(tempMerge);
    tempMergeRef.current = tempMerge;
    const blockFlushRef = useRef(null);
    const activeBlockIdRef = useRef(null);
    const blockEditorsRef = useRef(new Map());
    const globalEditorRef = useRef(null);

    useEffect(() => {
        registerDomainSaveOwners({
            getArticleId: () => Number(articleId) || 0,
            getContentBundle: () => {
                // Prefer heavy collect when it returns a sync object (Promise → fall through).
                const collect = window.__seoCollectEditorHeavyBundle;
                if (typeof collect === 'function') {
                    try {
                        const maybe = collect({
                            renameImagesBeforeWpSync: false,
                            validateLocalImageSlugsBeforeWpSync: false,
                        });
                        if (
                            maybe
                            && typeof maybe === 'object'
                            && typeof maybe.then !== 'function'
                        ) {
                            return maybe;
                        }
                    } catch {
                        // fall through to sync export path
                    }
                }

                const exportHtmlFn = getExportHtmlRef.current;
                const html = typeof exportHtmlFn === 'function'
                    ? String(exportHtmlFn() ?? '')
                    : '';
                const exportBlocks = blocksRef.current;
                const editors = blockEditorsRef.current;
                const editorDocument = Array.isArray(exportBlocks)
                    ? buildEditorDocumentEnvelope(exportBlocks, editors)
                    : null;
                const faqs = typeof window.__seoCollectArticleFaqs === 'function'
                    ? window.__seoCollectArticleFaqs()
                    : null;

                return {
                    html,
                    client_rendered_html: html,
                    editor_document: editorDocument,
                    articleMeta: null,
                    faqs,
                };
            },
        });
        return () => {
            unregisterDomainSaveOwners();
            if (typeof window !== 'undefined') {
                delete window.__seoEditorDomainBridge;
            }
        };
    }, [articleId]);

    const outlineRailRef = useRef(null);
    const [assistantPortalRoots, setAssistantPortalRoots] = useState({
        seo: null,
        image: null,
        reviews: null,
        links: null,
        vocabulary: null,
        faq: null,
        featured: null,
        aiChat: null,
    });
    // Phase 6C.2�6C.4: editor-hosted includes seo/images/reviews/links/faq/featured/ai-chat.
    // Default SEO so right-rail SEO Assistant is not stuck on inactive placeholder.
    const [activeHeavyModule, setActiveHeavyModule] = useState('seo');
    const editorHostActionsRef = useRef({});
    const activeHeavyModuleRef = useRef(null);
    activeHeavyModuleRef.current = activeHeavyModule;
    const imagesAbortRef = useRef(null);
    const reviewsAbortRef = useRef(null);
    const seoSummaryAbortRef = useRef(null);
    const [seoSummaryLoading, setSeoSummaryLoading] = useState(false);
    const [seoSummaryError, setSeoSummaryError] = useState(null);
    const seoSummaryLoadedRef = useRef(false);

    const [sidebarNavRoot, setSidebarNavRoot] = useState(null);
    const [mediaPickerRoot, setMediaPickerRoot] = useState(null);
    const [runtimeContextRevision, setRuntimeContextRevision] = useState(0);

    // Phase 6B/6C.1 — shell bridge + runtime navigation (no dual CustomEvent listeners in host).
    useEffect(() => {
        bindDiagnosticsArticleScope(articleId);
        const uninstallBridge = installEditorShellCompatibilityBridge();
        const uninstallMediaPickerBridge = installMediaPickerCompatibilityBridge();
        const uninstallBadgeBridge = installRuntimeHealthBadgeBridge();
        setSidebarNavRoot(document.getElementById('article-editor-sidebar-navigation-root'));
        setMediaPickerRoot(document.getElementById('article-editor-media-picker-root'));
        discardLegacyMediaLocalStorage(articleId);
        if (Number(articleId) > 0) {
            void refreshMediaSnapshotIfStale(articleId).catch(() => {});
        }
        const unsubNav = subscribeEditorNavigation((panelId) => {
            const normalized = normalizeHeavyModuleId(panelId);
            if (normalized && isEditorHostedModule(normalized)) {
                setActiveHeavyModule(normalized);
                return;
            }
            // External / Alpine-only / closed — unmount editor-hosted heavy body.
            setActiveHeavyModule(null);
        });
        // Align initial chip with runtime default.
        openPanel(getActivePanel() || 'seo', { source: 'host_mount' });

        return () => {
            unsubNav();
            uninstallMediaPickerBridge();
            uninstallBridge();
            uninstallBadgeBridge();
            imagesAbortRef.current?.abort();
            reviewsAbortRef.current?.abort();
        };
    }, [articleId]);

    // Phase 3: fetch images only while Images is the active heavy module; abort on leave.
    useEffect(() => {
        if (activeHeavyModule !== 'images' || !articleId) {
            imagesAbortRef.current?.abort();
            return undefined;
        }

        const controller = new AbortController();
        imagesAbortRef.current = controller;
        let cancelled = false;
        const imagesUrl =
            window.__SEO_EDITOR_LAZY_ENDPOINTS__?.images
            || `/api/seo/articles/${articleId}/editor/images`;
        const metaUrl =
            window.__SEO_EDITOR_LAZY_ENDPOINTS__?.meta
            || `/api/seo/articles/${articleId}/editor/meta`;

        void (async () => {
            try {
                const headers = {
                    Accept: 'application/json',
                    ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
                };
                const [imagesRes, metaRes] = await Promise.all([
                    seoArticleApiFetch(imagesUrl, { headers, signal: controller.signal }),
                    seoArticleApiFetch(metaUrl, { headers, signal: controller.signal }),
                ]);
                if (cancelled || controller.signal.aborted || activeHeavyModuleRef.current !== 'images') {
                    return;
                }
                if (imagesRes.response.ok && imagesRes.data?.success !== false) {
                    const images = Array.isArray(imagesRes.data?.data) ? imagesRes.data.data : [];
                    window.dispatchEvent(
                        new CustomEvent('article-post-images-synced', {
                            detail: { images },
                        }),
                    );
                }
                if (metaRes.response.ok && metaRes.data?.success !== false) {
                    const meta = metaRes.data?.data ?? {};
                    if (Array.isArray(meta.product_gallery) && meta.product_gallery.length > 0) {
                        window.dispatchEvent(
                            new CustomEvent('seo-product-gallery-updated', {
                                detail: {
                                    gallery: meta.product_gallery,
                                    article_id: articleId,
                                },
                            }),
                        );
                    }

                }
            } catch (error) {
                if (isAbortError(error) || controller.signal.aborted) {
                    return;
                }
            }
        })();

        return () => {
            cancelled = true;
            controller.abort();
            if (imagesAbortRef.current === controller) {
                imagesAbortRef.current = null;
            }
        };
    }, [activeHeavyModule, articleId]);

        const { analyzing, canGenerateFaq, canGenerateFeaturedSnippet, canGenerateOutlineHeading, canQuickCreateReviews, clientOutline, collapsedSectionIds, editorSearchMatchCount, faqCount, faqsCanonicalKnownRef, featuredHealthSnapshot, featuredSnippetGenerating, featuredSnippetPreviewHtml, featuredSnippetPromptContext, featuredSnippetPromptOpen, featuredSnippetTargetRef, generateImageModalInitialCustom, generateImageModalOpen, generateImageModalPrompt, generateImageModalTarget, generateImageTargetRef, generateQuickPostReviews, imageRenameBusy, imageRenameBusyCount, imagesReloadKey, imagesTabJumpTarget, insertMenu, isProductPost, outlineAppendDoneRef, outlineAppendInflightRef, outlineFingerprintRef, outlineHasSavedHeadings, outlineHeadingCommand, outlineHeadingIdsByBlockIdRef, outlineHeadingIdsByKeyRef, outlineHeadingKeys, outlineJumpTarget, outlineTreeSync, panelFaqs, panelFaqsRef, pendingFaqGenerateRef, pendingLocalRenameQueueRef, pendingLocalRenameResultsRef, pendingQuickFixKeywordRef, pendingWpRenameRequestRef, postImagesRef, productGalleryItems, publishEditorImagesCatalogRef, quickCreateReviewsConfigUrl, quickFixSlugAllBusy, quickReplaceFind, quickReplaceValue, refreshVirtualReviews, reviewCount, reviewCountLoading, reviewsLoaded, reviewsLoadWarning, reviewsLoading, saveStatus, sectionTitleEditRequest, seoAnalyzeError, seoPanelActive, setAnalyzing, setClientOutline, setCollapsedSectionIds, setEditorSearchMatchCount, setFaqCount, setFeaturedSnippetGenerating, setFeaturedSnippetPreviewHtml, setFeaturedSnippetPromptContext, setFeaturedSnippetPromptOpen, setGenerateImageModalInitialCustom, setGenerateImageModalOpen, setGenerateImageModalPrompt, setGenerateImageModalTarget, setImageRenameBusy, setImageRenameBusyCount, setImagesReloadKey, setImagesTabJumpTarget, setInsertMenu, setOutlineHasSavedHeadings, setOutlineHeadingCommand, setOutlineHeadingKeys, setOutlineJumpTarget, setOutlineTreeSync, setPanelFaqs, setQuickFixSlugAllBusy, setQuickReplaceFind, setQuickReplaceValue, setSaveStatus, setSectionTitleEditRequest, setSeoAnalyzeError, showConfigureReviewsLink, showReviewsTab, slugRenameManagedByBatchRef, supplementalImages, supplementalImagesRef, utilitySchedulerRef, virtualReviews } = useArticleEditorCoreState({ activeHeavyModule, activeHeavyModuleRef, articleId, blocks, blocksRef, editorSettings, initialFaqs, initialPostImages, initialProductGallery, initialSupplementalImages, initialVirtualReviews, perfDebug, reviewsAbortRef, setAssistantPortalRoots, setMediaPickerRoot, supportsProductGallery });

    const parseGalleryItems = useCallback((items) => normalizeProductAlbumList(items), []);

    useEffect(() => {
        const onGalleryUpdated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const gallery = detail.gallery;
            if (!Array.isArray(gallery)) {
                return;
            }

            const items = parseGalleryItems(gallery);
            mediaActions.setGallery(items);
            mediaActions.markDirty();
            mediaActions.setSupplementalImages((prev) =>
                resolveSupplementalImagesWithGallery(prev, items, articleId, supportsProductGallery),
            );

            if (supportsProductGallery && articleId) {
                // Snapshot/server/pending events already own persistence — UI mirror only.
                const skipPersist = Boolean(
                    detail.from_snapshot
                    || detail.from_server
                    || detail.pending,
                );
                if (!skipPersist) {
                    if (items.length === 0) {
                        clearFeaturedImageStorage(articleId);
                    } else {
                        const first = items[0];
                        saveFeaturedImage(articleId, {
                            url: first.url,
                            wpAttachmentId: first.id,
                            seoMediaId: first.id,
                        });
                    }
                }
                mediaActions.markDirty();
            }

            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        };

        window.addEventListener('seo-product-gallery-updated', onGalleryUpdated);

        return () => window.removeEventListener('seo-product-gallery-updated', onGalleryUpdated);
    }, [articleId, parseGalleryItems, supportsProductGallery]);

        const { analysis, articleType, domainLinkCatalogRef, extractedLinks, focusKeyword, hasHydratedSeoFromServerRef, lastSeoAnalysisRef, mediaHealthTick, savedSeoScore, scoringMessages, seoDomain, seoMetaRef, seoScoreSource, seoScoringRules, setArticleType, setExtractedLinks, setMediaHealthTick, setSavedSeoScore, setSeoScoreSource, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomain, siteDomainRef, suggestedExternalLinks, suggestedInternalLinks, suggestionExternalCatalogRef, suggestionKeywordCatalogRef, wikiTrustDomains } = useArticleEditorSeoAndLinksState({ articleTitle, editorSettings, initialPostType, initialSeo, setSeoSummaryError, setSeoSummaryLoading });

    const mainKeyword = useMemo(() => {
        const fromFocus = String(focusKeyword ?? '').trim();
        if (fromFocus) {
            return fromFocus;
        }
        return String(articleTitle ?? '').trim();
    }, [focusKeyword, articleTitle]);

    useEffect(() => {
        // D�ng cho clipboard paste handler (tiptap) v� c�c lu?ng insert ?nh.
        window.__SEO_MAIN_KEYWORD__ = mainKeyword;
        return () => {
            if (window.__SEO_MAIN_KEYWORD__ === mainKeyword) {
                delete window.__SEO_MAIN_KEYWORD__;
            }
        };
    }, [mainKeyword]);

    useEffect(() => {
        const onOpenGenerateImageModal = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const target = String(detail.target ?? 'editor').trim() || 'editor';
            generateImageTargetRef.current = target;
            setGenerateImageModalTarget(target);

            const preset = String(detail.prompt ?? detail.userBrief ?? '').trim();
            if (target === 'product-gallery') {
                const existingCustom = String(detail.loaiSanPhamCustom ?? '').trim() || String(initialLoaiSanPham ?? '').trim();
                setGenerateImageModalInitialCustom(existingCustom || mainKeyword || '');

                const explicitPrompt = String(detail.prompt ?? '').trim();
                const savedDescription = String(initialGalleryDescription ?? '').trim();
                const keyword = String(mainKeyword ?? '').trim();
                const restoreDraft = savedDescription !== '' && savedDescription !== keyword;
                setGenerateImageModalPrompt(explicitPrompt || (restoreDraft ? savedDescription : ''));
            } else {
                setGenerateImageModalInitialCustom('');
                setGenerateImageModalPrompt(preset || '');
            }
            setGenerateImageModalOpen(true);
        };

        window.addEventListener('seo-open-generate-image-modal', onOpenGenerateImageModal);

        return () => {
            window.removeEventListener('seo-open-generate-image-modal', onOpenGenerateImageModal);
        };
    }, [mainKeyword, initialLoaiSanPham, initialGalleryDescription]);

    useEffect(() => {
        setArticleAutosaveLock('generate-image-modal', generateImageModalOpen);

        return () => setArticleAutosaveLock('generate-image-modal', false);
    }, [generateImageModalOpen]);

    useEffect(() => {
        setArticleAutosaveLock('quick-fix-slug-all', quickFixSlugAllBusy);

        return () => setArticleAutosaveLock('quick-fix-slug-all', false);
    }, [quickFixSlugAllBusy]);

    const submitGenerateImageFromModal = useCallback(
        (payload) => {
            const normalized = payload != null && typeof payload === 'object'
                ? payload
                : { userBrief: String(payload ?? '') };
            const userBrief = String(normalized.userBrief ?? '').trim();
            const target = generateImageTargetRef.current || 'editor';
            const galleryBlockId = target === 'product-gallery' ? 'product-gallery' : '';

            window.dispatchEvent(
                new CustomEvent('generate-article-image', {
                    detail: {
                        selectionText: '',
                        selectionHtml: '',
                        userBrief,
                        activeBlockId: galleryBlockId,
                        target,
                        loaiSanPhamCategoryArticleId: Number.parseInt(
                            String(normalized.loaiSanPhamCategoryArticleId ?? 0),
                            10,
                        ) || 0,
                        loaiSanPhamCustom: String(
                            normalized.loaiSanPhamCustom ?? normalized.userBrief ?? '',
                        ).trim(),
                        galleryGenerationMode: String(normalized.galleryGenerationMode ?? 'sprite').trim() || 'sprite',
                    },
                }),
            );
        },
        [],
    );

    const enrichLinksWithOccurrences = useCallback((links) => {
        const source = links && typeof links === 'object' ? links : { internal: [], external: [] };
        const currentBlocks = blocksRef.current;

        const buildKey = (item) =>
            `${String(item?.href ?? '').trim()}\u0000${String(item?.text ?? '').trim()}`;

        const countCache = new Map();
        const withCounts = (items) =>
            (Array.isArray(items) ? items : [])
                .map((item) => {
                    const key = buildKey(item);
                    if (!countCache.has(key)) {
                        let count = 0;
                        for (const block of currentBlocks) {
                            if (block.type === 'image' || !block.content) {
                                continue;
                            }
                            count += countMatchingAnchorsInHtml(
                                block.content,
                                String(item?.text ?? ''),
                                String(item?.href ?? ''),
                            );
                        }
                        countCache.set(key, count);
                    }

                    const occurrenceCount = countCache.get(key) ?? 0;
                    if (occurrenceCount <= 0) {
                        return null;
                    }

                    return {
                        ...item,
                        occurrence_count: occurrenceCount,
                    };
                })
                .filter(Boolean);

        return {
            internal: withCounts(source.internal),
            external: withCounts(source.external).filter((item) => !isSpecialOrContactHref(item?.href)),
        };
    }, []);

    const publishExtractedLinks = useCallback((links, suggestedInternal = suggestedInternalLinks, suggestedExternal = suggestedExternalLinks) => {
        const enrichedLinks = enrichLinksWithOccurrences(links);
        const filteredSuggested = filterSuggestedInternalLinks(
            suggestedInternal,
            enrichedLinks.internal ?? [],
            enrichedLinks.external ?? [],
        );
        const filteredExternalSuggested = filterSuggestedInternalLinks(
            suggestedExternal,
            enrichedLinks.internal ?? [],
            enrichedLinks.external ?? [],
        ).filter((item) => {
            const href = String(item?.href ?? item?.target_url ?? '').trim();

            return href !== '' && !isSpecialOrContactHref(href);
        });
        const articlePlainText = htmlToPlainText(exportBlocksToHtml(blocksRef.current));
        window.dispatchEvent(
            new CustomEvent('seo-editor-links-updated', {
                detail: {
                    source: 'client-document',
                    links: enrichedLinks,
                    suggested_internal: filteredSuggested,
                    suggested_external: filteredExternalSuggested,
                    article_plain_text: articlePlainText,
                    site_domain: siteDomainRef.current,
                    domain_link_list_catalog: domainLinkCatalogRef.current,
                    suggested_internal_links_catalog: suggestionKeywordCatalogRef.current,
                    suggested_external_links_catalog: suggestionExternalCatalogRef.current,
                },
            }),
        );
    }, [suggestedInternalLinks, suggestedExternalLinks, enrichLinksWithOccurrences]);

    const blockById = useMemo(() => {
        const map = new Map();
        for (const block of blocks) {
            map.set(block.id, block);
        }

        return map;
    }, [blocks]);

    const blockIndexMap = useMemo(() => {
        const map = new Map();
        blocks.forEach((block, index) => map.set(block.id, index));

        return map;
    }, [blocks]);

    const editorSections = useMemo(() => buildEditorSections(blocks), [blocks]);

    const sectionByBlockId = useMemo(() => {
        const map = new Map();
        for (const section of editorSections) {
            for (const blockId of section.blockIds) {
                map.set(blockId, section.id);
            }
        }

        return map;
    }, [editorSections]);

    /** Block d?u m?i section (H2 section) � lu�n kh�a TipTap, ch? s?a qua Outline. */
    const sectionHeadingBlockIds = useMemo(() => {
        const ids = new Set();
        for (const section of editorSections) {
            if (section.isIntro || !section.blockIds?.length) {
                continue;
            }
            ids.add(section.blockIds[0]);
        }

        return ids;
    }, [editorSections]);

    const isIntroBlockId = useCallback(
        (blockId) => sectionByBlockId.get(String(blockId ?? '').trim()) === INTRO_SECTION_ID,
        [sectionByBlockId],
    );

    const notifyIntroNoImages = useCallback(() => {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('editor_intro'),
                    body: t('editor_intro_no_images'),
                    status: 'warning',
                },
            }),
        );
    }, []);

    const sectionStats = useMemo(
        () => buildSectionStats(editorSections, blockById),
        [editorSections, blockById],
    );
    const totalWordCount = useMemo(
        () => editorSections.reduce((sum, section) => sum + (sectionStats.get(section.id)?.wordCount ?? 0), 0),
        [editorSections, sectionStats],
    );
    // Content body images only (image blocks + inline <img>). Never featured/gallery/supplemental library.
    const contentImageCensus = useMemo(
        () => collectContentImagesFromArticle(blocks),
        [blocks],
    );
    const unifiedImagesInventory = useMemo(
        () => buildUnifiedArticleImagesInventory({
            contentImages: contentImageCensus.rows,
            featuredImage: featuredHealthSnapshot ?? featuredFromSnapshot(articleId),
            galleryImages: productGalleryItems,
            supplementalImages,
        }),
        [
            contentImageCensus.rows,
            featuredHealthSnapshot,
            articleId,
            productGalleryItems,
            supplementalImages,
        ],
    );
    const unifiedImageRows = useMemo(
        () => unifiedInventoryToImageRows(unifiedImagesInventory),
        [unifiedImagesInventory],
    );
    // Images chip count = unique inventory assets (not content-only, not issue count).
    const imageTabCount = unifiedImageRows.length;

    const structureMutationRef = useRef(null);
    const scheduleAutosaveRef = useRef(() => {});
    const requestAnalyzeRef = useRef(() => {});
    const markSeoStaleRef = useRef(() => {});
    const blockOutsideClickGuardUntilRef = useRef(0);
    const linkScrollTokenRef = useRef(0);
    const intraSelectionRef = useRef({ text: '', html: '' });
    const focusedOutlineHeadingRef = useRef(null);
    const pendingAiMediaRef = useRef(new Map());
    /** Media ID user d� x�a kh?i editor � kh�ng t? ch�n l?i t? poll/event AI. */
    const dismissedEditorImageMediaIdsRef = useRef(new Set());
    const resumedArticleAiJobsRef = useRef(null);
    const mediaPollTimersRef = useRef(new Map());
    const generateImageInFlightRef = useRef(false);
    /** Stable bridge � host-actions effect runs above requestGenerateArticleImage declaration (avoid TDZ). */
    const requestGenerateArticleImageRef = useRef(null);

    useEffect(() => {
        activeBlockIdRef.current = activeBlockId;
    }, [activeBlockId]);

    useEffect(() => {
        globalEditorRef.current = globalEditor;
    }, [globalEditor]);

    const getExportHtml = useCallback(() => exportBlocksToHtml(blocksRef.current), []);
    getExportHtmlRef.current = getExportHtml;

    useEffect(() => {
        window.__seoExportEditorHtml = () => getExportHtml();
        return () => {
            delete window.__seoExportEditorHtml;
        };
    }, [getExportHtml]);

    useEffect(() => {
        if (seoScoringRules.length > 0) {
            window.__SEO_SCORING_RULES__ = seoScoringRules;
        }
    }, [seoScoringRules]);

    useEffect(() => {
        if (Object.keys(scoringMessages).length > 0) {
            window.__SEO_RULE_MESSAGES__ = scoringMessages;
        }
    }, [scoringMessages]);

    const liveSeoScore = useMemo(() => {
        if (!isCompletedSeoAnalysis(analysis)) {
            return null;
        }
        const violations = sanitizeViolations(
            Array.isArray(analysis?.violations) ? analysis.violations : [],
            seoScoringRules,
        );

        return scoreFromViolations(violations, seoScoringRules);
    }, [analysis, seoScoringRules]);

    const seoFailedItems = useMemo(() => {
        if (!isCompletedSeoAnalysis(analysis)) {
            return [];
        }
        const violations = sanitizeViolations(
            Array.isArray(analysis?.violations) ? analysis.violations : [],
            seoScoringRules,
        );

        return buildFailedViolationItems(
            violations,
            seoScoringRules,
            scoringMessages,
            analysis?.metrics ?? {},
        );
    }, [analysis, seoScoringRules, scoringMessages]);

    const seoFailedCount = seoFailedItems.length;

    useEffect(() => {
        // Content widgets only — typing (blocks) must not rebuild featured/gallery health.
        const locale = String(document?.documentElement?.lang ?? 'vi').startsWith('en') ? 'en' : 'vi';
        const contentImages = collectContentImagesFromArticle(blocks);
        const imageRows = unifiedImageRows;
        const keyword = String(focusKeyword ?? '').trim();
        const fromAnalysis = analysis?.metrics?.image_ratio ?? {};
        const useSnap = fromAnalysis.count_source === 'media_snapshot'
            && Number.isFinite(Number(fromAnalysis.valid_image_count));
        // Ratio current = body content occurrences only — never unified inventory total.
        const validCount = useSnap
            ? Math.max(0, Number(fromAnalysis.valid_image_count) || 0)
            : contentImages.valid_content_image_count;
        const wordCount = Math.max(
            0,
            Number(fromAnalysis.current_word_count)
            || Number(analysis?.metrics?.word_count)
            || Number(analysis?.metrics?.eligible_word_count)
            || 0,
        );
        const wordsPerImage = Math.max(
            1,
            Number(fromAnalysis.target_words_per_image)
            || Number(fromAnalysis.words_per_image)
            || 200,
        );
        const recommendedFromWords = wordCount > 0
            ? Math.max(1, Math.ceil(wordCount / wordsPerImage))
            : 0;
        const recommended = Number(fromAnalysis.recommended_image_count) > 0
            ? Number(fromAnalysis.recommended_image_count)
            : recommendedFromWords;
        const imageRatioMetrics = {
            ...fromAnalysis,
            current_word_count: wordCount || Number(fromAnalysis.current_word_count) || 0,
            current_image_count: validCount,
            valid_image_count: validCount,
            recommended_image_count: recommended,
            missing_image_count: Math.max(0, recommended - validCount),
            target_words_per_image: wordsPerImage,
            words_per_image: wordsPerImage,
        };
        const runtime = getDefaultArticleEditorRuntime();
        const linksSource = extractedLinks
            ?? analysis?.extracted_links
            ?? null;
        const analysisReady = isCompletedSeoAnalysis(analysis);
        const seoIncomplete = !analysisReady || analyzing;
        publishPartialRuntimeWidgetHealth(runtime, {
            seo: {
                focusKeyword: keyword,
                violations: analysisReady && Array.isArray(analysis?.violations) ? analysis.violations : [],
                failedItems: seoFailedItems,
                locale,
                incomplete: seoIncomplete,
                analysisReady: analysisReady && !analyzing,
            },
            images: {
                rows: imageRows,
                keyword,
                imageRatioMetrics,
                locale,
                messages: scoringMessages,
                incomplete: false,
            },
            links: {
                extractedLinks: linksSource,
                locale,
                incomplete: linksSource == null,
            },
        }, {
            reviewsBadge: showReviewsTab && isProductPost && reviewCount !== null
                ? reviewCount
                : null,
        }, {
            articleId,
            generation: getDiagnosticsGeneration(),
            preserveStable: true,
        });
    }, [
        analysis,
        analyzing,
        articleId,
        blocks,
        unifiedImageRows,
        supplementalImages,
        focusKeyword,
        seoFailedItems,
        scoringMessages,
        extractedLinks,
        showReviewsTab,
        isProductPost,
        reviewCount,
        imageTabCount,
        mediaHealthTick,
    ]);

    useEffect(() => {
        // Media snapshot widgets — independent of TipTap typing / blocks.
        const locale = String(document?.documentElement?.lang ?? 'vi').startsWith('en') ? 'en' : 'vi';
        const keyword = String(focusKeyword ?? '').trim();
        const runtime = getDefaultArticleEditorRuntime();
        publishPartialRuntimeWidgetHealth(runtime, {
            featured: {
                articleId,
                featuredImage: featuredHealthSnapshot ?? featuredFromSnapshot(articleId),
                keyword,
                altMandatory: Boolean(getAnalysisPolicy()?.featured?.alt_required),
                locale,
            },
            gallery: {
                required: Boolean(
                    getAnalysisPolicy()?.gallery?.required
                    ?? (supportsProductGallery || isProductPost),
                ),
                items: productGalleryItems,
                keyword,
                locale,
            },
        }, {}, {
            articleId,
            generation: getDiagnosticsGeneration(),
            preserveStable: true,
        });
    }, [
        articleId,
        focusKeyword,
        supportsProductGallery,
        isProductPost,
        productGalleryItems,
        featuredHealthSnapshot,
        mediaHealthTick,
    ]);

    // Publishing shell chip — category readiness from Alpine/Livewire staged state (Laravel-only OK).
    useEffect(() => {
        const publishPublishingHealth = (detail = {}) => {
            const postType = String(
                detail.postType
                ?? detail.post_type
                ?? articleType
                ?? initialPostType
                ?? 'article',
            ).trim();
            const recordType = String(detail.recordType ?? detail.record_type ?? '').trim();
            const selectedIds = Array.isArray(detail.selectedIds)
                ? detail.selectedIds
                : (Array.isArray(detail.selected_ids) ? detail.selected_ids : (
                    typeof window.__seoPublishCategoriesSnapshot === 'function'
                        ? window.__seoPublishCategoriesSnapshot()
                        : []
                ));
            const resolved = resolvePublishingCategoryTaxonomy(postType, recordType);
            const locale = String(document?.documentElement?.lang ?? 'vi').startsWith('en') ? 'en' : 'vi';
            const health = buildPublishingWidgetHealth({
                postType,
                recordType,
                selectedIds,
                required: resolved.required,
                taxonomy: resolved.taxonomy,
                locale,
            });
            patchRuntimeWidgetHealth({ publishing: health }, {}, {
                articleId,
                generation: getDiagnosticsGeneration(),
                preserveStable: true,
            });
        };

        publishPublishingHealth();
        const onCategoriesChanged = (event) => publishPublishingHealth(event?.detail || {});
        const onPostTypeChanged = (event) => publishPublishingHealth({
            postType: event?.detail?.postType ?? event?.detail?.post_type,
        });
        window.addEventListener('seo-publishing-categories-changed', onCategoriesChanged);
        window.addEventListener('seo-publish-post-type-changed', onPostTypeChanged);
        return () => {
            window.removeEventListener('seo-publishing-categories-changed', onCategoriesChanged);
            window.removeEventListener('seo-publish-post-type-changed', onPostTypeChanged);
        };
    }, [articleId, articleType, initialPostType]);

    // Phase 6C.3 — Featured/Gallery health from media snapshot (no Alpine tick / LS).
    useEffect(() => {
        const syncFromSnapshot = () => {
            const nextFeatured = featuredFromSnapshot(articleId);
            const nextGallery = parseGalleryItems(galleryFromSnapshot(articleId));
            mediaActions.setFeaturedHealthSnapshot(nextFeatured);
            mediaActions.setGallery(nextGallery);
            setMediaHealthTick((tick) => tick + 1);
        };
        syncFromSnapshot();
        const unsub = subscribeMediaSnapshot(({ articleId: aid }) => {
            if (Number(aid) === Number(articleId)) {
                syncFromSnapshot();
            }
        });
        const onHealthRefresh = () => syncFromSnapshot();
        window.addEventListener('seo-assistant-widget-health-refresh', onHealthRefresh);
        return () => {
            unsub();
            window.removeEventListener('seo-assistant-widget-health-refresh', onHealthRefresh);
        };
    }, [articleId, parseGalleryItems]);

        const { analyzedBlocksRef, applySeoAnalysisResult, createFaqFromShortcode, handleSeoViolationAction, markSeoAnalysisReady, markSeoStale, openFaqModule, requestAnalyze, resolveArticleFaqsSnapshot, runLocalSeoAnalysis, seoAnalysisReady, seoStale, setSeoStale } = useArticleEditorSeoAnalysis({ articleId, articleTitle, articleType, blockEditorsRef, blockFlushRef, blocksRef, canGenerateFaq, clientOutline, editorSettings, faqsCanonicalKnownRef, focusKeyword, getExportHtml, lastSeoAnalysisRef, panelFaqsRef, pendingFaqGenerateRef, publishExtractedLinks, requestAnalyzeRef, scoringMessages, seoDomain, seoMetaRef, seoScoringRules, setAnalyzing, setExtractedLinks, setFeaturedSnippetPreviewHtml, setFeaturedSnippetPromptContext, setFeaturedSnippetPromptOpen, setSeoAnalyzeError, setSeoScoreSource, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomain, siteDomainRef, tempMergeRef, wikiTrustDomains });
        markSeoStaleRef.current = markSeoStale;

        useEffect(() => {
            markSeoAnalysisReady(isCompletedSeoAnalysis(analysis));
        }, [analysis, markSeoAnalysisReady]);

    const autosaveIntervalSecondsRaw = Number(editorSettings?.autosave_interval_seconds);
    const autosaveIntervalSeconds = Number.isFinite(autosaveIntervalSecondsRaw)
        ? Math.max(0, Math.min(30, autosaveIntervalSecondsRaw))
        : 2;
    const draftSaveDisabled = autosaveIntervalSeconds === 0
        || Boolean(sessionReadOnly)
        || Boolean(window.__SEO_EDITOR_READ_ONLY__);
    const draftSaveDelayMs = Math.max(1, autosaveIntervalSeconds || 2) * 1000;
    const serverAutosaveDebounceMs = Math.max(
        1000,
        Number(editorSettings?.server_autosave_debounce_ms)
            || Number(window.__SEO_EDITOR_SERVER_AUTOSAVE_DEBOUNCE_MS__)
            || 4000,
    );
        const { bootstrapBodyPlainRef, lastAutosaveHashRef, markRecoveringClear, networkRecovering, networkUnavailable, networkUnavailableRef, noteLocalRevisionChanged, scheduleServerAutosaveRef, serverAutosaveDirtyRef, serverAutosaveInFlightRef, serverAutosaveNeedsRetryRef, serverAutosaveSeqRef, whitespaceCorruptionLockedRef } = useArticleEditorSessionNetwork({ articleId, getExportHtml, initialHtml, saveStatus, sessionReadOnly, setSaveStatus });

        const { assertWritableDocumentNotWhitespaceCorrupted, canRedo, canUndo, cancelLocalDraftSave, clearTempMerge, historySteps, loadedArticleIdRef, redo, scheduleAutosave, skipNextAutosave, undo, updateBlocksWithoutHistory } = useArticleEditorSaveQueue({ articleId, blockEditorsRef, blocks, blocksRef, bootstrapBodyPlainRef, connectionHashRef, documentVersion, draftSaveDelayMs, draftSaveDisabled, getExportHtml, historyStep, lastAutosaveHashRef, markRecoveringClear, networkUnavailableRef, noteLocalRevisionChanged, scheduleAutosaveRef, scheduleServerAutosaveRef, serverAutosaveDebounceMs, serverAutosaveDirtyRef, serverAutosaveInFlightRef, serverAutosaveNeedsRetryRef, serverAutosaveSeqRef, sessionReadOnly, setActiveBlockId, setBlocks, setSaveStatus, setTempMerge, whitespaceCorruptionLockedRef, withDraftSite });

        const { applyDraftRestore, discardDraftRestore, draftChoiceModalOpen, draftRestoreOffer, keepServerOverDraft, reconcileImagesTabWithBlocks } = useArticleEditorBootstrap({ analyzedBlocksRef, articleId, bootstrapBodyPlainRef, canRedo, canUndo, cancelLocalDraftSave, clearTempMerge, connectionHashRef, dismissedEditorImageMediaIdsRef, draftScope, expectedContentHash, expectedUpdatedAt, hasHydratedSeoFromServerRef, initialEditorDocument, initialEditorDocumentHash, initialHtml, initialPostImages, initialSeo, loadedArticleIdRef, markSeoAnalysisReady, postImagesRef, redo, requestAnalyze, sessionReadOnly, setActiveBlockId, setAnalyzing, setBlocks, setExtractedLinks, setGlobalEditor, setImagesReloadKey, setSeoStale, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomainRef, skipNextAutosave, suggestionExternalCatalogRef, undo, whitespaceCorruptionLockedRef, withDraftSite });

        const { commitActiveBlock, registerBlockEditor, registerBlockFlush, updateBlockContent } = useArticleEditorBlockContentCommands({ activeBlockIdRef, articleId, blockEditorsRef, blockFlushRef, documentVersion, editorHostActionsRef, editorSettings, globalEditorRef, initialEditorDocumentHash, initialPostType, perfDebug, reconcileImagesTabWithBlocks, markSeoStaleRef, scheduleAutosaveRef, sessionReadOnly, setBlocks, setRuntimeContextRevision, structureMutationRef, tempMergeRef });

        const { applySlugRenameFinished, armBlockOutsideClickGuard, assertNoLocalSlugFixBeforeWpSync, handleImageAltTitleChange, patchImageInBlocks, persistEditorContentImmediately, quickFixAltTitleAllImages, quickFixAltTitleSingleImage, quickFixSlugAllImages, quickFixSlugSingleImage, selectPlainTextInBlock } = useArticleEditorImageSlugRename({ articleId, articleTitle, blockEditorsRef, blockFlushRef, blockOutsideClickGuardUntilRef, blocksRef, cancelLocalDraftSave, commitActiveBlock, connectionHashRef, draftScope, focusKeyword, getExportHtml, pendingLocalRenameQueueRef, pendingLocalRenameResultsRef, pendingQuickFixKeywordRef, pendingWpRenameRequestRef, publishEditorImagesCatalogRef, quickFixSlugAllBusy, requestAnalyze: markSeoStale, scheduleAutosave, setActiveBlockId, setBlocks, setGlobalEditor, setImagesReloadKey, setMediaHealthTick, setQuickFixSlugAllBusy, setSaveStatus, siteId, siteIdRef, skipNextAutosave, slugRenameManagedByBatchRef, supplementalImages, supplementalImagesRef, supportsProductGallery, tempMergeRef, unifiedImageRows, unifiedImagesInventory, updateBlocksWithoutHistory, withDraftSite });

        const { collapseSectionsExcept, focusImageBlock, insertCtaLinkIntoContent, insertSuggestedLinkIntoContent, quickGenerateImageForSection, removeInternalLinkFromContent, scrollToExtractedLink, scrollToFeaturedSnippetTable } = useArticleEditorLinksAndSnippets({ activeBlockId, activeBlockIdRef, applySlugRenameFinished, articleId, articleTitle, blockById, blockEditorsRef, blockFlushRef, blocksRef, clearTempMerge, commitActiveBlock, connectionHashRef, editorHostActionsRef, editorSections, focusKeyword, intraSelectionRef, linkScrollTokenRef, notifyIntroNoImages, persistEditorContentImmediately, requestAnalyze: markSeoStale, scheduleAutosave, sectionByBlockId, selectPlainTextInBlock, setActiveBlockId, setBlocks, setCollapsedSectionIds, setExtractedLinks, setGlobalEditor, setImageRenameBusy, setImageRenameBusyCount, setSaveStatus, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomainRef, slugRenameManagedByBatchRef, updateBlockContent });

        const { clearMediaPolling, deleteBlock, isDismissedEditorImageMedia, makeImageFeatured, removeImageBlock, removeSupplementalImage } = useArticleEditorImageLifecycle({ activeBlockId, articleId, articleTitle, blockById, blockFlushRef, blocks, blocksRef, clearTempMerge, commitActiveBlock, dismissedEditorImageMediaIdsRef, editorHostActionsRef, extractedLinks, focusImageBlock, focusKeyword, generateImageTargetRef, getExportHtml, insertCtaLinkIntoContent, insertSuggestedLinkIntoContent, intraSelectionRef, mediaPollTimersRef, pendingAiMediaRef, publishEditorImagesCatalogRef, publishExtractedLinks, removeInternalLinkFromContent, requestGenerateArticleImageRef, scheduleAutosave, scrollToExtractedLink, scrollToFeaturedSnippetTable, sectionByBlockId, setActiveBlockId, setBlocks, setExtractedLinks, setGlobalEditor, setImagesReloadKey, siteDomain, siteDomainRef, suggestedExternalLinks, suggestedInternalLinks, supplementalImagesRef, supportsProductGallery, tempMergeRef, utilitySchedulerRef });

        const { activateBlock, clearOutlineFocus, insertBlockRelative, syncOutlineFocusFromBlock } = useArticleEditorBlockActivation({ activeBlockId, activeBlockIdRef, armBlockOutsideClickGuard, articleId, blocks, blocksRef, clearTempMerge, collapsedSectionIds, commitActiveBlock, focusedOutlineHeadingRef, intraSelectionRef, isIntroBlockId, notifyIntroNoImages, outlineHasSavedHeadings, outlineHeadingIdsByBlockIdRef, outlineHeadingKeys, sectionByBlockId, setActiveBlockId, setBlocks, setCollapsedSectionIds, setGlobalEditor, setInsertMenu, setOutlineHeadingCommand, tempMerge, tempMergeRef });

        const { applyCompletedMediaToPlaceholder, applyCompletedMediaToProductGallery, clearAwaitingClientImagePlaceholders, findImageBlockByMediaId, insertImageAfterBlock, placeProcessingImagePlaceholder, requestGenerateArticleImage, resolveAiRefBlockId, startMediaStatusPolling } = useArticleEditorImageGeneration({ activeBlockIdRef, articleId, blocks, blocksRef, clearMediaPolling, commitActiveBlock, connectionHashRef, dismissedEditorImageMediaIdsRef, generateImageInFlightRef, getExportHtml, isDismissedEditorImageMedia, isIntroBlockId, mediaPollTimersRef, notifyIntroNoImages, patchImageInBlocks, pendingAiMediaRef, requestGenerateArticleImageRef, resumedArticleAiJobsRef, scheduleAutosave, setActiveBlockId, setBlocks, setGlobalEditor, setImagesReloadKey, setSaveStatus, tempMergeRef, updateBlocksWithoutHistory });

    // �ang c�n placeholder spin � reconcile d?nh k? (poll miss / m?t pending map).
        const { deleteSection, handleOutlineDeleteHeading, handleOutlineMoveHeading, insertVideoAfterBlock, moveBlockToSection, startTempMerge, toggleInsertMenu } = useArticleEditorInsertAndSections({ activeBlockId, activeBlockIdRef, applyCompletedMediaToPlaceholder, articleId, blockEditorsRef, blockFlushRef, blocks, blocksRef, commitActiveBlock, deleteBlock, dismissedEditorImageMediaIdsRef, editorSections, insertBlockRelative, notifyIntroNoImages, outlineAppendDoneRef, outlineAppendInflightRef, outlineHasSavedHeadings, outlineHeadingIdsByBlockIdRef, patchImageInBlocks, pendingAiMediaRef, sectionHeadingBlockIds, setActiveBlockId, setBlocks, setGlobalEditor, setInsertMenu, setOutlineHeadingKeys, setOutlineTreeSync, setTempMerge, startMediaStatusPolling, structureMutationRef, tempMergeRef });

        const { mergedDisplay, saveLabel } = useArticleEditorExternalEventsBridge({ activeBlockId, activeBlockIdRef, analyzedBlocksRef, applyCompletedMediaToPlaceholder, applyCompletedMediaToProductGallery, applySeoAnalysisResult, articleId, articleTitle, assertNoLocalSlugFixBeforeWpSync, assertWritableDocumentNotWhitespaceCorrupted, blockEditorsRef, blockFlushRef, blockOutsideClickGuardUntilRef, blocks, blocksRef, clearAwaitingClientImagePlaceholders, clearMediaPolling, clearOutlineFocus, clearTempMerge, connectionHashRef, dismissedEditorImageMediaIdsRef, editorHostActionsRef, faqsCanonicalKnownRef, findImageBlockByMediaId, generateImageTargetRef, getExportHtml, globalEditor, initialPostImages, insertImageAfterBlock, insertVideoAfterBlock, isDismissedEditorImageMedia, lastSeoAnalysisRef, mediaPollTimersRef, networkRecovering, networkUnavailable, outlineHasSavedHeadings, panelFaqsRef, patchImageInBlocks, pendingAiMediaRef, placeProcessingImagePlaceholder, postImagesRef, publishEditorImagesCatalogRef, reconcileImagesTabWithBlocks, requestAnalyze, requestGenerateArticleImage, resolveAiRefBlockId, resolveArticleFaqsSnapshot, runLocalSeoAnalysis, saveStatus, scheduleAutosave, markSeoStale, sectionByBlockId, seoDomain, seoMetaRef, setActiveBlockId, setArticleType, setBlocks, setFaqCount, setGlobalEditor, setImagesReloadKey, setInsertMenu, setPanelFaqs, setSaveStatus, setSavedSeoScore, setSeoStale, setSupportsProductGallery, skipNextAutosave, startMediaStatusPolling, supplementalImagesRef, tempMerge, tempMergeRef, updateBlockContent });

    // Sync text heading t? tab Outline v? block tuong ?ng trong editor ch�nh.
        const { addOutlineNode, addSection, addSectionAfter, applyOutlineHeadingHtml, applyOutlineHeadingText, changeOutlineHeadingLevel, collapseAllSections, confirmFeaturedSnippetPromptInsert, convertOutlineHeading, deleteOutlineHeadingKeepContent, deleteOutlineHeadingWithContent, focusOutlineFromSectionHeader, handleOutlineHeadingFromEditor, handleOutlineLoaded, insertFeaturedSnippetAsNewSectionAfter, jumpToOutlineHeading, requestGenerateFeaturedSnippetAfterSection, resolveHeadingInnerHtml, runFeaturedSnippetPromptGenerate, saveSectionTitleFromHeader, toggleOutlineHeadingVisible, toggleSectionCollapse, updateOutlineHeadingTitle } = useArticleEditorOutline({ activeBlockId, activateBlock, articleId, articleTitle, blockEditorsRef, blockFlushRef, blocksRef, canGenerateFeaturedSnippet, collapseSectionsExcept, commitActiveBlock, editorSections, featuredSnippetGenerating, featuredSnippetPreviewHtml, featuredSnippetTargetRef, focusImageBlock, focusKeyword, focusedOutlineHeadingRef, outlineAppendDoneRef, outlineAppendInflightRef, outlineFingerprintRef, outlineHasSavedHeadings, outlineHeadingIdsByBlockIdRef, outlineHeadingIdsByKeyRef, outlineRailRef, markSeoStale, sectionByBlockId, sectionHeadingBlockIds, setActiveBlockId, setBlocks, setClientOutline, setCollapsedSectionIds, setFeaturedSnippetGenerating, setFeaturedSnippetPreviewHtml, setFeaturedSnippetPromptOpen, setGlobalEditor, setImagesTabJumpTarget, setInsertMenu, setOutlineHasSavedHeadings, setOutlineHeadingKeys, setOutlineJumpTarget, setOutlineTreeSync, setSectionTitleEditRequest, syncOutlineFocusFromBlock, tempMergeRef });

        const { handleEditorSearchAction } = useArticleEditorSearch({ blockById, clearTempMerge, commitActiveBlock, editorSections, featuredSnippetTargetRef, insertFeaturedSnippetAsNewSectionAfter, publishEditorImagesCatalogRef, quickReplaceFind, quickReplaceValue, setBlocks, setCollapsedSectionIds, setEditorSearchMatchCount, setFeaturedSnippetGenerating, setFeaturedSnippetPreviewHtml, setImagesReloadKey, tempMergeRef });

    const editorHostApi = useMemo(() => ({
        contractVersion: 1,
        article: {
            id: articleId,
            type: articleType,
            supportsProductGallery: Boolean(supportsProductGallery),
        },
        seo: {
            focusKeyword,
            analysis: syncRequired ? null : analysis,
            seoScoringRules,
            seoRuleMessages: scoringMessages,
            loading: false,
            analyzing: syncRequired ? false : analyzing,
            stale: syncRequired ? false : seoStale,
            ready: syncRequired ? false : seoAnalysisReady,
            analyzeError: syncRequired ? null : seoAnalyzeError,
            error: seoSummaryError,
            savedScore: syncRequired ? null : savedSeoScore,
            scoreSource: syncRequired ? 'unavailable' : seoScoreSource,
            syncRequired,
            unavailableMessage: syncRequired ? t('content_sync_required_seo') : null,
            onRetry: () => {
                if (syncRequired) {
                    return;
                }
                seoSummaryLoadedRef.current = false;
                setSeoSummaryError(null);
                seoDomain.clearAnalysis();
            },
            onAnalyzeClick: () => {
                if (syncRequired) {
                    return;
                }
                requestAnalyze();
            },
            onViolationAction: handleSeoViolationAction,
            canGenerateFaq: canGenerateFaq && !syncRequired,
            canGenerateFeaturedSnippet: canGenerateFeaturedSnippet && !syncRequired,
        },
        ai: {
            debug: editorSettings?.ai_debug ?? null,
            canGenerateImage: !sessionReadOnly
                && canMutateEditor()
                && editorSettings?.can_generate_image !== false,
            canGenerateVideo: !sessionReadOnly
                && canMutateEditor()
                && editorSettings?.can_generate_video === true,
        },
        images: {
            reloadKey: imagesReloadKey,
            blocks,
            extraImages: unifiedImageRows,
            featuredImage: featuredHealthSnapshot ?? featuredFromSnapshot(articleId),
            galleryImages: productGalleryItems,
            useUnifiedInventory: true,
            siteId,
            articleId,
            jumpTarget: imagesTabJumpTarget,
            focusKeyword,
            articleTitle,
            onPatchImage: patchImageInBlocks,
            onFocusBlock: focusImageBlock,
            onQuickFixSlugAll: quickFixSlugAllImages,
            quickFixSlugAllBusy,
            onQuickFixSlugOne: quickFixSlugSingleImage,
            onQuickFixAltTitleAll: quickFixAltTitleAllImages,
            onQuickFixAltTitleOne: quickFixAltTitleSingleImage,
            onRemoveImage: removeImageBlock,
            onRemoveSupplementalImage: removeSupplementalImage,
            onAltTitleChange: handleImageAltTitleChange,
            onMakeFeatured: makeImageFeatured,
            onNotify: (payload) => {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', { detail: payload }));
            },
        },
        reviews: {
            articleId,
            initialReviews: virtualReviews,
            onRefresh: refreshVirtualReviews,
            loading: reviewsLoading,
            loaded: reviewsLoaded,
            count: reviewCount,
            countLoading: reviewCountLoading,
            warning: reviewsLoadWarning,
            canQuickCreate: canQuickCreateReviews,
            showConfigureReviews: showConfigureReviewsLink,
            quickCreateConfigUrl: quickCreateReviewsConfigUrl,
            onQuickCreate: canQuickCreateReviews ? generateQuickPostReviews : undefined,
        },
    }), [
        articleType,
        supportsProductGallery,
        focusKeyword,
        analysis,
        seoScoringRules,
        scoringMessages,
        seoSummaryLoading,
        analyzing,
        seoStale,
        seoAnalysisReady,
        seoAnalyzeError,
        savedSeoScore,
        seoScoreSource,
        seoSummaryError,
        requestAnalyze,
        handleSeoViolationAction,
        syncRequired,
        canGenerateFaq,
        canGenerateFeaturedSnippet,
        imagesReloadKey,
        blocks,
        unifiedImageRows,
        featuredHealthSnapshot,
        productGalleryItems,
        supplementalImages,
        siteId,
        articleId,
        imagesTabJumpTarget,
        articleTitle,
        patchImageInBlocks,
        focusImageBlock,
        quickFixSlugAllImages,
        quickFixSlugAllBusy,
        quickFixSlugSingleImage,
        quickFixAltTitleAllImages,
        quickFixAltTitleSingleImage,
        removeImageBlock,
        removeSupplementalImage,
        handleImageAltTitleChange,
        makeImageFeatured,
        virtualReviews,
        refreshVirtualReviews,
        reviewCount,
        reviewCountLoading,
        reviewsLoaded,
        reviewsLoading,
        reviewsLoadWarning,
        canQuickCreateReviews,
        showConfigureReviewsLink,
        quickCreateReviewsConfigUrl,
        generateQuickPostReviews,
        editorSettings?.ai_debug,
        editorSettings?.can_generate_image,
        editorSettings?.can_generate_video,
        sessionReadOnly,
    ]);

    const editorPanelShells = useMemo(() => ({
        seo: ({ children }) => (
            <ArticleAssistantWidget
                widgetId="seo"
                title="SEO Assistant"
                icon={BarChart3}
                badge={syncRequired ? null : (analyzing ? '…' : (liveSeoScore ?? null))}
                defaultCollapsed={false}
                className="seo-assistant-widget--seo"
            >
                {children}
            </ArticleAssistantWidget>
        ),
        images: ({ children }) => (
            <ArticleAssistantWidget
                widgetId="images"
                title="Image Assistant"
                icon={ImageIcon}
                badge={imageTabCount > 0 ? imageTabCount : null}
                defaultCollapsed={false}
                className="seo-assistant-widget--images"
            >
                {children}
            </ArticleAssistantWidget>
        ),
        reviews: ({ children }) => (
            <ArticleAssistantWidget
                widgetId="reviews"
                title={t('reviews_tab_label')}
                icon={Star}
                badge={reviewCount}
                defaultCollapsed
                className="seo-assistant-widget--reviews"
            >
                {children}
            </ArticleAssistantWidget>
        ),
    }), [analyzing, liveSeoScore, imageTabCount, reviewCount, syncRequired]);

    return (
        <div
            className={`seo-article-editor-root${sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ ? ' seo-article-editor-root--hard-readonly' : ''}`}
            data-seo-editor-hard-readonly={sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ ? '1' : '0'}
        >
            <EditorBusyOverlay
                visible={imageRenameBusy || quickFixSlugAllBusy}
                title={
                    quickFixSlugAllBusy
                        ? t('editor_quick_fix_slug_all_busy')
                        : t('editor_renaming_wp_images')
                }
                message={
                    quickFixSlugAllBusy
                        ? t('editor_please_wait')
                        : imageRenameBusyCount > 0
                          ? t('editor_renaming_wp_images_body', { count: imageRenameBusyCount })
                          : t('editor_please_wait')
                }
            />
            <div className="seo-article-editor-workspace">
                <div className="seo-article-editor-left-rail">
                    <ArticleGoogleSerpPreview
                        articleId={articleId}
                        initialPreview={initialSeo?.google_serp_preview ?? {
                            title: String(articleTitle ?? '').trim(),
                            description: String(initialSeo?.meta_description ?? '').trim(),
                            url: '#',
                            display_url: '#',
                        }}
                        fallbackUrl={String(initialSeo?.google_serp_preview?.url ?? initialSeo?.site_domain ?? '#')}
                        skipSeoScore={Boolean(initialSeo?.skip_seo_score)}
                        initialFocusKeyword={String(initialSeo?.focus_keyword ?? '')}
                        initialSlug={String(initialSeo?.article_slug ?? '')}
                        permalinkBase={String(initialSeo?.permalink_base ?? '')}
                        permalinkSuffix={String(initialSeo?.permalink_suffix ?? '')}
                        promptHooks={editorSettings?.prompt_hooks ?? null}
                        articleTitle={articleTitle}
                    />

                    <aside
                        ref={outlineRailRef}
                        className="seo-article-editor-outline-rail"
                        aria-label="Outline / D�n �"
                    >
                        <ArticleOutlineTab
                            articleId={articleId}
                            headingCommand={outlineHeadingCommand}
                            outlineTreeSync={outlineTreeSync}
                            canGenerateOutlineHeading={canGenerateOutlineHeading && !syncRequired}
                            resolveHeadingInnerHtml={resolveHeadingInnerHtml}
                            preferClientSource={!syncRequired}
                            clientOutline={syncRequired ? [] : clientOutline}
                            syncRequired={syncRequired}
                            onClientRefresh={() => {
                                if (syncRequired) {
                                    return [];
                                }
                                outlineFingerprintRef.current = '';
                                const tree = buildClientOutlineTree(blocksRef.current);
                                outlineFingerprintRef.current = outlineHeadingFingerprint(blocksRef.current);
                                setClientOutline(tree);
                                return tree;
                            }}
                            onOutlineLoaded={handleOutlineLoaded}
                            onHeadingTextChange={applyOutlineHeadingText}
                            onHeadingHtmlChange={applyOutlineHeadingHtml}
                            onSaveOutlineHeadingTitle={updateOutlineHeadingTitle}
                            onJumpToEditorHeading={jumpToOutlineHeading}
                            onOutlineMoveHeading={handleOutlineMoveHeading}
                            onOutlineDeleteHeading={handleOutlineDeleteHeading}
                            onOutlineAddSection={addSection}
                            onOutlineAddNode={addOutlineNode}
                            onOutlineChangeLevel={changeOutlineHeadingLevel}
                            onOutlineConvert={convertOutlineHeading}
                            onOutlineDeleteWithContent={deleteOutlineHeadingWithContent}
                            onOutlineToggleVisible={toggleOutlineHeadingVisible}
                            onNotify={(payload) => {
                                window.dispatchEvent(
                                    new CustomEvent('seo-article-editor-notify', { detail: payload }),
                                );
                            }}
                            onRequestEditorHtml={getExportHtml}
                        />
                    </aside>
                </div>

                <div className="seo-article-editor-mainpane">
            <div className="seo-editor-sticky-boundary">
            <div className="seo-editor-toolbar seo-editor-toolbar--document">
                <div className="seo-editor-toolbar__actions">
                    <button
                        type="button"
                        className="seo-history-btn"
                        onClick={() => {
                            if (sessionReadOnly || !canMutateEditor()) {
                                return;
                            }
                            clearTempMerge();
                            commitActiveBlock();
                            undo();
                        }}
                        disabled={sessionReadOnly || !canUndo || !canMutateEditor()}
                        title={sessionReadOnly
                            ? t('editor_locked_mutation_tooltip')
                            : t('editor_undo_with_count', { undo: historySteps.undo, max: historySteps.max })}
                    >
                        <Undo2 size={15} />
                    </button>
                    <button
                        type="button"
                        className="seo-history-btn"
                        onClick={() => {
                            if (sessionReadOnly || !canMutateEditor()) {
                                return;
                            }
                            clearTempMerge();
                            commitActiveBlock();
                            redo();
                        }}
                        disabled={sessionReadOnly || !canRedo || !canMutateEditor()}
                        title={sessionReadOnly
                            ? t('editor_locked_mutation_tooltip')
                            : t('editor_redo_with_count', { redo: historySteps.redo })}
                    >
                        <Redo2 size={15} />
                    </button>
                    <span className="seo-autosave-status seo-autosave-status--toolbar-hidden" aria-hidden="true">
                        {saveLabel}
                    </span>
                    {syncRequired ? null : analyzing ? (
                        <span className="seo-analyze-stale-hint">{t('editor_seo_analyzing')}</span>
                    ) : seoAnalyzeError ? (
                        <button
                            type="button"
                            className="seo-analyze-stale-hint"
                            onClick={requestAnalyze}
                            title={t('editor_seo_analyze_failed')}
                        >
                            {t('editor_seo_analyze_failed')} � {t('editor_seo_analyze_retry')}
                        </button>
                    ) : seoStale ? (
                        <button
                            type="button"
                            className="seo-analyze-stale-hint"
                            onClick={requestAnalyze}
                            title={t('editor_seo_stale')}
                        >
                            {t('editor_seo_stale')}
                        </button>
                    ) : null}
                </div>
            </div>

            <div className="editor-container">
                    <div className="max-w-none space-y-3">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div className="text-xs font-medium text-gray-500 dark:text-gray-300">
                                {syncRequired
                                    ? t('content_sync_required_title')
                                    : `${t('editor_total_words')}: ${totalWordCount}`}
                            </div>
                            <div className="ml-auto flex flex-wrap items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={collapseAllSections}
                                    className="seo-editor-search-btn"
                                    title={t('editor_collapse_all_sections')}
                                    aria-label={t('editor_collapse_all_sections')}
                                >
                                    <ListCollapse size={15} />
                                </button>
                                <div className="seo-editor-search-group">
                                    <input
                                        type="text"
                                        value={quickReplaceFind}
                                        onChange={(event) => setQuickReplaceFind(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                handleEditorSearchAction();
                                            }
                                        }}
                                        placeholder={t('editor_find')}
                                        className="seo-editor-search-input"
                                        aria-label={t('editor_find')}
                                    />
                                    {quickReplaceFind.trim() !== '' && editorSearchMatchCount != null ? (
                                        <span
                                            className={
                                                'seo-editor-search-count' +
                                                (editorSearchMatchCount > 0
                                                    ? ' is-found'
                                                    : ' is-empty')
                                            }
                                            title={t('editor_search_count_title')}
                                        >
                                            {editorSearchMatchCount}
                                        </span>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={handleEditorSearchAction}
                                        className="seo-editor-search-btn"
                                        title={
                                            String(quickReplaceValue ?? '').trim() !== ''
                                                ? t('editor_replace_all')
                                                : t('editor_search_sections')
                                        }
                                        aria-label={t('editor_search_sections')}
                                    >
                                        <Search size={15} />
                                    </button>
                                </div>
                                <input
                                    type="text"
                                    value={quickReplaceValue}
                                    onChange={(event) => setQuickReplaceValue(event.target.value)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            event.preventDefault();
                                            handleEditorSearchAction();
                                        }
                                    }}
                                    placeholder={t('editor_replace')}
                                    className="h-8 w-36 rounded border border-gray-300 bg-white px-2 text-xs text-gray-800 dark:border-gray-600 dark:bg-slate-900 dark:text-gray-100"
                                />
                            </div>
                        </div>

                        {contentLoading ? (
                            <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-10 text-center text-sm text-slate-600 animate-pulse dark:border-slate-700 dark:bg-slate-900/40 dark:text-slate-300">
                                {t('editor_loading_content')}
                            </div>
                        ) : syncRequired ? (
                            <ArticleContentSyncRequiredBlocker
                                allowFetch={contentLifecycle.allow_fetch_from_wordpress
                                    && Boolean(editorSettings?.allow_wp_sync !== false)}
                                observedPermalink={contentLifecycle.observed_permalink}
                            />
                        ) : blocks.length === 0 ? (
                            <p className="text-gray-400 text-center py-10 italic text-sm">
                                {t('editor_loading_content')}
                            </p>
                        ) : (
                            editorSections.map((section, sectionIndex) => {
                                const isCollapsed = collapsedSectionIds[section.id] === true;
                                const sectionNumber = editorSections
                                    .slice(0, sectionIndex + 1)
                                    .filter((item) => !item.isIntro).length;
                                const visibleBlockIds = section.isIntro
                                    ? section.blockIds
                                    : section.blockIds.filter((blockId) => !sectionHeadingBlockIds.has(blockId));
                                const stats =
                                    sectionStats.get(section.id) ?? {
                                        imageCount: 0,
                                        emptyImageSrcCount: 0,
                                        hasEmptyImageSrc: false,
                                        hasTable: false,
                                        tableCount: 0,
                                        linkCount: 0,
                                        wordCount: 0,
                                    };
                                const canQuickDeleteEmptySection =
                                    !section.isIntro && sectionHasOnlyEmptyHeadingBody(section, blockById);

                                return (
                                    <section
                                        key={section.id}
                                        data-seo-section-id={section.id}
                                        className="rounded-lg border border-gray-200 bg-white/80 dark:border-gray-700 dark:bg-slate-900/40"
                                    >
                                        <header className="flex items-center justify-between gap-3 border-b border-gray-100 px-3 py-2 dark:border-gray-700">
                                            <div className="flex min-w-0 items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleSectionCollapse(section.id)}
                                                    className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded border border-gray-300 text-gray-700 hover:bg-gray-100 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-slate-700"
                                                    title={isCollapsed ? t('editor_expand_section') : t('editor_collapse_section')}
                                                >
                                                    {isCollapsed ? <ChevronRight size={15} /> : <ChevronDown size={15} />}
                                                </button>
                                                {section.isIntro ? (
                                                    <h3 className="truncate text-sm font-semibold text-gray-700 dark:text-gray-200">
                                                        {t('editor_intro')}
                                                    </h3>
                                                ) : (
                                                    <SectionHeaderTitle
                                                        sectionNumber={sectionNumber}
                                                        title={section.title}
                                                        onSave={(nextTitle) => saveSectionTitleFromHeader(section, nextTitle)}
                                                        onFocusOutline={() => focusOutlineFromSectionHeader(section)}
                                                        autoEditToken={
                                                            sectionTitleEditRequest?.sectionId === section.id
                                                                ? sectionTitleEditRequest.token
                                                                : 0
                                                        }
                                                    />
                                                )}
                                            </div>
                                            <span className="flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                {!section.isIntro ? (
                                                    <span
                                                        className={
                                                            'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] ' +
                                                            (stats.imageCount === 0
                                                                ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-500/60 dark:bg-red-900/40 dark:text-red-200'
                                                                : 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-600 dark:bg-slate-800/60 dark:text-gray-200')
                                                        }
                                                        title={t('editor_section_image_count')}
                                                    >
                                                        <ImageIcon size={11} />
                                                        <span>{stats.imageCount}</span>
                                                    </span>
                                                ) : null}

                                                {stats.hasTable ? (
                                                    <span
                                                        className="ml-1 inline-flex items-center gap-1 rounded border border-amber-300 bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800 dark:border-amber-500/60 dark:bg-amber-900/40 dark:text-amber-200"
                                                        title={t('editor_section_has_table')}
                                                    >
                                                        <Table size={11} />
                                                        <span>{stats.tableCount}</span>
                                                    </span>
                                                ) : null}

                                                {stats.linkCount > 0 ? (
                                                    <span
                                                        className="ml-1 inline-flex items-center gap-1 rounded border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 text-[11px] text-emerald-800 dark:border-emerald-500/60 dark:bg-emerald-900/40 dark:text-emerald-200"
                                                        title={t('editor_section_link_count')}
                                                    >
                                                        <Link2 size={11} />
                                                        <span>{stats.linkCount}</span>
                                                    </span>
                                                ) : null}

                                                <span
                                                    className="ml-1 inline-flex items-center gap-1 rounded border border-indigo-300 bg-indigo-50 px-1.5 py-0.5 text-[11px] text-indigo-800 dark:border-indigo-500/60 dark:bg-indigo-900/40 dark:text-indigo-200"
                                                    title={t('editor_section_word_count')}
                                                >
                                                    <span>W</span>
                                                    <span>{stats.wordCount}</span>
                                                </span>

                                                {!section.isIntro ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => quickGenerateImageForSection(section)}
                                                        className="seo-section-header-icon-btn ml-1 border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:border-sky-500/70 dark:bg-sky-900/30 dark:text-sky-200"
                                                        title={t('ai_visual_section')}
                                                        aria-label={t('ai_visual')}
                                                    >
                                                        <Sparkles size={12} />
                                                    </button>
                                                ) : null}

                                                {!section.isIntro ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => addSectionAfter(section)}
                                                        className="seo-section-header-icon-btn ml-1 border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100 dark:border-violet-500/70 dark:bg-violet-900/30 dark:text-violet-200"
                                                        title={t('editor_add_section_after')}
                                                        aria-label={t('editor_add_section_after')}
                                                    >
                                                        <ListPlus size={12} />
                                                    </button>
                                                ) : null}

                                                {canQuickDeleteEmptySection ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => deleteSection(section, { skipConfirm: true })}
                                                        className="seo-section-header-icon-btn ml-1 border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-500/70 dark:bg-rose-900/30 dark:text-rose-200"
                                                        title={t('editor_delete_empty_section_hint')}
                                                        aria-label={t('editor_delete_empty_section')}
                                                    >
                                                        <Trash2 size={12} />
                                                    </button>
                                                ) : null}

                                                {!section.isIntro && canGenerateFeaturedSnippet ? (
                                                    <button
                                                        type="button"
                                                        onClick={() => requestGenerateFeaturedSnippetAfterSection(section)}
                                                        disabled={featuredSnippetGenerating}
                                                        className="seo-section-header-icon-btn ml-1 border-fuchsia-300 bg-fuchsia-50 text-fuchsia-700 hover:bg-fuchsia-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-fuchsia-500/70 dark:bg-fuchsia-900/30 dark:text-fuchsia-200"
                                                        title={t('editor_generate_featured_snippet')}
                                                        aria-label={t('editor_generate_featured_snippet')}
                                                    >
                                                        <Sparkles size={12} />
                                                    </button>
                                                ) : null}

                                                {!section.isIntro && stats.hasEmptyImageSrc ? (
                                                    <span
                                                        className="ml-2 inline-flex items-center gap-1 rounded border border-rose-300 bg-rose-50 px-1.5 py-0.5 text-[11px] font-medium text-rose-700 dark:border-rose-500/70 dark:bg-rose-900/30 dark:text-rose-200"
                                                        title={t('editor_section_has_empty_src')}
                                                    >
                                                        <AlertTriangle size={11} />
                                                        <span>{t('editor_empty_src_count', { count: stats.emptyImageSrcCount })}</span>
                                                    </span>
                                                ) : null}

                                                <span className="ml-2 inline-block align-middle">
                                                    {visibleBlockIds.length} block
                                                </span>
                                            </span>
                                        </header>

                                        {!isCollapsed ? (
                                            <div className="space-y-3 p-3">
                                                {visibleBlockIds.map((blockId) => {
                                                    const block = blockById.get(blockId);
                                                    if (!block) {
                                                        return null;
                                                    }

                                                    const isActive = activeBlockId === block.id;
                                                    const showInsert = isActive && !tempMerge;
                                                    // Allow move past H3/table within section; only section H2 stays immovable here.
                                                    const showMoveButtons = showInsert && !sectionHeadingBlockIds.has(block.id);
                                                    const canMovePrevSection = sectionIndex > 0;
                                                    const canMoveNextSection = sectionIndex < editorSections.length - 1;
                                                    const editorWritable = !sessionReadOnly
                                                        && !window.__SEO_EDITOR_READ_ONLY__
                                                        && canMutateEditor();
                                                    const withinMove = withinSectionMoveAvailability(visibleBlockIds, block.id);
                                                    const canMoveUpWithinSection = editorWritable && withinMove.canMoveUp;
                                                    const canMoveDownWithinSection = editorWritable && withinMove.canMoveDown;
                                                    const handleMovePrevSection = () => moveBlockToSection(block.id, 'prev');
                                                    const handleMoveNextSection = () => moveBlockToSection(block.id, 'next');
                                                    const handleMoveUpWithinSection = () => {
                                                        executeEditorCommand('move_block_within_section', {
                                                            sectionId: section.id,
                                                            blockId: block.id,
                                                            direction: 'up',
                                                        }, { notifyOnFailure: false });
                                                    };
                                                    const handleMoveDownWithinSection = () => {
                                                        executeEditorCommand('move_block_within_section', {
                                                            sectionId: section.id,
                                                            blockId: block.id,
                                                            direction: 'down',
                                                        }, { notifyOnFailure: false });
                                                    };
                                                    const jumpTarget = outlineJumpTarget?.blockId === block.id
                                                        ? outlineJumpTarget
                                                        : null;

                                                    return (
                                                        <div
                                                            key={`${block.id}-${block.editorEpoch ?? '0'}`}
                                                            data-seo-block-id={block.id}
                                                            className={`seo-editor-block-slot ${isActive ? 'is-active' : ''}`}
                                                        >
                                                            {showInsert ? (
                                                                <>
                                                                    <BlockInsertBar
                                                                        position="before"
                                                                        open={
                                                                            insertMenu?.blockId === block.id &&
                                                                            insertMenu?.position === 'before'
                                                                        }
                                                                        onToggle={() => toggleInsertMenu(block.id, 'before')}
                                                                        showMoveButtons={showMoveButtons}
                                                                        canMovePrevSection={canMovePrevSection}
                                                                        canMoveNextSection={canMoveNextSection}
                                                                        canMoveUpWithinSection={canMoveUpWithinSection}
                                                                        canMoveDownWithinSection={canMoveDownWithinSection}
                                                                        onMovePrevSection={handleMovePrevSection}
                                                                        onMoveNextSection={handleMoveNextSection}
                                                                        onMoveUpWithinSection={handleMoveUpWithinSection}
                                                                        onMoveDownWithinSection={handleMoveDownWithinSection}
                                                                    />
                                                                    {insertMenu?.blockId === block.id &&
                                                                    insertMenu?.position === 'before' ? (
                                                                        <BlockInsertMenuBar
                                                                            faqShortcodeDisabled={articleHasFaqShortcode(blocks)}
                                                                            imageInsertDisabled={section.isIntro}
                                                                            onClose={() => setInsertMenu(null)}
                                                                            onInsert={(type) =>
                                                                                insertBlockRelative(block.id, 'before', type)
                                                                            }
                                                                        />
                                                                    ) : null}
                                                                </>
                                                            ) : null}

                                                            <BlockEditor
                                                                block={block}
                                                                sectionId={section.id}
                                                                articleId={articleId}
                                                                siteId={siteId}
                                                                supportsProductGallery={supportsProductGallery}
                                                                isActive={isActive}
                                                                isHiddenInMerge={
                                                                    Boolean(
                                                                        tempMerge &&
                                                                            tempMerge.rangeIds.includes(block.id) &&
                                                                            block.id !== tempMerge.anchorId,
                                                                    )
                                                                }
                                                                canShiftMerge={Boolean(activeBlockId && activeBlockId !== block.id)}
                                                                onActivate={() => activateBlock(block.id)}
                                                                onShiftMerge={startTempMerge}
                                                                displayContent={
                                                                    tempMerge &&
                                                                    activeBlockId === block.id &&
                                                                    block.id === tempMerge.anchorId
                                                                        ? mergedDisplay
                                                                        : undefined
                                                                }
                                                                suppressBlockUpdate={Boolean(
                                                                    tempMerge &&
                                                                        activeBlockId === block.id &&
                                                                        block.id === tempMerge.anchorId,
                                                                )}
                                                                onUpdate={(newContent, imageData) =>
                                                                    updateBlockContent(block.id, newContent, imageData)
                                                                }
                                                                onRegisterFlush={
                                                                    isActive ? registerBlockFlush : undefined
                                                                }
                                                                onRegisterEditor={
                                                                    isActive
                                                                        ? (editor) => registerBlockEditor(block.id, editor)
                                                                        : undefined
                                                                }
                                                                setGlobalEditor={setGlobalEditor}
                                                                panelFaqs={panelFaqs}
                                                                faqCount={faqCount}
                                                                canGenerateFaq={canGenerateFaq}
                                                                onEditFaq={openFaqModule}
                                                                onCreateFaq={createFaqFromShortcode}
                                                                outlineHeadingsLocked={false}
                                                                isSectionHeadingBlock={sectionHeadingBlockIds.has(block.id)}
                                                                onOutlineHeadingCommand={handleOutlineHeadingFromEditor}
                                                                onArmOutsideClickGuard={armBlockOutsideClickGuard}
                                                                onDelete={() => deleteBlock(block.id)}
                                                                canDeleteBlock={blocks.length > 1}
                                                                editable={!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__}
                                                                focusHeadingIndex={jumpTarget?.headingIndex ?? null}
                                                                focusHeadingToken={jumpTarget?.token ?? 0}
                                                            />

                                                            {showInsert ? (
                                                                <>
                                                                    <BlockInsertBar
                                                                        position="after"
                                                                        open={
                                                                            insertMenu?.blockId === block.id &&
                                                                            insertMenu?.position === 'after'
                                                                        }
                                                                        onToggle={() => toggleInsertMenu(block.id, 'after')}
                                                                        showMoveButtons={showMoveButtons}
                                                                        canMovePrevSection={canMovePrevSection}
                                                                        canMoveNextSection={canMoveNextSection}
                                                                        canMoveUpWithinSection={canMoveUpWithinSection}
                                                                        canMoveDownWithinSection={canMoveDownWithinSection}
                                                                        onMovePrevSection={handleMovePrevSection}
                                                                        onMoveNextSection={handleMoveNextSection}
                                                                        onMoveUpWithinSection={handleMoveUpWithinSection}
                                                                        onMoveDownWithinSection={handleMoveDownWithinSection}
                                                                    />
                                                                    {insertMenu?.blockId === block.id &&
                                                                    insertMenu?.position === 'after' ? (
                                                                        <BlockInsertMenuBar
                                                                            faqShortcodeDisabled={articleHasFaqShortcode(blocks)}
                                                                            imageInsertDisabled={section.isIntro}
                                                                            onClose={() => setInsertMenu(null)}
                                                                            onInsert={(type) =>
                                                                                insertBlockRelative(block.id, 'after', type)
                                                                            }
                                                                        />
                                                                    ) : null}
                                                                </>
                                                            ) : null}
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        ) : null}
                                    </section>
                                );
                            })
                        )}
                    </div>
                </div>
            </div>
            {generateImageModalOpen ? (
                <Suspense fallback={null}>
                    <GenerateImageModal
                        open={generateImageModalOpen}
                        onClose={() => setGenerateImageModalOpen(false)}
                        onSubmit={submitGenerateImageFromModal}
                        initialPrompt={generateImageModalPrompt}
                        initialLoaiSanPhamCustom={generateImageModalInitialCustom}
                        mode={generateImageModalTarget === 'product-gallery' ? 'product-gallery' : 'editor'}
                        productCategoryOptions={productCategoryOptions}
                        articleId={articleId}
                        siteId={siteId}
                        productGalleryItems={productGalleryItems}
                        canaryProduct={isCanaryProduct}
                        parentChildAllowed={parentChildAllowed}
                        parentChildReason={parentChildReason}
                    />
                </Suspense>
            ) : null}
            {featuredSnippetPromptOpen ? (
                <Suspense fallback={null}>
                    <FeaturedSnippetPromptModal
                        open={featuredSnippetPromptOpen}
                        canGenerate={canGenerateFeaturedSnippet}
                        generating={featuredSnippetGenerating}
                        previewHtml={featuredSnippetPreviewHtml}
                        context={featuredSnippetPromptContext}
                        onClose={() => {
                            setFeaturedSnippetPromptOpen(false);
                            if (featuredSnippetTargetRef.current?.mode === 'prompt-preview'
                                || featuredSnippetTargetRef.current?.mode === 'prompt-insert') {
                                featuredSnippetTargetRef.current = null;
                            }
                        }}
                        onGenerate={() => {
                            void runFeaturedSnippetPromptGenerate();
                        }}
                        onConfirmInsert={confirmFeaturedSnippetPromptInsert}
                    />
                </Suspense>
            ) : null}
                </div>
            </div>

            <EditorHostApiProvider value={editorHostApi}>
                {sidebarNavRoot ? (
                    <EditorSidebarNavigation
                        runtime={getDefaultArticleEditorRuntime()}
                        rootEl={sidebarNavRoot}
                        contextRevision={runtimeContextRevision}
                        shellItems={SHELL_BOUNDARY_NAV_ITEMS}
                    />
                ) : null}
                <EditorSidebarPortalHost
                    runtime={getDefaultArticleEditorRuntime()}
                    activePanelId={activeHeavyModule}
                    portalRoots={assistantPortalRoots}
                    shells={editorPanelShells}
                    articleId={articleId}
                    siteId={siteId}
                    isPanelAllowed={(panelId) => {
                        if (panelId === 'reviews') {
                            return Boolean(isProductPost && showReviewsTab);
                        }
                        return true;
                    }}
                />
                {mediaPickerRoot ? (
                    <LazySharedMediaPicker
                        articleId={articleId}
                        siteId={siteId}
                        rootEl={mediaPickerRoot}
                        wordpressAvailable={Boolean(editorSettings?.wordpress_connected ?? true)}
                        articleDomain={siteDomainRef.current || siteDomain}
                    />
                ) : null}
            </EditorHostApiProvider>

            {draftChoiceModalOpen && draftRestoreOffer ? (
                <div className="seo-draft-restore-overlay" role="dialog" aria-modal="true">
                    <div className="seo-draft-restore-modal">
                        <h3 className="seo-draft-restore-modal__title">
                            {t('editor_draft_restore_title')}
                        </h3>
                        <p className="seo-draft-restore-modal__body">
                            {t('editor_draft_restore_body')}
                        </p>
                        <div className="seo-draft-restore-modal__actions">
                            <button
                                type="button"
                                className="seo-draft-restore-modal__btn seo-draft-restore-modal__btn--primary"
                                onClick={applyDraftRestore}
                            >
                                {t('editor_draft_restore_action_restore')}
                            </button>
                            <button
                                type="button"
                                className="seo-draft-restore-modal__btn"
                                onClick={keepServerOverDraft}
                            >
                                {t('editor_draft_restore_action_keep_server')}
                            </button>
                            <button
                                type="button"
                                className="seo-draft-restore-modal__btn seo-draft-restore-modal__btn--danger"
                                onClick={discardDraftRestore}
                            >
                                {t('editor_draft_restore_action_discard')}
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
