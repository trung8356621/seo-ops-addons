import {
    buildClientOutlineTree,
    flattenClientOutlineNodes,
    normalizeOutlineHeadingText,
    outlineHeadingFingerprint,
} from '../utils/articleEditorClientOutline';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { createArticleEditorUtilityScheduler } from '../utils/articleEditorUtilityScheduler';
import { fetchWordPressProductReviews } from '../utils/articleEditorApi';
import { isAbortError } from '../utils/articleEditorModules';
import { loadFeaturedImage } from '@media-addon/utils/articleFeaturedImageStorage.js';
import { loadProductAlbum, normalizeProductAlbumList } from '@media-addon/utils/articleProductAlbumStorage.js';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { outlineHeadingKey, resolveSupplementalImagesWithGallery } from '../utils/contentDocumentHelpers';
import { readCoreBootstrap } from '../utils/articleEditorPayloadAdapters';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
} from 'react';
import { useMediaEditor } from '@media-addon/editor/domains/media/useMediaEditor.js';

/**
 * useArticleEditorCoreState - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorCoreState({ activeHeavyModule, activeHeavyModuleRef, articleId, blocks, blocksRef, editorSettings, initialFaqs, initialPostImages, initialProductGallery, initialSupplementalImages, initialVirtualReviews, perfDebug, reviewsAbortRef, setAssistantPortalRoots, setMediaPickerRoot, supportsProductGallery }) {
    const [virtualReviews, setVirtualReviews] = useState(() =>
        Array.isArray(initialVirtualReviews) ? initialVirtualReviews : [],
    );
    const isProductPost = supportsProductGallery;
    const showReviewsTab = editorSettings?.show_reviews_tab !== false;
    const canQuickCreateReviews = editorSettings?.can_quick_create_reviews === true;
    const showConfigureReviewsLink = editorSettings?.show_configure_reviews_link === true;
    const quickCreateReviewsConfigUrl = String(editorSettings?.quick_create_reviews_config_url ?? '').trim();
    const canGenerateFeaturedSnippet = editorSettings?.can_generate_featured_snippet === true;
    const canGenerateOutlineHeading = editorSettings?.can_generate_outline_heading === true;
    useEffect(() => {
        const refreshAssistantPortalRoots = () => {
            setAssistantPortalRoots({
                seo: document.getElementById('seo-article-seo-assistant-root'),
                image: document.getElementById('seo-article-image-assistant-root'),
                reviews: document.getElementById('seo-article-reviews-assistant-root'),
                links: document.getElementById('seo-article-links-root'),
                faq: document.getElementById('seo-article-faq-root'),
                featured: document.getElementById('seo-article-featured-root'),
                aiChat: document.getElementById('seo-article-ai-chat-root'),
            });
            setMediaPickerRoot(document.getElementById('article-editor-media-picker-root'));
        };

        refreshAssistantPortalRoots();
        window.addEventListener('load', refreshAssistantPortalRoots);

        return () => window.removeEventListener('load', refreshAssistantPortalRoots);
    }, []);

    useEffect(() => {
        const onReviewsUpdated = (event) => {
            const detail = event?.detail ?? {};
            const next = detail.reviews ?? detail.params?.reviews;
            if (Array.isArray(next)) {
                setVirtualReviews(next);
            }
        };

        window.addEventListener('virtual-reviews-updated', onReviewsUpdated);

        return () => window.removeEventListener('virtual-reviews-updated', onReviewsUpdated);
    }, []);

    const [reviewsLoadWarning, setReviewsLoadWarning] = useState(null);
    const [reviewsLoading, setReviewsLoading] = useState(false);
    const reviewsPanelActive = activeHeavyModule === 'reviews';
    const imagesPanelActive = activeHeavyModule === 'images';
    const seoPanelActive = activeHeavyModule === 'seo';
    useEffect(() => {
        // Phase 3: fetch reviews only while Reviews is active; abort + drop heavy list on leave.
        if (!showReviewsTab || !isProductPost || !articleId || !reviewsPanelActive) {
            reviewsAbortRef.current?.abort();
            if (!reviewsPanelActive) {
                setVirtualReviews([]);
                setReviewsLoading(false);
            }
            return undefined;
        }

        const controller = new AbortController();
        reviewsAbortRef.current = controller;
        let cancelled = false;

        (async () => {
            setReviewsLoading(true);
            setReviewsLoadWarning(null);
            try {
                const result = await fetchWordPressProductReviews(articleId);
                if (cancelled || controller.signal.aborted || activeHeavyModuleRef.current !== 'reviews') {
                    return;
                }
                if (!result.success) {
                    setReviewsLoadWarning(String(result.message ?? 'Không thể tải đánh giá từ WordPress.'));
                    return;
                }

                const data = result.data ?? {};
                const remote = Array.isArray(data.reviews) ? data.reviews : [];
                const pending = Array.isArray(data.pending_local_reviews) ? data.pending_local_reviews : [];
                setVirtualReviews([...remote, ...pending]);
                if (data.warning) {
                    setReviewsLoadWarning(String(data.warning));
                }
            } catch (error) {
                if (isAbortError(error) || cancelled || controller.signal.aborted) {
                    return;
                }
                setReviewsLoadWarning(String(error?.message ?? 'Không thể tải đánh giá từ WordPress.'));
            } finally {
                if (!cancelled && !controller.signal.aborted) {
                    setReviewsLoading(false);
                }
            }
        })();

        return () => {
            cancelled = true;
            controller.abort();
            if (reviewsAbortRef.current === controller) {
                reviewsAbortRef.current = null;
            }
        };
    }, [articleId, isProductPost, showReviewsTab, reviewsPanelActive]);

    const refreshVirtualReviews = useCallback(async () => {
        if (!articleId || !isProductPost) {
            return callEditArticleLivewire('refreshVirtualReviewsForEditor');
        }
        setReviewsLoading(true);
        try {
            const result = await fetchWordPressProductReviews(articleId);
            if (!result.success) {
                setReviewsLoadWarning(String(result.message ?? 'Không thể tải đánh giá từ WordPress.'));
                return [];
            }
            const data = result.data ?? {};
            const remote = Array.isArray(data.reviews) ? data.reviews : [];
            const pending = Array.isArray(data.pending_local_reviews) ? data.pending_local_reviews : [];
            const merged = [...remote, ...pending];
            setVirtualReviews(merged);
            setReviewsLoadWarning(data.warning ? String(data.warning) : null);
            return merged;
        } catch (error) {
            setReviewsLoadWarning(String(error?.message ?? 'Không thể tải đánh giá từ WordPress.'));
            return [];
        } finally {
            setReviewsLoading(false);
        }
    }, [articleId, isProductPost]);

    const generateQuickPostReviews = useCallback(
        () => callEditArticleLivewire('generateQuickPostReviews'),
        [],
    );

    const [saveStatus, setSaveStatus] = useState('saved');
    const [analyzing, setAnalyzing] = useState(false);
    const [imageRenameBusy, setImageRenameBusy] = useState(false);
    const [imageRenameBusyCount, setImageRenameBusyCount] = useState(0);
    const [quickFixSlugAllBusy, setQuickFixSlugAllBusy] = useState(false);
    const [imagesReloadKey, setImagesReloadKey] = useState(0);
    const [imagesTabJumpTarget, setImagesTabJumpTarget] = useState(null);
    const [outlineHasSavedHeadings, setOutlineHasSavedHeadings] = useState(false);
    const [outlineHeadingCommand, setOutlineHeadingCommand] = useState(null);
    const [outlineTreeSync, setOutlineTreeSync] = useState(null);
    const [sectionTitleEditRequest, setSectionTitleEditRequest] = useState(null);
    const [outlineHeadingKeys, setOutlineHeadingKeys] = useState(() => new Set());
    const [clientOutline, setClientOutline] = useState(() => []);
    const outlineFingerprintRef = useRef('');
    const utilitySchedulerRef = useRef(null);
    if (utilitySchedulerRef.current == null) {
        utilitySchedulerRef.current = createArticleEditorUtilityScheduler({
            perfDebug: Boolean(perfDebug || editorSettings?.perf_debug),
        });
    }
    const outlineHeadingIdsByBlockIdRef = useRef(new Map());
    const outlineHeadingIdsByKeyRef = useRef(new Map());
    const outlineAppendInflightRef = useRef(new Set());
    const outlineAppendDoneRef = useRef(new Set());
    const [insertMenu, setInsertMenu] = useState(null);
    const [collapsedSectionIds, setCollapsedSectionIds] = useState({});
    // Media domain SoT (Hard Cutover): featured/gallery/post/supplemental images now live
    // in editor/domains/media/state.js instead of shell useState. Hydrate the module store
    // once per mount (ref-guarded — mirrors the old useState lazy-initializer timing so the
    // first paint is never a flash of empty state) then read reactively via useMediaEditor().
    const mediaDomainHydratedRef = useRef(false);
    if (!mediaDomainHydratedRef.current) {
        mediaDomainHydratedRef.current = true;
        const initialPostImagesValue = Array.isArray(initialPostImages) ? initialPostImages : [];
        const initialSupplementalValue = (() => {
            const initial = Array.isArray(initialSupplementalImages) ? initialSupplementalImages : [];
            if (!supportsProductGallery || !articleId) {
                return initial;
            }

            const album = loadProductAlbum(articleId);

            return resolveSupplementalImagesWithGallery(initial, album, articleId, supportsProductGallery);
        })();
        const initialGalleryValue = normalizeProductAlbumList(
            initialProductGallery.length > 0 ? initialProductGallery : loadProductAlbum(articleId),
        );
        mediaActions.setPostImages(initialPostImagesValue);
        mediaActions.setSupplementalImages(initialSupplementalValue);
        mediaActions.setGallery(initialGalleryValue);
        mediaActions.setFeaturedHealthSnapshot(loadFeaturedImage(articleId));
    }
    const {
        featuredHealthSnapshot,
        gallery: productGalleryItems,
        postImages,
        supplementalImages,
    } = useMediaEditor();
    const supplementalImagesRef = useRef(supplementalImages);
    supplementalImagesRef.current = supplementalImages;
    const publishEditorImagesCatalogRef = useRef(() => {});
    const postImagesRef = useRef(postImages);
    postImagesRef.current = postImages;
    const [quickReplaceFind, setQuickReplaceFind] = useState('');
    const [quickReplaceValue, setQuickReplaceValue] = useState('');
    const [editorSearchMatchCount, setEditorSearchMatchCount] = useState(null);
    const panelFaqsRef = useRef(Array.isArray(initialFaqs) ? initialFaqs : []);
    const [panelFaqs, setPanelFaqs] = useState(Array.isArray(initialFaqs) ? initialFaqs : []);
    panelFaqsRef.current = panelFaqs;
    const [faqCount, setFaqCount] = useState(() => {
        const core = readCoreBootstrap();
        const fromCore = Number(core?.faqCount ?? core?.faq_count ?? 0);
        return Number.isFinite(fromCore) ? fromCore : 0;
    });
    const canGenerateFaq = editorSettings?.can_generate_faq === true;
    const [featuredSnippetPromptOpen, setFeaturedSnippetPromptOpen] = useState(false);
    const [featuredSnippetPreviewHtml, setFeaturedSnippetPreviewHtml] = useState('');
    const [featuredSnippetPromptContext, setFeaturedSnippetPromptContext] = useState(null);
    const [seoAnalyzeError, setSeoAnalyzeError] = useState(null);
    const pendingFaqGenerateRef = useRef(false);
    const pendingQuickFixKeywordRef = useRef('');
    const pendingLocalRenameResultsRef = useRef([]);
    const pendingLocalRenameQueueRef = useRef([]);
    const pendingWpRenameRequestRef = useRef([]);
    const slugRenameManagedByBatchRef = useRef(false);
    const generateImageTargetRef = useRef('editor');
    const [generateImageModalOpen, setGenerateImageModalOpen] = useState(false);
    const [generateImageModalPrompt, setGenerateImageModalPrompt] = useState('');
    const [generateImageModalTarget, setGenerateImageModalTarget] = useState('editor');
    const [generateImageModalInitialCustom, setGenerateImageModalInitialCustom] = useState('');
    const [featuredSnippetGenerating, setFeaturedSnippetGenerating] = useState(false);
    const featuredSnippetTargetRef = useRef(null);

    // Phase 4: outline status derived from client blocks — no GET /outline on open/interact.
    useEffect(() => {
        const scheduler = utilitySchedulerRef.current;
        return () => {
            scheduler?.destroy();
            utilitySchedulerRef.current = null;
        };
    }, []);

    useEffect(() => {
        const scheduler = utilitySchedulerRef.current;
        if (!scheduler) {
            return undefined;
        }

        scheduler.bumpVersion();
        scheduler.schedule({
            id: 'outline',
            debounceMs: 400,
            priority: 'idle',
            run: ({ version, signal }) => {
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                const nextBlocks = blocksRef.current;
                const fingerprint = outlineHeadingFingerprint(nextBlocks);
                if (fingerprint === outlineFingerprintRef.current) {
                    return;
                }
                outlineFingerprintRef.current = fingerprint;
                const tree = buildClientOutlineTree(nextBlocks);
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                setClientOutline(tree);
                const flat = flattenClientOutlineNodes(tree);
                setOutlineHasSavedHeadings(flat.length > 0);
                setOutlineHeadingKeys(
                    new Set(
                        flat.map((node) =>
                            outlineHeadingKey(Number(node.level), normalizeOutlineHeadingText(node.heading_text)),
                        ),
                    ),
                );
                const byKey = new Map();
                for (const node of flat) {
                    const level = Number(node?.level ?? 0);
                    const text = normalizeOutlineHeadingText(node?.heading_text);
                    if (level >= 2 && text !== '' && node?.id != null) {
                        byKey.set(outlineHeadingKey(level, text), node.id);
                    }
                    if (node?.block_id) {
                        outlineHeadingIdsByBlockIdRef.current.set(String(node.block_id), node.id);
                    }
                }
                outlineHeadingIdsByKeyRef.current = byKey;
            },
        });

        return undefined;
    }, [blocks]);

    return { analyzing, canGenerateFaq, canGenerateFeaturedSnippet, canGenerateOutlineHeading, canQuickCreateReviews, clientOutline, collapsedSectionIds, editorSearchMatchCount, faqCount, featuredHealthSnapshot, featuredSnippetGenerating, featuredSnippetPreviewHtml, featuredSnippetPromptContext, featuredSnippetPromptOpen, featuredSnippetTargetRef, generateImageModalInitialCustom, generateImageModalOpen, generateImageModalPrompt, generateImageModalTarget, generateImageTargetRef, generateQuickPostReviews, imageRenameBusy, imageRenameBusyCount, imagesReloadKey, imagesTabJumpTarget, insertMenu, isProductPost, outlineAppendDoneRef, outlineAppendInflightRef, outlineFingerprintRef, outlineHasSavedHeadings, outlineHeadingCommand, outlineHeadingIdsByBlockIdRef, outlineHeadingIdsByKeyRef, outlineHeadingKeys, outlineTreeSync, panelFaqs, panelFaqsRef, pendingFaqGenerateRef, pendingLocalRenameQueueRef, pendingLocalRenameResultsRef, pendingQuickFixKeywordRef, pendingWpRenameRequestRef, postImagesRef, productGalleryItems, publishEditorImagesCatalogRef, quickCreateReviewsConfigUrl, quickFixSlugAllBusy, quickReplaceFind, quickReplaceValue, refreshVirtualReviews, reviewsLoadWarning, reviewsLoading, saveStatus, sectionTitleEditRequest, seoAnalyzeError, seoPanelActive, setAnalyzing, setClientOutline, setCollapsedSectionIds, setEditorSearchMatchCount, setFaqCount, setFeaturedSnippetGenerating, setFeaturedSnippetPreviewHtml, setFeaturedSnippetPromptContext, setFeaturedSnippetPromptOpen, setGenerateImageModalInitialCustom, setGenerateImageModalOpen, setGenerateImageModalPrompt, setGenerateImageModalTarget, setImageRenameBusy, setImageRenameBusyCount, setImagesReloadKey, setImagesTabJumpTarget, setInsertMenu, setOutlineHasSavedHeadings, setOutlineHeadingCommand, setOutlineHeadingKeys, setOutlineTreeSync, setPanelFaqs, setQuickFixSlugAllBusy, setQuickReplaceFind, setQuickReplaceValue, setSaveStatus, setSectionTitleEditRequest, setSeoAnalyzeError, showConfigureReviewsLink, showReviewsTab, slugRenameManagedByBatchRef, supplementalImages, supplementalImagesRef, utilitySchedulerRef, virtualReviews };
}
