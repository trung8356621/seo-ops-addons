import { AI_PLACEHOLDER_LOADING_URL } from '@media-addon/utils/seoMediaApi.js';
import { INLINE_WHITESPACE_CORRUPTION_CODE } from '../utils/inlineWhitespaceGuard';
import {
    INTRO_SECTION_ID,
    exportBlocksToHtml,
    parseHtmlToBlocks,
    stripLeadingH1FromHtml,
} from '../utils/contentDocumentHelpers';
import { buildEditorDocumentEnvelope } from '../utils/articleEditorDocument';
import { buildMergedEditorImagesForPicker, enrichBlocksWithPostImages, slugFromUrl } from '@media-addon/utils/articleImagesUtils.js';
import { isAssistantFocusStealTarget, syncAndFreezeInsertionContext } from '../utils/editorInsertionContext';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { renderImageFigure, withDefaultImageInsertAlign } from '@media-addon/utils/blockImageUtils.js';
import { resolveFullWordPressImageUrl } from '@wordpress-addon/utils/wordpressImageUrl.js';
import { saveDraft } from '../utils/articleEditorStorage';
import { t } from '../utils/i18n';
import { useEffect } from 'react';

/**
 * useArticleEditorExternalEventsBridge - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorExternalEventsBridge({ activeBlockId, activeBlockIdRef, analyzedBlocksRef, applyCompletedMediaToPlaceholder, applyCompletedMediaToProductGallery, applySeoAnalysisResult, articleId, articleTitle, assertNoLocalSlugFixBeforeWpSync, assertWritableDocumentNotWhitespaceCorrupted, blockEditorsRef, blockFlushRef, blockOutsideClickGuardUntilRef, blocks, blocksRef, clearAwaitingClientImagePlaceholders, clearMediaPolling, clearOutlineFocus, clearTempMerge, connectionHashRef, dismissedEditorImageMediaIdsRef, editorHostActionsRef, findImageBlockByMediaId, generateImageTargetRef, getExportHtml, globalEditor, initialPostImages, insertImageAfterBlock, insertVideoAfterBlock, isDismissedEditorImageMedia, lastSeoAnalysisRef, mediaPollTimersRef, networkRecovering, networkUnavailable, outlineHasSavedHeadings, panelFaqsRef, patchImageInBlocks, pendingAiMediaRef, placeProcessingImagePlaceholder, postImagesRef, publishEditorImagesCatalogRef, reconcileImagesTabWithBlocks, requestAnalyze, requestGenerateArticleImage, resolveAiRefBlockId, resolveArticleFaqsSnapshot, runLocalSeoAnalysis, saveStatus, scheduleAutosave, scheduleIdleSeoAnalysis, sectionByBlockId, seoDomain, seoMetaRef, setActiveBlockId, setArticleType, setBlocks, setFaqCount, setGlobalEditor, setImagesReloadKey, setInsertMenu, setPanelFaqs, setSaveStatus, setSavedSeoScore, setSeoStale, setSupportsProductGallery, skipNextAutosave, startMediaStatusPolling, supplementalImagesRef, tempMerge, tempMergeRef, updateBlockContent }) {
    useEffect(() => {
        const applyExtractedFaqsToEditor = (detail = {}) => {
            const html = stripLeadingH1FromHtml(detail?.editorHtml ?? detail?.editor_html ?? '');
            if (!html) {
                return;
            }

            skipNextAutosave.current = true;
            clearTempMerge();
            blockFlushRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            setBlocks(enrichBlocksWithPostImages(parseHtmlToBlocks(html), postImagesRef.current));
            saveDraft(articleId, connectionHashRef.current, { content: html });
            setSaveStatus('saved');
            setSeoStale(true);
        };
        editorHostActionsRef.current.applyExtractedFaqs = applyExtractedFaqsToEditor;

        const applyEditorHtml = (event) => {
            applyExtractedFaqsToEditor(event.detail ?? {});
        };

        const onRevisionRestore = (event) => {
            const html = stripLeadingH1FromHtml(event.detail?.content ?? event.detail?.html ?? '');
            if (!html) {
                return;
            }

            skipNextAutosave.current = true;
            clearTempMerge();
            blockFlushRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            const parsedBlocks = parseHtmlToBlocks(html);
            setBlocks(enrichBlocksWithPostImages(parsedBlocks, postImagesRef.current));
            saveDraft(articleId, connectionHashRef.current, { content: html });
            setSaveStatus('saved');
            setSeoStale(true);
        };

        const onCollectEditorHtml = (event) => {
            blockFlushRef.current?.();
            clearTempMerge();
            setActiveBlockId(null);
            setGlobalEditor(null);

            const detail =
                event.detail != null && typeof event.detail === 'object' && !Array.isArray(event.detail)
                    ? event.detail
                    : {};
            const target = detail.target ?? 'save';
            runLocalSeoAnalysis();
            window.dispatchEvent(
                new CustomEvent('editor-html-collected', {
                    detail: {
                        html: getExportHtml(),
                        target,
                        seoAnalysis: lastSeoAnalysisRef.current,
                    },
                }),
            );
        };

        window.addEventListener('collect-editor-html', onCollectEditorHtml);
        // Legacy extract-article-faqs → Livewire path removed for editor; use runFaqExtractFromToolbar.
        window.addEventListener('article-faqs-extracted', applyEditorHtml);
        window.addEventListener('seo-article-revision-restore', onRevisionRestore);

        const onPostImagesSynced = (event) => {
            const images = event.detail?.images;
            if (!Array.isArray(images)) {
                return;
            }
            mediaActions.setPostImages(images);
            postImagesRef.current = images;
            setBlocks((prev) => enrichBlocksWithPostImages(prev, images));
            setImagesReloadKey((key) => key + 1);
        };

        const onSupplementalImagesSynced = (event) => {
            const images = event.detail?.images;
            if (!Array.isArray(images)) {
                return;
            }
            mediaActions.setSupplementalImages(images);
        };

        window.addEventListener('article-post-images-synced', onPostImagesSynced);
        window.addEventListener('article-supplemental-images-synced', onSupplementalImagesSynced);

        const publishEditorImagesCatalog = ({ autoSync = false } = {}) => {
            const images = buildMergedEditorImagesForPicker(
                blocksRef.current,
                supplementalImagesRef.current,
            );
            window.dispatchEvent(
                new CustomEvent('seo-editor-images-catalog', {
                    detail: { images, tab: 'article', autoSync },
                }),
            );
        };

        publishEditorImagesCatalogRef.current = (autoSync = true) =>
            publishEditorImagesCatalog({ autoSync });

        const onRequestEditorImagesCatalog = () => publishEditorImagesCatalog();

        window.addEventListener('seo-request-editor-images-catalog', onRequestEditorImagesCatalog);

        const syncPanelFaqs = (event) => {
            const fromExtract = event.detail?.faqs;
            if (Array.isArray(fromExtract)) {
                panelFaqsRef.current = fromExtract;
                setPanelFaqs(fromExtract);
                setFaqCount(fromExtract.length);
                scheduleIdleSeoAnalysis();
            }
        };

        const syncPanelFaqsFromFaqEditor = (event) => {
            const rows = event.detail?.faqs;
            if (!Array.isArray(rows)) {
                return;
            }

            panelFaqsRef.current = rows;
            setPanelFaqs(rows);
            setFaqCount(rows.length);
            scheduleIdleSeoAnalysis();
        };

        window.addEventListener('article-faqs-extracted', syncPanelFaqs);
        window.addEventListener('article-faq-rows-changed', syncPanelFaqsFromFaqEditor);

        const handleFocusKeywordUpdated = (e) => {
            const keyword = e.detail?.focus_keyword ?? null;
            seoDomain.patch({
                focusKeyword: String(keyword ?? ''),
            });
            requestAnalyze();
        };

        const handleGoogleSerpPreviewUpdated = (event) => {
            const preview = event.detail?.preview ?? event.detail ?? {};
            seoMetaRef.current = {
                ...seoMetaRef.current,
                seoTitle: String(preview?.title ?? seoMetaRef.current.seoTitle ?? articleTitle ?? '').trim(),
                metaDescription: String(
                    preview?.description ?? seoMetaRef.current.metaDescription ?? '',
                ).trim(),
            };
            requestAnalyze();
        };

        const handleEditorSlugUpdated = (event) => {
            const slug = String(event.detail?.slug ?? event.detail?.article_slug ?? '').trim();
            if (slug === '') {
                return;
            }

            seoMetaRef.current = {
                ...seoMetaRef.current,
                slug,
            };
            requestAnalyze();
        };

        const handlePublishPostTypeChanged = (event) => {
            const nextType = String(event.detail?.postType ?? event.detail?.post_type ?? '').trim();
            if (nextType === '') {
                return;
            }

            const normalized = nextType.toLowerCase();
            const nextSupportsGallery = normalized === 'product' || normalized === 'e-commerce';
            setArticleType(nextType);
            setSupportsProductGallery(nextSupportsGallery);
            requestAnalyze();
        };

        const handleServerAnalyzeResult = (event) => {
            const result = event?.detail?.result;
            if (result && typeof result === 'object') {
                if (!Object.prototype.hasOwnProperty.call(result, 'violations')) {
                    requestAnalyze();
                    return;
                }
                applySeoAnalysisResult(result, 'saved');
                const score = Number(result.total_score ?? result.score ?? result.seo_score);
                if (Number.isFinite(score)) {
                    setSavedSeoScore(score);
                }
                setSeoStale(false);
                return;
            }
            requestAnalyze();
        };

        window.addEventListener('seo-focus-keyword-updated', handleFocusKeywordUpdated);
        window.addEventListener('google-serp-preview-updated', handleGoogleSerpPreviewUpdated);
        window.addEventListener('seo-editor-slug-updated', handleEditorSlugUpdated);
        window.addEventListener('seo-publish-post-type-changed', handlePublishPostTypeChanged);
        window.addEventListener('seo-editor-analyze-result', handleServerAnalyzeResult);

        const handleClickOutside = (e) => {
            if (Date.now() < blockOutsideClickGuardUntilRef.current) {
                return;
            }

            const activeId = String(activeBlockIdRef.current ?? '').trim();
            if (activeId !== '') {
                const activeSlot = e.target.closest(`[data-seo-block-id="${activeId}"]`);
                if (activeSlot) {
                    return;
                }
            }

            // Không đóng block nếu đang focus vào input/textarea bên trong block image hoặc picker
            const activeEl = document.activeElement;
            if (
                activeEl &&
                ['INPUT', 'TEXTAREA'].includes(activeEl.tagName) &&
                !activeEl.readOnly &&
                (activeEl.closest('.block-image-active') || activeEl.closest('.seo-image-block-picker'))
            ) {
                if (!e.target.closest('.block-image-active') && !e.target.closest('.seo-image-block-picker')) {
                    return;
                }
            }

            // Assistant sidebar / dock: never clear active editor context on click.
            if (isAssistantFocusStealTarget(e.target)) {
                return;
            }

            if (
                e.target.closest('.block-editor-active') ||
                e.target.closest('.block-image-active') ||
                e.target.closest('.seo-block-toolbar') ||
                e.target.closest('.seo-block-preview') ||
                e.target.closest('.seo-link-bubble') ||
                e.target.closest('.seo-block-images-panel') ||
                e.target.closest('.seo-ai-chat-panel') ||
                e.target.closest('.seo-ai-fab') ||
                e.target.closest('.wp-article-links-box') ||
                e.target.closest('.wp-article-links-keyword') ||
                e.target.closest('.seo-article-faq-panel') ||
                e.target.closest('.seo-faq-item') ||
                e.target.closest('.seo-faq-shortcode-block') ||
                e.target.closest('.omi-faq-editor-preview') ||
                e.target.closest('.seo-fmt-dropdown-menu') ||
                e.target.closest('.seo-block-insert-bar') ||
                e.target.closest('.seo-block-insert-trigger') ||
                e.target.closest('.seo-block-insert-menu') ||
                e.target.closest('.seo-editor-block-slot') ||
                e.target.closest('.seo-section-element-actions') ||
                e.target.closest('.seo-block-editor-resize') ||
                e.target.closest('.seo-image-block-picker') ||
                e.target.closest('.seo-image-block-picker__choice') ||
                e.target.closest('.seo-image-block-picker__input') ||
                e.target.closest('.seo-image-block-picker__textarea') ||
                e.target.closest('.seo-image-block-picker__btn') ||
                e.target.closest('.seo-image-block-picker__back') ||
                e.target.closest('.seo-image-meta-panel') ||
                e.target.closest('.block-editor-text-block') ||
                e.target.closest('.seo-image-toolbar') ||
                e.target.closest('.block-image-active') ||
                e.target.closest('.seo-block-image-empty-preview') ||
                e.target.closest('.seo-outline-panel') ||
                e.target.closest('.seo-article-editor-outline-rail') ||
                e.target.closest('.seo-article-media-modal') ||
                e.target.closest('.seo-generate-image-modal') ||
                e.target.closest('.seo-generate-image-modal-backdrop') ||
                e.target.tagName === 'INPUT' ||
                e.target.tagName === 'TEXTAREA' ||
                e.target.closest('[contenteditable]')
            ) {
                return;
            }

            setInsertMenu(null);

            if (tempMergeRef.current) {
                clearTempMerge();
                setActiveBlockId(null);
                setGlobalEditor(null);
                return;
            }

            blockFlushRef.current?.();
            activeBlockIdRef.current = null;
            setActiveBlockId(null);
            setGlobalEditor(null);
            if (outlineHasSavedHeadings) {
                clearOutlineFocus();
            }
        };

        const captureInsertionBeforeAssistantFocus = (event) => {
            if (!isAssistantFocusStealTarget(event.target)) {
                return;
            }
            syncAndFreezeInsertionContext({
                blockEditors: blockEditorsRef.current,
                activeBlockId: activeBlockIdRef.current,
                sectionByBlockId,
            });
        };

        const onFreezeInsertionContext = () => {
            syncAndFreezeInsertionContext({
                blockEditors: blockEditorsRef.current,
                activeBlockId: activeBlockIdRef.current,
                sectionByBlockId,
            });
        };

        document.addEventListener('mousedown', handleClickOutside);
        document.addEventListener('pointerdown', captureInsertionBeforeAssistantFocus, true);
        window.addEventListener('seo-assistant-freeze-insertion-context', onFreezeInsertionContext);

        const onImageGenerateRequest = (event) => {
            const blockId = event.detail?.blockId;
            const prompt = (event.detail?.prompt ?? '').trim();
            const mediaKind = String(event.detail?.mediaKind ?? 'image').toLowerCase() === 'video' ? 'video' : 'image';
            if (!blockId || !prompt) {
                return;
            }

            if (mediaKind === 'image' && sectionByBlockId.get(String(blockId)) === INTRO_SECTION_ID) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_intro'),
                            body: t('editor_intro_no_images'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            if (mediaKind === 'video') {
                window.dispatchEvent(
                    new CustomEvent('generate-article-video', {
                        detail: {
                            selectionText: '',
                            selectionHtml: '',
                            userBrief: prompt,
                            activeBlockId: blockId,
                            articleId,
                        },
                    }),
                );
                return;
            }

            window.dispatchEvent(
                new CustomEvent('generate-article-image', {
                    detail: {
                        selectionText: '',
                        selectionHtml: '',
                        userBrief: prompt,
                        activeBlockId: blockId,
                        articleId,
                    },
                }),
            );
        };

        window.addEventListener('seo-editor-image-generate-request', onImageGenerateRequest);

        const persistSelectedMediaBlock = (blockId, content, image, blockType = 'image') => {
            const nextBlocks = blocksRef.current.map((block) =>
                block.id === blockId
                    ? {
                          ...block,
                          type: blockType,
                          content,
                          image,
                      }
                    : block,
            );

            blocksRef.current = nextBlocks;
            setBlocks(nextBlocks);
            reconcileImagesTabWithBlocks(nextBlocks);

            if (articleId) {
                saveDraft(articleId, connectionHashRef.current, {
                    content: exportBlocksToHtml(nextBlocks),
                });
                setSaveStatus('saved');
            }
        };

        const syncWpPickerSelectionToImagesTab = (pickerTab) => {
            if (String(pickerTab ?? '').trim() !== 'original') {
                return;
            }

            setImagesReloadKey((key) => key + 1);
            publishEditorImagesCatalog({ autoSync: true });
        };

        const onEditorBlockImageSelected = (event) => {
            const blockId = String(event.detail?.blockId ?? '').trim();
            const rawUrl = (event.detail?.url ?? '').trim();
            const attachmentId = Number(event.detail?.attachmentId ?? 0);
            const mediaType = String(event.detail?.mediaType ?? event.detail?.media_type ?? 'image').toLowerCase();
            if (!blockId || !rawUrl) return;

            if (mediaType !== 'video' && sectionByBlockId.get(blockId) === INTRO_SECTION_ID) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_intro'),
                            body: t('editor_intro_no_images'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const url = resolveFullWordPressImageUrl(rawUrl);
            if (mediaType === 'video') {
                const video = {
                    src: url,
                    alt: '',
                    title: '',
                    slug: (event.detail?.slug ?? '').trim() || slugFromUrl(url) || undefined,
                    align: 'none',
                    mediaType: 'video',
                    wpAttachmentId: attachmentId > 0 ? attachmentId : undefined,
                    seoMediaId: Number(event.detail?.seoMediaId ?? event.detail?.id ?? 0) || undefined,
                    wpSrc: url,
                };
                const safeUrl = url
                    .replace(/&/g, '&amp;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#39;')
                    .replace(/</g, '&lt;');
                persistSelectedMediaBlock(
                    blockId,
                    `<figure class="wp-block-video"><video controls src="${safeUrl}"></video></figure>`,
                    video,
                    'video',
                );
                syncWpPickerSelectionToImagesTab(event.detail?.pickerTab);
                return;
            }

            const slug = (event.detail?.slug ?? '').trim() || slugFromUrl(url);
            const kw = String(window.__SEO_MAIN_KEYWORD__ ?? '').trim();
            const alt = kw || (event.detail?.alt ?? '').trim();
            const seoMediaId = Number(event.detail?.seoMediaId ?? event.detail?.id ?? 0);
            if (seoMediaId > 0) {
                dismissedEditorImageMediaIdsRef.current.delete(seoMediaId);
            }
            const image = withDefaultImageInsertAlign({
                src: url,
                alt,
                title: alt,
                wpAttachmentId: attachmentId > 0 ? attachmentId : undefined,
                seoMediaId: seoMediaId > 0 ? seoMediaId : undefined,
                slug: slug || undefined,
                wpSrc: url,
            });
            const html = renderImageFigure(image);
            persistSelectedMediaBlock(blockId, html, image, 'image');
            syncWpPickerSelectionToImagesTab(event.detail?.pickerTab);
        };

        window.addEventListener('editor-block-image-selected', onEditorBlockImageSelected);

        const onArticleAiImageGenerated = (event) => {
            const detail = event.detail != null && typeof event.detail === 'object' ? event.detail : {};
            const requestedBlockId = String(detail.activeBlockId ?? detail.active_block_id ?? '').trim();
            const url = String(detail.url ?? '').trim();
            const status = String(detail.status ?? '').toLowerCase();
            const mediaId = Number(detail.seoMediaId ?? detail.seo_media_id ?? 0);
            const target = String(detail.target ?? generateImageTargetRef.current ?? 'editor').trim() || 'editor';
            if (!url && status !== 'processing' && status !== 'pending') {
                return;
            }

            if (mediaId > 0 && isDismissedEditorImageMedia(mediaId)) {
                pendingAiMediaRef.current.delete(mediaId);
                clearMediaPolling(mediaId);

                return;
            }

            if (target === 'product-gallery') {
                if ((status === 'processing' || status === 'pending') && mediaId > 0) {
                    pendingAiMediaRef.current.set(mediaId, {
                        target: 'product-gallery',
                        mediaType: 'image',
                    });
                    startMediaStatusPolling(mediaId, 'image');
                    generateImageTargetRef.current = 'editor';
                    return;
                }

                if (status === 'completed' && mediaId > 0 && url && !url.includes('placeholder-loading')) {
                    const galleryItems = Array.isArray(detail.gallery_urls) && detail.gallery_urls.length > 0
                        ? detail.gallery_urls
                        : (Array.isArray(detail.galleryUrls) && detail.galleryUrls.length > 0
                            ? detail.galleryUrls
                            : null);
                    applyCompletedMediaToProductGallery(mediaId, url, galleryItems);
                    generateImageTargetRef.current = 'editor';
                }

                return;
            }

            const isProcessingStatus = status === 'processing' || status === 'pending';

            // Completed trước — không phụ thuộc refBlockId.
            if (status === 'completed' && mediaId > 0 && url && !url.includes('placeholder-loading')) {
                if (applyCompletedMediaToPlaceholder(mediaId, 'image', url)) {
                    return;
                }

                const existingCompleted = findImageBlockByMediaId(mediaId);
                if (existingCompleted) {
                    patchImageInBlocks(
                        existingCompleted.id,
                        {
                            src: url,
                            title: '',
                            alt: '',
                            isProcessing: false,
                            seoMediaId: mediaId,
                        },
                        true,
                    );
                    pendingAiMediaRef.current.delete(mediaId);
                    clearMediaPolling(mediaId);
                    setImagesReloadKey((k) => k + 1);
                    scheduleAutosave();
                }

                return;
            }

            // Processing: gắn awaiting client placeholder — không early-return vì thiếu refBlockId.
            if (isProcessingStatus && mediaId > 0) {
                const awaitingEntry = [...pendingAiMediaRef.current.entries()].find(
                    ([, value]) => value?.awaitingServer && value?.blockId,
                );
                if (awaitingEntry) {
                    const [clientKey, pending] = awaitingEntry;
                    pendingAiMediaRef.current.delete(clientKey);
                    patchImageInBlocks(
                        pending.blockId,
                        {
                            seoMediaId: mediaId,
                            isProcessing: true,
                            src: url || AI_PLACEHOLDER_LOADING_URL,
                        },
                        true,
                    );
                    pendingAiMediaRef.current.set(mediaId, {
                        blockId: pending.blockId,
                        mediaType: 'image',
                    });
                    startMediaStatusPolling(mediaId, 'image');
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                    );

                    return;
                }

                const existingBlock = findImageBlockByMediaId(mediaId);
                if (existingBlock) {
                    if (!pendingAiMediaRef.current.has(mediaId)) {
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: existingBlock.id,
                            mediaType: 'image',
                        });
                    }
                    startMediaStatusPolling(mediaId, 'image');
                    return;
                }

                if (pendingAiMediaRef.current.has(mediaId)) {
                    const pending = pendingAiMediaRef.current.get(mediaId);
                    const pendingBlockId = String(pending?.blockId ?? '').trim();
                    const hasPendingBlock = pendingBlockId
                        ? blocksRef.current.some((block) => block.id === pendingBlockId)
                        : false;

                    if (!hasPendingBlock) {
                        pendingAiMediaRef.current.delete(mediaId);
                        clearMediaPolling(mediaId);

                        return;
                    }

                    startMediaStatusPolling(mediaId, 'image');
                    return;
                }
            }

            const fallbackActiveBlockId = String(activeBlockIdRef.current ?? '').trim();
            const refBlockId = resolveAiRefBlockId(requestedBlockId || fallbackActiveBlockId);
            if (!refBlockId) {
                return;
            }

            if (isProcessingStatus && mediaId > 0) {
                const placeholderId = placeProcessingImagePlaceholder(refBlockId, url || AI_PLACEHOLDER_LOADING_URL, {
                    seoMediaId: mediaId,
                    isProcessing: true,
                });
                if (placeholderId) {
                    pendingAiMediaRef.current.set(mediaId, {
                        blockId: placeholderId,
                        mediaType: 'image',
                    });
                    startMediaStatusPolling(mediaId, 'image');
                }

                window.dispatchEvent(
                    new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                );

                return;
            }

            if (status === 'failed') {
                return;
            }

            // Legacy: đồng bộ URL ngay, không có mediaId (tránh chèn trùng khi đã có job processing).
            if (url && mediaId <= 0) {
                placeProcessingImagePlaceholder(refBlockId, url);
            }
        };

        const onArticleAiVideoGenerated = (event) => {
            const requestedBlockId = (event.detail?.activeBlockId ?? '').trim();
            const url = (event.detail?.url ?? '').trim();
            const status = String(event.detail?.status ?? '').toLowerCase();
            const mediaId = Number(event.detail?.seoMediaId ?? 0);

            if (!url) {
                return;
            }

            if (mediaId > 0 && isDismissedEditorImageMedia(mediaId)) {
                pendingAiMediaRef.current.delete(mediaId);
                clearMediaPolling(mediaId);

                return;
            }

            const fallbackActiveBlockId = String(activeBlockIdRef.current ?? '').trim();
            const refBlockId = resolveAiRefBlockId(requestedBlockId || fallbackActiveBlockId);
            if (!refBlockId) {
                return;
            }

            if (status === 'processing' && mediaId > 0) {
                if (pendingAiMediaRef.current.has(mediaId)) {
                    // Đảm bảo luôn có polling kể cả khi event "processing" đến lặp/khôi phục.
                    const pending = pendingAiMediaRef.current.get(mediaId);
                    const pendingBlockId = String(pending?.blockId ?? '').trim();
                    const hasPendingBlock = pendingBlockId
                        ? blocksRef.current.some((block) => block.id === pendingBlockId)
                        : false;

                    if (!hasPendingBlock) {
                        pendingAiMediaRef.current.delete(mediaId);
                        clearMediaPolling(mediaId);

                        return;
                    }

                    startMediaStatusPolling(mediaId, 'video');
                    return;
                }

                const placeholderId = insertImageAfterBlock(refBlockId, url, {
                    seoMediaId: mediaId,
                    isProcessing: true,
                });
                if (placeholderId) {
                    pendingAiMediaRef.current.set(mediaId, {
                        blockId: placeholderId,
                        mediaType: 'video',
                    });
                    startMediaStatusPolling(mediaId, 'video');
                }

                window.dispatchEvent(
                    new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                );

                return;
            }

            if (status === 'completed' && mediaId > 0 && applyCompletedMediaToPlaceholder(mediaId, 'video', url)) {
                return;
            }

            if (status === 'failed') {
                return;
            }

            if (status === 'completed' || status === 'processing' || status === 'pending') {
                return;
            }

            if (url) {
                insertVideoAfterBlock(refBlockId, url);
            }
        };

        window.addEventListener('article-ai-image-generated', onArticleAiImageGenerated);
        window.addEventListener('article-ai-video-generated', onArticleAiVideoGenerated);

        return () => {
            window.removeEventListener('article-ai-video-generated', onArticleAiVideoGenerated);
            window.removeEventListener('article-ai-image-generated', onArticleAiImageGenerated);
            window.removeEventListener('editor-block-image-selected', onEditorBlockImageSelected);
            window.removeEventListener('seo-editor-image-generate-request', onImageGenerateRequest);
            window.removeEventListener('collect-editor-html', onCollectEditorHtml);
            window.removeEventListener('article-faqs-extracted', applyEditorHtml);
            window.removeEventListener('seo-article-revision-restore', onRevisionRestore);
            window.removeEventListener('article-post-images-synced', onPostImagesSynced);
            window.removeEventListener('article-supplemental-images-synced', onSupplementalImagesSynced);
            window.removeEventListener('seo-request-editor-images-catalog', onRequestEditorImagesCatalog);
            window.removeEventListener('article-faqs-extracted', syncPanelFaqs);
            window.removeEventListener('article-faq-rows-changed', syncPanelFaqsFromFaqEditor);
            window.removeEventListener('seo-focus-keyword-updated', handleFocusKeywordUpdated);
            window.removeEventListener('google-serp-preview-updated', handleGoogleSerpPreviewUpdated);
            window.removeEventListener('seo-editor-slug-updated', handleEditorSlugUpdated);
            window.removeEventListener('seo-publish-post-type-changed', handlePublishPostTypeChanged);
            window.removeEventListener('seo-editor-analyze-result', handleServerAnalyzeResult);
            document.removeEventListener('mousedown', handleClickOutside);
            document.removeEventListener('pointerdown', captureInsertionBeforeAssistantFocus, true);
            window.removeEventListener('seo-assistant-freeze-insertion-context', onFreezeInsertionContext);
            for (const timer of mediaPollTimersRef.current.values()) {
                window.clearTimeout(timer);
            }
            mediaPollTimersRef.current.clear();
            pendingAiMediaRef.current.clear();
        };
    }, [
        activeBlockId,
        globalEditor,
        applyCompletedMediaToPlaceholder,
        applyCompletedMediaToProductGallery,
        findImageBlockByMediaId,
        patchImageInBlocks,
        placeProcessingImagePlaceholder,
        resolveAiRefBlockId,
        updateBlockContent,
        reconcileImagesTabWithBlocks,
        clearTempMerge,
        articleId,
        articleTitle,
        getExportHtml,
        initialPostImages,
        insertImageAfterBlock,
        insertVideoAfterBlock,
        requestAnalyze,
        scheduleIdleSeoAnalysis,
        runLocalSeoAnalysis,
        scheduleAutosave,
        startMediaStatusPolling,
        clearMediaPolling,
        isDismissedEditorImageMedia,
        clearOutlineFocus,
        outlineHasSavedHeadings,
    ]);

    useEffect(() => {
        window.__seoCollectEditorHeavyBundle = async ({
            renameImagesBeforeWpSync = false,
            validateLocalImageSlugsBeforeWpSync = false,
        } = {}) => {
            blockFlushRef.current?.();

            if (validateLocalImageSlugsBeforeWpSync || renameImagesBeforeWpSync) {
                assertNoLocalSlugFixBeforeWpSync();
                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                    'Đang đồng bộ WordPress…',
                );
            }

            clearTempMerge();
            setActiveBlockId(null);
            setGlobalEditor(null);
            runLocalSeoAnalysis();

            const faqRows = resolveArticleFaqsSnapshot();
            const faqCollectorOpen = typeof window.__seoCollectArticleFaqs === 'function';
            // Phase 2: đừng nhét faqs:[] khi module FAQ chưa hydrate — sync sẽ wipe DB/WP.
            const faqsForBundle =
                faqCollectorOpen || (Array.isArray(faqRows) && faqRows.length > 0)
                    ? faqRows
                    : null;

            const exportHtml = getExportHtml();
            if (!assertWritableDocumentNotWhitespaceCorrupted(exportHtml)) {
                const err = new Error(t('editor_inline_whitespace_corruption_body'));
                err.code = INLINE_WHITESPACE_CORRUPTION_CODE;
                throw err;
            }
            const editorDocument = buildEditorDocumentEnvelope(
                blocksRef.current,
                blockEditorsRef.current,
            );

            return {
                articleId,
                html: exportHtml,
                client_rendered_html: exportHtml,
                editor_document: editorDocument,
                expected_editor_document_hash: window.__SEO_EDITOR_DOCUMENT_HASH__ || null,
                seoAnalysis: lastSeoAnalysisRef.current,
                faqs: faqsForBundle,
            };
        };

        window.__seoAssertEditorWhitespaceSafe = (html) => (
            assertWritableDocumentNotWhitespaceCorrupted(html)
        );

        return () => {
            delete window.__seoCollectEditorHeavyBundle;
            delete window.__seoAssertEditorWhitespaceSafe;
        };
    }, [
        articleId,
        assertWritableDocumentNotWhitespaceCorrupted,
        clearTempMerge,
        getExportHtml,
        assertNoLocalSlugFixBeforeWpSync,
        resolveArticleFaqsSnapshot,
        runLocalSeoAnalysis,
    ]);

    useEffect(() => {
        // Idle SEO auto-analysis (3–5s) — not 150ms loop. Typing cancels via bumpVersion.
        if (blocks.length === 0) return;
        if (skipNextAutosave.current) {
            skipNextAutosave.current = false;
            return;
        }
        if (blocks !== analyzedBlocksRef.current) {
            scheduleIdleSeoAnalysis();
        }
        scheduleAutosave();
    }, [blocks, scheduleAutosave, scheduleIdleSeoAnalysis]);

    useEffect(() => {
        const onGenerateImage = (event) => {
            void requestGenerateArticleImage(event.detail);
        };

        const onImageFailed = (event) => {
            if (String(event.detail?.type ?? '') !== 'image') {
                return;
            }

            clearAwaitingClientImagePlaceholders();
        };

        window.addEventListener('generate-article-image', onGenerateImage);
        window.addEventListener('article-ai-media-failed', onImageFailed);

        return () => {
            window.removeEventListener('generate-article-image', onGenerateImage);
            window.removeEventListener('article-ai-media-failed', onImageFailed);
        };
    }, [clearAwaitingClientImagePlaceholders, requestGenerateArticleImage]);

    const saveLabel =
        networkUnavailable
            ? t('editor_network_offline_unsaved')
            : networkRecovering
              ? t('editor_network_reconnected_saving')
              : saveStatus === 'saving'
                ? t('editor_saving_draft')
                : saveStatus === 'pending'
                  ? t('editor_draft_pending')
                  : t('editor_draft_saved_local');

    useEffect(() => {
        window.dispatchEvent(
            new CustomEvent('article-editor:save-status', {
                detail: {
                    status: networkUnavailable
                        ? 'offline'
                        : networkRecovering
                          ? 'recovering'
                          : saveStatus,
                    label: saveLabel,
                },
            }),
        );
    }, [saveStatus, saveLabel, networkUnavailable, networkRecovering]);

    const mergedDisplay =
        tempMerge && activeBlockId === tempMerge.anchorId ? tempMerge.mergedHtml : undefined;

    return { mergedDisplay, saveLabel };
}
