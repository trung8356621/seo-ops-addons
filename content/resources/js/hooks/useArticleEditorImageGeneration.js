import { AI_PLACEHOLDER_LOADING_URL, fetchArticleAiMediaJobs, fetchSeoMediaStatus, isAiPlaceholderLoadingSrc } from '@media-addon/utils/seoMediaApi.js';
import { appendProductAlbumItems, syncProductAlbumToServer } from '@media-addon/utils/articleProductAlbumStorage.js';
import { assertWritableEditorSession } from '../utils/editorSessionState';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { createEmptyImageBlock } from '../utils/contentDocumentHelpers';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import {
    normalizeBlocks,
    parseImageFromBlockContent,
    renderImageFigure,
    withDefaultImageInsertAlign,
} from '@media-addon/utils/blockImageUtils.js';
import { normalizeSectionHeadingBlockHtml } from '../utils/editorHtmlUtils';
import { saveDraft } from '../utils/articleEditorStorage';
import { setArticleAutosaveLock } from '../utils/articleAutosaveLock';
import { t } from '../utils/i18n';
import { useCallback, useEffect } from 'react';

/**
 * useArticleEditorImageGeneration - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorImageGeneration({ activeBlockIdRef, articleId, blocks, blocksRef, clearMediaPolling, commitActiveBlock, connectionHashRef, dismissedEditorImageMediaIdsRef, generateImageInFlightRef, getExportHtml, isDismissedEditorImageMedia, isIntroBlockId, mediaPollTimersRef, notifyIntroNoImages, patchImageInBlocks, pendingAiMediaRef, requestGenerateArticleImageRef, resumedArticleAiJobsRef, scheduleAutosave, setActiveBlockId, setBlocks, setGlobalEditor, setImagesReloadKey, setSaveStatus, tempMergeRef, updateBlocksWithoutHistory }) {
    const applyCompletedMediaToPlaceholder = useCallback((mediaId, mediaType, finalUrl) => {
        if (isDismissedEditorImageMedia(mediaId)) {
            pendingAiMediaRef.current.delete(mediaId);

            return false;
        }

        const trimmedUrl = String(finalUrl ?? '').trim();
        if (!trimmedUrl || trimmedUrl.includes('placeholder-loading')) {
            return false;
        }

        const pending = pendingAiMediaRef.current.get(mediaId);
        let targetBlockId = String(pending?.blockId ?? '').trim();

        if (!targetBlockId && mediaId > 0) {
            const byMediaId = blocksRef.current.find((block) => {
                const image = block?.image ?? null;
                const seoId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
                return seoId === mediaId && Boolean(image?.isProcessing);
            });
            targetBlockId = byMediaId?.id ?? '';
        }

        // Client placeholder chưa gắn seoMediaId — lấy entry awaitingServer.
        if (!targetBlockId) {
            const awaitingEntry = [...pendingAiMediaRef.current.entries()].find(
                ([, value]) => value?.awaitingServer && value?.blockId,
            );
            if (awaitingEntry) {
                const [clientKey, awaiting] = awaitingEntry;
                pendingAiMediaRef.current.delete(clientKey);
                targetBlockId = String(awaiting.blockId ?? '').trim();
            }
        }

        // Còn một block đang isProcessing — thay thế.
        if (!targetBlockId) {
            const processingBlocks = blocksRef.current.filter(
                (block) => block?.type === 'image' && Boolean(block?.image?.isProcessing),
            );
            if (processingBlocks.length === 1) {
                targetBlockId = processingBlocks[0].id;
            }
        }

        if (!targetBlockId) {
            return false;
        }

        if (mediaType === 'video') {
            const safeUrl = trimmedUrl
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;');

            updateBlocksWithoutHistory((prev) =>
                prev.map((block) =>
                    block.id === targetBlockId
                        ? {
                              ...block,
                              type: 'text',
                              image: undefined,
                              content: `<figure class="wp-block-video"><video controls src="${safeUrl}"></video></figure>`,
                          }
                        : block,
                ),
            );
        } else {
            patchImageInBlocks(
                targetBlockId,
                {
                    src: trimmedUrl,
                    title: '',
                    alt: '',
                    isProcessing: false,
                    seoMediaId: mediaId > 0 ? mediaId : undefined,
                },
                true,
            );
        }

        // Gỡ spinner trùng từ race insert (client data-URI + Livewire path).
        updateBlocksWithoutHistory((prev) =>
            prev.filter((block) => {
                if (block.id === targetBlockId || block?.type !== 'image') {
                    return true;
                }
                const image = block?.image ?? null;
                if (!image?.isProcessing && !isAiPlaceholderLoadingSrc(image?.src)) {
                    return true;
                }
                const seoId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
                if (mediaId > 0 && seoId === mediaId) {
                    return false;
                }
                // Leftover spinner chưa gắn seoMediaId.
                return seoId > 0;
            }),
        );

        pendingAiMediaRef.current.delete(mediaId);
        for (const [key, pending] of [...pendingAiMediaRef.current.entries()]) {
            if (pending?.awaitingServer || String(pending?.blockId ?? '') === targetBlockId) {
                pendingAiMediaRef.current.delete(key);
            }
        }
        clearMediaPolling?.(mediaId);
        window.dispatchEvent(new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }));
        setImagesReloadKey((k) => k + 1);
        scheduleAutosave();
        return true;
    }, [clearMediaPolling, isDismissedEditorImageMedia, patchImageInBlocks, scheduleAutosave, updateBlocksWithoutHistory]);

    const applyCompletedMediaToProductGallery = useCallback((mediaId, finalUrl, galleryItems = null) => {
        if (!articleId) {
            return false;
        }

        const trimmedUrl = String(finalUrl ?? '').trim();
        if (mediaId <= 0 || trimmedUrl === '') {
            return false;
        }

        // Luôn gắn ảnh gốc (chưa split) vào album — không dùng gallery_urls từ auto-split.
        const rawItems = [{ id: mediaId, url: trimmedUrl }];

        const appended = appendProductAlbumItems(articleId, rawItems);
        if (appended.length === 0) {
            return false;
        }

        mediaActions.markDirty();
        pendingAiMediaRef.current.delete(mediaId);
        window.dispatchEvent(new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }));

        const galleryUrls = appended
            .map((item) => ({
                id: Number(item?.id ?? 0),
                url: String(item?.url ?? '').trim(),
            }))
            .filter((item) => item.url !== '');

        window.dispatchEvent(
            new CustomEvent('article-ai-image-generated', {
                detail: {
                    target: 'product-gallery',
                    status: 'completed',
                    url: String(galleryUrls[0]?.url ?? finalUrl ?? '').trim(),
                    seoMediaId: mediaId,
                    gallery_urls: galleryUrls,
                    galleryUrls,
                },
            }),
        );

        syncProductAlbumToServer(articleId);

        return true;
    }, [articleId]);

    const AI_JOBS_POLL_MS = 5_000;
    const AI_JOBS_INITIAL_POLL_MS = 3_000;

    const startMediaStatusPolling = useCallback((mediaId, mediaType) => {
        if (!mediaId || mediaPollTimersRef.current.has(mediaId)) {
            return;
        }

        let attempt = 0;
        const maxAttempts = 72;

        const poll = async () => {
            attempt += 1;

            try {
                const payload = await fetchSeoMediaStatus(mediaId);
                const status = String(payload?.status ?? '').toLowerCase();
                const url = String(payload?.url ?? '').trim();

                if (status === 'completed' && url) {
                    if (url.includes('placeholder-loading')) {
                        clearMediaPolling(mediaId);
                        pendingAiMediaRef.current.delete(mediaId);
                        window.dispatchEvent(
                            new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                        );
                        window.dispatchEvent(
                            new CustomEvent('article-ai-media-failed', {
                                detail: {
                                    type: mediaType,
                                    message: payload?.error_message || t('editor_ai_failed'),
                                    seoMediaId: mediaId,
                                },
                            }),
                        );
                        setImagesReloadKey((k) => k + 1);
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: mediaType === 'video' ? t('editor_generate_video_failed') : t('editor_generate_image_failed'),
                                    body: payload?.error_message || t('editor_generate_image_no_result'),
                                    status: 'danger',
                                },
                            }),
                        );
                        return;
                    } else if (isDismissedEditorImageMedia(mediaId)) {
                        clearMediaPolling(mediaId);
                        pendingAiMediaRef.current.delete(mediaId);

                        return;
                    } else {
                        const pending = pendingAiMediaRef.current.get(mediaId);
                        if (pending?.target === 'product-gallery' && mediaType === 'image') {
                            const galleryItems = Array.isArray(payload?.gallery_urls) && payload.gallery_urls.length > 0
                                ? payload.gallery_urls
                                : null;
                            if (applyCompletedMediaToProductGallery(mediaId, url, galleryItems)) {
                                clearMediaPolling(mediaId);
                                return;
                            }
                        } else if (applyCompletedMediaToPlaceholder(mediaId, mediaType, url)) {
                            clearMediaPolling(mediaId);
                            return;
                        }
                        // completed nhưng chưa gắn được block — giữ poll, thử lại.
                    }
                }

                if (status === 'failed') {
                    clearMediaPolling(mediaId);
                    pendingAiMediaRef.current.delete(mediaId);
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-job-updated', { detail: { seoMediaId: mediaId } }),
                    );
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-failed', {
                            detail: {
                                type: mediaType,
                                message: payload?.error_message || t('editor_ai_failed'),
                                seoMediaId: mediaId,
                            },
                        }),
                    );
                    setImagesReloadKey((k) => k + 1);
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: mediaType === 'video' ? t('editor_generate_video_failed') : t('editor_generate_image_failed'),
                                body: payload?.error_message || t('editor_ai_failed'),
                                status: 'danger',
                            },
                        }),
                    );
                    return;
                }
            } catch {
                if (attempt >= maxAttempts) {
                    clearMediaPolling(mediaId);
                    return;
                }
            }

            if (attempt >= maxAttempts) {
                clearMediaPolling(mediaId);
                return;
            }

            const nextTimer = window.setTimeout(poll, AI_JOBS_POLL_MS);
            mediaPollTimersRef.current.set(mediaId, nextTimer);
        };

        const initialTimer = window.setTimeout(poll, AI_JOBS_INITIAL_POLL_MS);
        mediaPollTimersRef.current.set(mediaId, initialTimer);
    }, [
        applyCompletedMediaToPlaceholder,
        applyCompletedMediaToProductGallery,
        clearMediaPolling,
        isDismissedEditorImageMedia,
    ]);

    useEffect(() => {
        for (const block of blocks) {
            const image = block?.image ?? null;
            if (!image?.isProcessing) {
                continue;
            }

            const mediaId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
            if (mediaId <= 0) {
                continue;
            }

            if (!pendingAiMediaRef.current.has(mediaId)) {
                pendingAiMediaRef.current.set(mediaId, {
                    blockId: block.id,
                    mediaType: 'image',
                });
            }

            startMediaStatusPolling(mediaId, 'image');
        }
    }, [blocks, startMediaStatusPolling]);

    const insertImageAfterBlock = useCallback(
        (refBlockId, imageUrl, imagePatch = {}) => {
            if (!assertWritableEditorSession('image_insert_blocked')) {
                return '';
            }
            const url = (imageUrl ?? '').trim();
            if (!refBlockId || !url) {
                return '';
            }
            if (tempMergeRef.current) {
                return '';
            }
            if (isIntroBlockId(refBlockId)) {
                notifyIntroNoImages();

                return '';
            }

            commitActiveBlock();
            setArticleAutosaveLock('image-insert', true);
            try {
            const image = withDefaultImageInsertAlign({
                src: url,
                alt: '',
                title: '',
                ...imagePatch,
            });
            const html = renderImageFigure(image);
            const newBlock = {
                ...createEmptyImageBlock(),
                content: html,
                image,
            };

            const applyInsert = (prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) {
                    return prev;
                }
                const next = [...prev];
                const anchor = next[index];
                if (anchor?.type !== 'image' && anchor?.content) {
                    next[index] = {
                        ...anchor,
                        content: normalizeSectionHeadingBlockHtml(anchor.content),
                    };
                }
                next.splice(index + 1, 0, newBlock);
                return normalizeBlocks(next);
            };

            if (image.isProcessing) {
                updateBlocksWithoutHistory(applyInsert);
            } else {
                setBlocks(applyInsert);
            }

            setActiveBlockId(newBlock.id);
            setGlobalEditor(null);
            setImagesReloadKey((k) => k + 1);
            return newBlock.id;
            } finally {
                queueMicrotask(() => setArticleAutosaveLock('image-insert', false));
            }
        },
        [commitActiveBlock, isIntroBlockId, notifyIntroNoImages, updateBlocksWithoutHistory],
    );

    const resolveAiRefBlockId = useCallback((blockId) => {
        const id = String(blockId ?? '').trim();
        if (id) {
            return id;
        }
        const list = blocksRef.current;
        if (!list?.length) {
            return '';
        }
        return list[list.length - 1].id;
    }, []);

    const findImageBlockByMediaId = useCallback((mediaId) => {
        const id = Number(mediaId ?? 0);
        if (id <= 0) {
            return null;
        }

        return (
            blocksRef.current.find((block) => {
                if (block?.type !== 'image') {
                    return false;
                }

                const blockMediaId = Number(block?.image?.seoMediaId ?? block?.image?.seo_media_id ?? 0);

                return blockMediaId === id;
            }) ?? null
        );
    }, []);

    const placeProcessingImagePlaceholder = useCallback(
        (refBlockId, imageUrl, imagePatch = {}) => {
            const url = (imageUrl ?? '').trim();
            const refId = resolveAiRefBlockId(refBlockId);
            if (!refId || !url) {
                return '';
            }
            if (tempMergeRef.current) {
                return '';
            }
            if (isIntroBlockId(refId)) {
                notifyIntroNoImages();

                return '';
            }

            commitActiveBlock();

            const kw = String(window.__SEO_MAIN_KEYWORD__ ?? '').trim();
            const patchMediaId = Number(imagePatch?.seoMediaId ?? imagePatch?.seo_media_id ?? 0);
            const existingPlaceholder = blocksRef.current.find((block) => {
                if (block?.type !== 'image') {
                    return false;
                }
                const current = block?.image ?? parseImageFromBlockContent(block?.content ?? '');
                if (!current?.src) {
                    return false;
                }
                const currentMediaId = Number(current?.seoMediaId ?? current?.seo_media_id ?? 0);
                if (patchMediaId > 0 && currentMediaId === patchMediaId) {
                    return true;
                }
                if (!current?.isProcessing) {
                    return false;
                }
                // Match mọi AI spinner — data-URI local và path /assets/...placeholder-loading là cùng 1 placeholder.
                if (isAiPlaceholderLoadingSrc(current.src) || isAiPlaceholderLoadingSrc(url)) {
                    return true;
                }
                return String(current.src).trim() === url;
            });
            if (existingPlaceholder) {
                const baseImage = existingPlaceholder.image ?? parseImageFromBlockContent(existingPlaceholder.content) ?? {};
                const nextImage = withDefaultImageInsertAlign({
                    ...baseImage,
                    ...imagePatch,
                    src: url,
                    alt: kw || baseImage.alt || '',
                    title: kw || baseImage.title || '',
                });
                const nextHtml = renderImageFigure(nextImage);
                updateBlocksWithoutHistory((prev) =>
                    prev.map((block) =>
                        block.id === existingPlaceholder.id
                            ? {
                                  ...block,
                                  type: 'image',
                                  content: nextHtml,
                                  image: nextImage,
                                  pendingImagePrompt: undefined,
                              }
                            : block,
                    ),
                );
                return existingPlaceholder.id;
            }

            const image = withDefaultImageInsertAlign({
                src: url,
                alt: kw,
                title: kw,
                ...imagePatch,
            });
            const html = renderImageFigure(image);

            const refBlock = blocksRef.current.find((b) => b.id === refId);
            const refSrc = String(
                refBlock?.image?.src ?? parseImageFromBlockContent(refBlock?.content ?? '')?.src ?? '',
            ).trim();
            const isEmptyImageBlock = refBlock?.type === 'image' && !refSrc;

            if (isEmptyImageBlock) {
                updateBlocksWithoutHistory((prev) =>
                    prev.map((b) =>
                        b.id === refId
                            ? {
                                  ...b,
                                  content: html,
                                  image,
                                  pendingImagePrompt: undefined,
                              }
                            : b,
                    ),
                );
                setActiveBlockId(refId);
                setGlobalEditor(null);
                setImagesReloadKey((k) => k + 1);
                return refId;
            }

            return insertImageAfterBlock(refId, url, imagePatch);
        },
        [
            commitActiveBlock,
            insertImageAfterBlock,
            isIntroBlockId,
            notifyIntroNoImages,
            resolveAiRefBlockId,
            updateBlocksWithoutHistory,
        ],
    );

    const clearAwaitingClientImagePlaceholders = useCallback(() => {
        for (const [key, pending] of [...pendingAiMediaRef.current.entries()]) {
            if (!pending?.awaitingServer || !pending?.blockId) {
                continue;
            }

            pendingAiMediaRef.current.delete(key);
            updateBlocksWithoutHistory((prev) => prev.filter((block) => block.id !== pending.blockId));
        }

        setImagesReloadKey((value) => value + 1);
    }, [updateBlocksWithoutHistory]);

    const requestGenerateArticleImage = useCallback(
        async (detail) => {
            const payload = detail != null && typeof detail === 'object' ? detail : {};
            const target = String(payload.target ?? 'editor').trim() || 'editor';
            const userBrief = String(payload.userBrief ?? '').trim();
            const selectionText = String(payload.selectionText ?? '').trim();
            const activeBlockIdFromPayload = String(payload.activeBlockId ?? '').trim();

            if (!userBrief && !selectionText && target !== 'product-gallery') {
                return;
            }

            if (generateImageInFlightRef.current) {
                window.dispatchEvent(new CustomEvent('article-ai-media-failed', {
                    detail: {
                        type: 'image',
                        message: t('editor_generate_image_busy'),
                    },
                }));

                return;
            }
            generateImageInFlightRef.current = true;

            if (target !== 'product-gallery') {
                const refBlockId = resolveAiRefBlockId(
                    activeBlockIdFromPayload || String(activeBlockIdRef.current ?? '').trim(),
                );
                if (refBlockId) {
                    commitActiveBlock();
                    const placeholderId = placeProcessingImagePlaceholder(refBlockId, AI_PLACEHOLDER_LOADING_URL, {
                        isProcessing: true,
                    });
                    if (placeholderId) {
                        pendingAiMediaRef.current.set(`client:${placeholderId}`, {
                            blockId: placeholderId,
                            mediaType: 'image',
                            awaitingServer: true,
                        });
                    }

                    if (articleId) {
                        setSaveStatus('saving');
                        saveDraft(articleId, connectionHashRef.current, {
                            content: getExportHtml(),
                        });
                        setSaveStatus('saved');
                    }
                }
            }

            setArticleAutosaveLock('generate-image-request', true);

            try {
                const livewireCall = callEditArticleLivewire(
                    'generateArticleImageFromEditor',
                    selectionText,
                    String(payload.selectionHtml ?? ''),
                    userBrief,
                    activeBlockIdFromPayload,
                    target,
                    Number.parseInt(String(payload.loaiSanPhamCategoryArticleId ?? 0), 10) || 0,
                    String(payload.loaiSanPhamCustom ?? '').trim(),
                    String(payload.galleryGenerationMode ?? 'sprite').trim() || 'sprite',
                );
                const result = await Promise.race([
                    livewireCall,
                    new Promise((_, reject) => {
                        window.setTimeout(() => {
                            reject(new Error(t('editor_generate_image_timeout')));
                        }, 90_000);
                    }),
                ]);

                if (result && typeof result === 'object' && result.ok === false) {
                    clearAwaitingClientImagePlaceholders();
                    const message = String(result.message ?? t('editor_generate_image_failed'));
                    const technical = String(result.technical_details ?? result.technicalDetails ?? '');
                    window.dispatchEvent(
                        new CustomEvent('article-ai-media-failed', {
                            detail: {
                                type: 'image',
                                message,
                                technicalDetails: technical,
                                classification: result.classification ?? null,
                                retryable: Boolean(result.retryable),
                            },
                        }),
                    );
                    // Không dùng window.alert — Filament Notification + modal/event đã hiển thị.
                } else if (target === 'product-gallery') {
                    const mediaId = Number(result?.seo_media_id ?? result?.seoMediaId ?? 0) || 0;
                    const executionId = String(
                        result?.gallery_execution_id ?? result?.galleryExecutionId ?? '',
                    ).trim();
                    const status = String(result?.status ?? 'processing').toLowerCase();
                    const processing =
                        status === 'processing' || status === 'pending' || status === 'queued';

                    if (processing && mediaId <= 0 && executionId === '') {
                        window.dispatchEvent(
                            new CustomEvent('article-ai-media-failed', {
                                detail: {
                                    type: 'image',
                                    message: String(
                                        result?.message ??
                                            result?.error_message ??
                                            t('editor_generate_image_failed'),
                                    ),
                                },
                            }),
                        );
                    } else {
                        window.dispatchEvent(
                            new CustomEvent('article-ai-image-generated', {
                                detail: {
                                    target: 'product-gallery',
                                    status,
                                    url: String(result?.url ?? '').trim(),
                                    seoMediaId: mediaId,
                                    seo_media_id: mediaId,
                                    gallery_execution_id: executionId,
                                    galleryExecutionId: executionId,
                                    supports_reference_image: Boolean(result?.supports_reference_image),
                                    resolved_model: String(result?.resolved_model ?? ''),
                                },
                            }),
                        );
                    }
                } else if (target !== 'product-gallery') {
                    // Không phụ thuộc Livewire event — gắn seoMediaId + poll từ return value / ai-jobs.
                    let mediaId = Number(result?.seo_media_id ?? result?.seoMediaId ?? 0);
                    let status = String(result?.status ?? 'processing').toLowerCase();
                    let resultUrl = String(result?.url ?? '').trim();

                    if (mediaId <= 0 && articleId) {
                        try {
                            const jobs = await fetchArticleAiMediaJobs(articleId);
                            const newest = (Array.isArray(jobs) ? jobs : []).find((job) => {
                                const jobStatus = String(job?.status ?? '').toLowerCase();
                                const jobType = String(job?.media_type ?? 'image').toLowerCase();
                                return jobType === 'image' && (jobStatus === 'processing' || jobStatus === 'completed');
                            });
                            if (newest) {
                                mediaId = Number(newest.id ?? 0);
                                status = String(newest.status ?? status).toLowerCase();
                                resultUrl = String(newest.url ?? resultUrl).trim();
                            }
                        } catch {
                            // ignore — event/poll path vẫn thử
                        }
                    }

                    if (mediaId > 0) {
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
                                    isProcessing: status !== 'completed',
                                    src:
                                        status === 'completed' && resultUrl && !resultUrl.includes('placeholder-loading')
                                            ? resultUrl
                                            : AI_PLACEHOLDER_LOADING_URL,
                                },
                                true,
                            );
                            pendingAiMediaRef.current.set(mediaId, {
                                blockId: pending.blockId,
                                mediaType: 'image',
                            });
                        } else {
                            const processingBlocks = blocksRef.current.filter(
                                (block) => block?.type === 'image' && Boolean(block?.image?.isProcessing),
                            );
                            const unbound = processingBlocks.find((block) => {
                                const seoId = Number(block?.image?.seoMediaId ?? block?.image?.seo_media_id ?? 0);
                                return seoId <= 0;
                            });
                            const targetBlock = unbound ?? (processingBlocks.length === 1 ? processingBlocks[0] : null);
                            if (targetBlock) {
                                patchImageInBlocks(
                                    targetBlock.id,
                                    {
                                        seoMediaId: mediaId,
                                        isProcessing: status !== 'completed',
                                        src:
                                            status === 'completed' && resultUrl && !resultUrl.includes('placeholder-loading')
                                                ? resultUrl
                                                : AI_PLACEHOLDER_LOADING_URL,
                                    },
                                    true,
                                );
                                pendingAiMediaRef.current.set(mediaId, {
                                    blockId: targetBlock.id,
                                    mediaType: 'image',
                                });
                            }
                        }

                        if (status === 'completed' && resultUrl && !resultUrl.includes('placeholder-loading')) {
                            applyCompletedMediaToPlaceholder(mediaId, 'image', resultUrl);
                        } else {
                            startMediaStatusPolling(mediaId, 'image');
                        }
                    }
                }
            } catch (error) {
                clearAwaitingClientImagePlaceholders();
                const message = error?.message ?? t('editor_generate_image_failed');
                window.dispatchEvent(
                    new CustomEvent('article-ai-media-failed', {
                        detail: {
                            type: 'image',
                            message,
                        },
                    }),
                );
                // Không dùng window.alert — tránh raw provider error; Notification/event đã đủ.
            } finally {
                generateImageInFlightRef.current = false;
                setArticleAutosaveLock('generate-image-request', false);
            }
        },
        [
            applyCompletedMediaToPlaceholder,
            articleId,
            clearAwaitingClientImagePlaceholders,
            commitActiveBlock,
            getExportHtml,
            patchImageInBlocks,
            placeProcessingImagePlaceholder,
            resolveAiRefBlockId,
            startMediaStatusPolling,
        ],
    );
    requestGenerateArticleImageRef.current = requestGenerateArticleImage;

    useEffect(() => {
        if (!articleId || resumedArticleAiJobsRef.current === articleId) {
            return undefined;
        }

        resumedArticleAiJobsRef.current = articleId;
        let cancelled = false;

        void (async () => {
            try {
                const jobs = await fetchArticleAiMediaJobs(articleId);
                if (cancelled) {
                    return;
                }

                for (const job of jobs) {
                    const mediaId = Number(job?.id ?? 0);
                    const status = String(job?.status ?? '').toLowerCase();
                    const mediaType = String(job?.media_type ?? 'image').toLowerCase();
                    const jobUrl = String(job?.url ?? '').trim();

                    if (mediaId <= 0) {
                        continue;
                    }

                    if (dismissedEditorImageMediaIdsRef.current.has(mediaId)) {
                        continue;
                    }

                    // Completed gần đây — thay placeholder đang spin (kể cả mất pending map).
                    if (
                        status === 'completed' &&
                        jobUrl &&
                        !jobUrl.includes('placeholder-loading') &&
                        mediaType !== 'video'
                    ) {
                        if (applyCompletedMediaToPlaceholder(mediaId, 'image', jobUrl)) {
                            continue;
                        }
                    }

                    if (status !== 'processing') {
                        continue;
                    }

                    if (pendingAiMediaRef.current.has(mediaId) || mediaPollTimersRef.current.has(mediaId)) {
                        startMediaStatusPolling(mediaId, mediaType === 'video' ? 'video' : 'image');
                        continue;
                    }

                    const existingBlock = findImageBlockByMediaId(mediaId);
                    if (existingBlock) {
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: existingBlock.id,
                            mediaType: mediaType === 'video' ? 'video' : 'image',
                        });
                        startMediaStatusPolling(mediaId, mediaType === 'video' ? 'video' : 'image');
                        continue;
                    }

                    // Placeholder client chưa gắn seoMediaId — bind job processing mới nhất.
                    const unboundProcessing = blocksRef.current.find((block) => {
                        if (block?.type !== 'image' || !block?.image?.isProcessing) {
                            return false;
                        }
                        const seoId = Number(block.image?.seoMediaId ?? block.image?.seo_media_id ?? 0);
                        return seoId <= 0;
                    });
                    if (unboundProcessing && mediaType !== 'video') {
                        patchImageInBlocks(
                            unboundProcessing.id,
                            {
                                seoMediaId: mediaId,
                                isProcessing: true,
                                src: jobUrl || AI_PLACEHOLDER_LOADING_URL,
                            },
                            true,
                        );
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: unboundProcessing.id,
                            mediaType: 'image',
                        });
                        startMediaStatusPolling(mediaId, 'image');
                        continue;
                    }

                    const editorBlockId = String(job?.editor_block_id ?? '').trim();
                    const refBlockId = resolveAiRefBlockId(editorBlockId);
                    if (!refBlockId) {
                        continue;
                    }

                    const placeholderUrl = jobUrl || AI_PLACEHOLDER_LOADING_URL;
                    const placeholderId =
                        mediaType === 'video'
                            ? insertImageAfterBlock(refBlockId, placeholderUrl, {
                                  seoMediaId: mediaId,
                                  isProcessing: true,
                              })
                            : placeProcessingImagePlaceholder(refBlockId, placeholderUrl, {
                                  seoMediaId: mediaId,
                                  isProcessing: true,
                              });

                    if (placeholderId) {
                        pendingAiMediaRef.current.set(mediaId, {
                            blockId: placeholderId,
                            mediaType: mediaType === 'video' ? 'video' : 'image',
                        });
                        startMediaStatusPolling(mediaId, mediaType === 'video' ? 'video' : 'image');
                    }
                }
            } catch {
                // Không chặn editor nếu API job tạm lỗi.
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [
        articleId,
        applyCompletedMediaToPlaceholder,
        findImageBlockByMediaId,
        insertImageAfterBlock,
        patchImageInBlocks,
        placeProcessingImagePlaceholder,
        resolveAiRefBlockId,
        startMediaStatusPolling,
    ]);

    return { applyCompletedMediaToPlaceholder, applyCompletedMediaToProductGallery, clearAwaitingClientImagePlaceholders, findImageBlockByMediaId, insertImageAfterBlock, placeProcessingImagePlaceholder, requestGenerateArticleImage, resolveAiRefBlockId, startMediaStatusPolling };
}
