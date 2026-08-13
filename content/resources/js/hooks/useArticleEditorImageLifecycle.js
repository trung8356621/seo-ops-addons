import { LINKS_RESCAN_REQUEST_EVENT } from '../utils/articleEditorModules';
import { articleImageRowsShareIdentity, resolveArticleImageRemoveTarget } from '@media-addon/utils/articleImagesUtils.js';
import { assertWritableEditorSession } from '../utils/editorSessionState';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { clearFeaturedImageStorage, saveFeaturedImage } from '@media-addon/utils/articleFeaturedImageStorage.js';
import { dispatchActiveBlockContext, exportBlocksToHtml } from '../utils/contentDocumentHelpers';
import { loadProductAlbum, removeProductAlbumItem } from '@media-addon/utils/articleProductAlbumStorage.js';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { buildAiMediaContextFromBlock, buildAiMediaContextFromSection } from '../utils/buildAiMediaContext';
import { openPanel } from '../editor/runtime/editorRuntimeNavigation';
import { parseImageFromBlockContent } from '@media-addon/utils/blockImageUtils.js';
import { scanExistingLinksCompat } from '../utils/existingLinkScanner';
import { scrollElementIntoViewIfNeeded } from '../utils/editorInsertionContext';
import { t } from '../utils/i18n';
import { useCallback, useEffect } from 'react';

/**
 * useArticleEditorImageLifecycle - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorImageLifecycle({ activeBlockId, articleId, articleTitle, blockById, blockFlushRef, blocks, blocksRef, clearTempMerge, commitActiveBlock, dismissedEditorImageMediaIdsRef, editorHostActionsRef, extractedLinks, focusImageBlock, focusKeyword, generateImageTargetRef, getExportHtml, insertCtaLinkIntoContent, insertSuggestedLinkIntoContent, intraSelectionRef, mediaPollTimersRef, pendingAiMediaRef, publishEditorImagesCatalogRef, publishExtractedLinks, removeInternalLinkFromContent, requestGenerateArticleImageRef, scheduleAutosave, scrollToExtractedLink, scrollToFeaturedSnippetTable, sectionByBlockId, setActiveBlockId, setBlocks, setExtractedLinks, setGlobalEditor, setImagesReloadKey, siteDomain, siteDomainRef, suggestedExternalLinks, suggestedInternalLinks, supplementalImagesRef, supportsProductGallery, tempMergeRef, utilitySchedulerRef }) {
    useEffect(() => {
        if (blocks.length === 0) {
            return undefined;
        }

        const domain = siteDomainRef.current || siteDomain;
        const scheduler = utilitySchedulerRef.current;
        if (!scheduler) {
            const freshLinks = scanExistingLinksCompat(blocks, domain);
            setExtractedLinks((prev) => {
                const prevInternal = JSON.stringify(prev?.internal ?? []);
                const prevExternal = JSON.stringify(prev?.external ?? []);
                const nextInternal = JSON.stringify(freshLinks.internal ?? []);
                const nextExternal = JSON.stringify(freshLinks.external ?? []);
                if (prevInternal === nextInternal && prevExternal === nextExternal) {
                    return prev;
                }
                return freshLinks;
            });
            return undefined;
        }

        scheduler.schedule({
            id: 'existing-links-scan',
            debounceMs: 750,
            priority: 'normal',
            run: ({ version, signal }) => {
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                const freshLinks = scanExistingLinksCompat(
                    blocksRef.current,
                    siteDomainRef.current || siteDomain,
                );
                if (signal.aborted || version !== scheduler.getVersion()) {
                    return;
                }
                setExtractedLinks((prev) => {
                    const prevInternal = JSON.stringify(prev?.internal ?? []);
                    const prevExternal = JSON.stringify(prev?.external ?? []);
                    const nextInternal = JSON.stringify(freshLinks.internal ?? []);
                    const nextExternal = JSON.stringify(freshLinks.external ?? []);
                    if (prevInternal === nextInternal && prevExternal === nextExternal) {
                        return prev;
                    }
                    return freshLinks;
                });
            },
        });

        return undefined;
    }, [blocks, siteDomain]);

    useEffect(() => {
        publishExtractedLinks(extractedLinks, suggestedInternalLinks, suggestedExternalLinks);
    }, [extractedLinks, suggestedInternalLinks, suggestedExternalLinks, publishExtractedLinks]);

    useEffect(() => {
        const republishExistingLinks = () => {
            const freshLinks = scanExistingLinksCompat(
                blocksRef.current,
                siteDomainRef.current || siteDomain,
            );
            setExtractedLinks(freshLinks);
            publishExtractedLinks(freshLinks, suggestedInternalLinks, suggestedExternalLinks);
        };

        window.addEventListener(LINKS_RESCAN_REQUEST_EVENT, republishExistingLinks);

        return () => {
            window.removeEventListener(LINKS_RESCAN_REQUEST_EVENT, republishExistingLinks);
        };
    }, [publishExtractedLinks, siteDomain, suggestedExternalLinks, suggestedInternalLinks]);

    useEffect(() => {
        // Host actions accept plain detail payloads (not Event). Window listeners unwrap once.
        const scrollToLinkAction = (detail) => {
            scrollToExtractedLink(detail && typeof detail === 'object' ? detail : {});
        };
        const insertSuggestedLinkAction = (detail) => {
            insertSuggestedLinkIntoContent(detail && typeof detail === 'object' ? detail : {});
        };
        const insertCtaLinkAction = (detail) => {
            insertCtaLinkIntoContent(detail && typeof detail === 'object' ? detail : {});
        };
        const removeInternalLinkAction = (detail) => {
            removeInternalLinkFromContent(detail && typeof detail === 'object' ? detail : {});
        };

        const onScrollToLink = (event) => {
            scrollToLinkAction(event?.detail ?? {});
        };

        const onFocusAssistantReason = (event) => {
            const detail = event?.detail ?? {};
            const code = String(detail.code ?? detail.reason?.code ?? '').trim();
            const targetId = String(detail.target_id ?? detail.reason?.target_id ?? '').trim();
            const panel = String(detail.panel ?? '').trim();

            if (code === 'focus_keyword_missing' || targetId === 'focus-keyword') {
                window.dispatchEvent(new CustomEvent('seo-assistant-switch-panel', { detail: { panel: 'seo' } }));
                requestAnimationFrame(() => {
                    const input = document.getElementById('seo-google-preview-focus-keyword');
                    if (input instanceof HTMLElement) {
                        input.focus();
                        scrollElementIntoViewIfNeeded(input, { behavior: 'smooth', block: 'nearest' });
                    }
                });
                return;
            }

            if (code === 'featured_missing' || code === 'featured_slug_not_fixed' || panel === 'featured') {
                openPanel('featured', { source: 'reason_featured' });
                return;
            }

            if (code === 'gallery_missing' || code.startsWith('gallery_') || panel === 'gallery') {
                openPanel('featured', { source: 'reason_gallery' });
                return;
            }

            if (
                code === 'image_slug_not_fixed'
                || code === 'image_slug_unresolved'
                || code === 'image_alt_missing'
                || code === 'image_ratio_low'
                || code === 'image_reference_invalid'
                || panel === 'images'
            ) {
                window.dispatchEvent(new CustomEvent('seo-assistant-switch-panel', { detail: { panel: 'images' } }));
                if (targetId) {
                    focusImageBlock(targetId);
                }
                return;
            }

            if (code === 'links_below_minimum' || panel === 'links' || panel === 'cta') {
                window.dispatchEvent(new CustomEvent('seo-assistant-switch-panel', { detail: { panel: panel || 'links' } }));
            }
        };

        const onRemoveInternalLink = (event) => {
            removeInternalLinkAction(event?.detail ?? {});
        };

        const onScrollToFeaturedSnippetTable = () => {
            scrollToFeaturedSnippetTable();
        };

        const onDocumentHtmlRequest = () => {
            const html = typeof getExportHtml === 'function'
                ? getExportHtml()
                : exportBlocksToHtml(blocksRef.current);
            window.dispatchEvent(
                new CustomEvent('seo-editor-document-html', {
                    detail: { html: String(html ?? ''), articleId },
                }),
            );
        };

        // Phase 6C.2 — module actions accept plain detail (FAQ pattern). Do not expect Event.
        editorHostActionsRef.current.insertSuggestedLink = insertSuggestedLinkAction;
        editorHostActionsRef.current.insertCtaLink = insertCtaLinkAction;
        editorHostActionsRef.current.removeInternalLink = removeInternalLinkAction;
        editorHostActionsRef.current.scrollToLink = scrollToLinkAction;
        editorHostActionsRef.current.applyEditorBlockImage = (detail) => {
            window.dispatchEvent(new CustomEvent('editor-block-image-selected', {
                detail: detail && typeof detail === 'object' ? detail : {},
            }));
        };
        editorHostActionsRef.current.generateArticleImage = (detail) => {
            requestGenerateArticleImageRef.current?.(detail);
        };
        editorHostActionsRef.current.generateArticleVideo = (detail) => {
            // Video generation remains Livewire shell endpoint (Alpine listens).
            window.dispatchEvent(new CustomEvent('generate-article-video', {
                detail: detail && typeof detail === 'object' ? detail : {},
            }));
        };
        editorHostActionsRef.current.openAiMedia = (detail) => {
            const payload = detail != null && typeof detail === 'object' ? detail : {};
            let ctx;

            if (payload.section) {
                ctx = buildAiMediaContextFromSection({
                    section: payload.section,
                    blockById,
                    articleTitle,
                    focusKeyword,
                });
            } else if (payload.blockId || payload.targetBlockId) {
                ctx = buildAiMediaContextFromBlock({
                    blockId: payload.blockId || payload.targetBlockId,
                    blockById,
                    sectionByBlockId,
                    articleTitle,
                    focusKeyword,
                });
            } else {
                ctx = {
                    prompt: String(payload.prompt ?? payload.prefill ?? payload.userBrief ?? '').trim(),
                    targetBlockId: String(payload.targetBlockId ?? payload.blockId ?? '').trim() || null,
                    mediaType: payload.mediaType === 'video' ? 'video' : 'image',
                };
            }

            if (payload.mediaType === 'video' || payload.mediaType === 'image') {
                ctx = { ...ctx, mediaType: payload.mediaType };
            }

            const targetId = String(ctx.targetBlockId ?? '').trim();
            if (targetId) {
                if (generateImageTargetRef) {
                    generateImageTargetRef.current = targetId;
                }
                setActiveBlockId(targetId);
                setGlobalEditor(null);
                focusImageBlock?.(targetId);
            }

            openPanel('ai-chat', {
                source: String(payload.source ?? 'host_open_ai_media'),
                detail: {
                    ...ctx,
                    source: String(payload.source ?? 'host_open_ai_media'),
                },
            });
        };
        editorHostActionsRef.current.getExportHtml = () => (
            typeof getExportHtml === 'function'
                ? getExportHtml()
                : exportBlocksToHtml(blocksRef.current)
        );
        editorHostActionsRef.current.getSelectionHtml = () => String(intraSelectionRef.current?.html ?? '');

        window.addEventListener('seo-editor-scroll-to-link', onScrollToLink);
        window.addEventListener('seo-assistant-focus-reason', onFocusAssistantReason);
        window.addEventListener('seo-editor-remove-internal-link', onRemoveInternalLink);
        window.addEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);
        window.addEventListener('seo-editor-document-html-request', onDocumentHtmlRequest);

        return () => {
            window.removeEventListener('seo-editor-scroll-to-link', onScrollToLink);
            window.removeEventListener('seo-assistant-focus-reason', onFocusAssistantReason);
            window.removeEventListener('seo-editor-remove-internal-link', onRemoveInternalLink);
            window.removeEventListener('seo-editor-scroll-to-featured-snippet-table', onScrollToFeaturedSnippetTable);
            window.removeEventListener('seo-editor-document-html-request', onDocumentHtmlRequest);
        };
    }, [scrollToExtractedLink, insertSuggestedLinkIntoContent, insertCtaLinkIntoContent, removeInternalLinkFromContent, scrollToFeaturedSnippetTable, getExportHtml, articleId, focusImageBlock, articleTitle, blockById, focusKeyword, generateImageTargetRef, sectionByBlockId, setActiveBlockId, setGlobalEditor]);

    const clearMediaPolling = useCallback((mediaId) => {
        const timer = mediaPollTimersRef.current.get(mediaId);
        if (timer) {
            window.clearTimeout(timer);
        }
        mediaPollTimersRef.current.delete(mediaId);
    }, []);

    const releaseImageBlockMediaTracking = useCallback(
        (block) => {
            if (!block || block.type !== 'image') {
                return;
            }

            const image = block.image ?? parseImageFromBlockContent(String(block.content ?? ''));
            const mediaId = Number(image?.seoMediaId ?? image?.seo_media_id ?? 0);
            const blockId = String(block.id ?? '').trim();

            for (const [key, pending] of [...pendingAiMediaRef.current.entries()]) {
                const pendingBlockId = String(pending?.blockId ?? '').trim();
                const keyAsMediaId = Number(key);

                if (
                    (blockId !== '' && pendingBlockId === blockId) ||
                    (mediaId > 0 && keyAsMediaId === mediaId)
                ) {
                    pendingAiMediaRef.current.delete(key);
                }
            }

            if (mediaId > 0) {
                dismissedEditorImageMediaIdsRef.current.add(mediaId);
                clearMediaPolling(mediaId);
            }
        },
        [clearMediaPolling],
    );

    const isDismissedEditorImageMedia = useCallback((mediaId) => {
        const id = Number(mediaId ?? 0);

        return id > 0 && dismissedEditorImageMediaIdsRef.current.has(id);
    }, []);

    const deleteBlock = useCallback(
        (id, { skipConfirm = false } = {}) => {
            if (!assertWritableEditorSession('block_delete_blocked')) {
                return;
            }
            if (blocksRef.current.length <= 1) return;

            const block = blocksRef.current.find((b) => b.id === id);
            if (!block) return;

            if (block.isWp && !skipConfirm && !window.confirm(t('editor_delete_wp_block_confirm'))) {
                return;
            }

            const isDeletingActive = activeBlockId === id;

            if (tempMergeRef.current?.rangeIds?.includes(id)) {
                clearTempMerge();
            }

            if (isDeletingActive) {
                blockFlushRef.current = null;
                setActiveBlockId(null);
                setGlobalEditor(null);
                dispatchActiveBlockContext(articleId, '', '', false, null);
            } else {
                commitActiveBlock();
            }

            if (block.type === 'image') {
                releaseImageBlockMediaTracking(block);
            }

            setBlocks((prev) => prev.filter((b) => b.id !== id));
        },
        [activeBlockId, articleId, commitActiveBlock, clearTempMerge, releaseImageBlockMediaTracking],
    );

    const removeImageBlock = useCallback(
        (row) => {
            const target = resolveArticleImageRemoveTarget(
                row,
                blocksRef.current,
                supplementalImagesRef.current,
            );
            if (!target || target.kind !== 'block') {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_tab_remove_no_block'),
                            body: t('image_tab_remove_unmatched_404'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const blockId = target.blockId;
            if (blocksRef.current.length <= 1) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('cannot_delete_last_block'),
                            body: t('image_tab_remove_last_block_hint'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const block = blocksRef.current.find((item) => item.id === blockId);
            if (!block || block.type !== 'image') {
                return;
            }

            deleteBlock(blockId, { skipConfirm: true });

            // Dọn supplemental orphan cùng identity — tránh hàng 404 còn lại trên tab Images.
            mediaActions.setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).filter((item) => {
                    if (String(item?.blockId ?? item?.block_id ?? '').trim() === blockId) {
                        return false;
                    }

                    return !articleImageRowsShareIdentity(row, item);
                }),
            );
            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        },
        [deleteBlock],
    );

    const removeSupplementalImage = useCallback(
        (row) => {
            const target = resolveArticleImageRemoveTarget(
                row,
                blocksRef.current,
                supplementalImagesRef.current,
            );
            if (!target) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('image_tab_remove_no_block'),
                            body: t('image_tab_remove_unmatched_404'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            if (target.kind === 'block') {
                removeImageBlock(row);

                return;
            }

            const src = String(target.src ?? row?.src ?? '').trim();
            const origin = String(target.origin ?? row?.origin ?? '').trim();
            if (!src) {
                return;
            }

            mediaActions.setSupplementalImages((prev) =>
                (Array.isArray(prev) ? prev : []).filter((item) => {
                    const itemBlockId = String(item?.blockId || item?.block_id || '').trim();
                    if (itemBlockId) {
                        return true;
                    }

                    return !articleImageRowsShareIdentity(
                        { ...row, src, origin },
                        item,
                    );
                }),
            );

            if (supportsProductGallery && (origin === 'gallery' || origin === 'featured')) {
                removeProductAlbumItem(articleId, src);
                if (loadProductAlbum(articleId).length === 0) {
                    clearFeaturedImageStorage(articleId);
                }
            } else if (origin === 'featured') {
                clearFeaturedImageStorage(articleId);
            }

            setImagesReloadKey((key) => key + 1);
            queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
            scheduleAutosave();
        },
        [articleId, removeImageBlock, scheduleAutosave, supportsProductGallery],
    );

    const makeImageFeatured = useCallback(
        async (row) => {
            if (supportsProductGallery) {
                throw new Error(t('make_featured_image_product_hint'));
            }

            const item = saveFeaturedImage(articleId, {
                url: String(row?.localSrc || row?.src || '').trim(),
                wpAttachmentId: Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0),
                seoMediaId: Number(row?.seoMediaId ?? row?.seo_media_id ?? 0),
                alt: String(row?.alt ?? '').trim(),
                slug: String(row?.slug ?? '').trim(),
            });

            if (!item) {
                throw new Error(t('make_featured_image_missing_source'));
            }

            await callEditArticleLivewire('persistFeaturedImageFromClient', item);

            window.dispatchEvent(
                new CustomEvent('article-media-selected', {
                    detail: {
                        mode: 'featured',
                        url: item.url,
                        wpAttachmentId: item.wp_attachment_id,
                        seoMediaId: item.seo_media_id,
                        alt: item.alt,
                        slug: item.slug,
                    },
                }),
            );
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('make_featured_image_success'),
                        body: t('make_featured_image_success_body'),
                        status: 'success',
                    },
                }),
            );
            setImagesReloadKey((key) => key + 1);
            scheduleAutosave();
        },
        [articleId, scheduleAutosave, supportsProductGallery],
    );

    return { clearMediaPolling, deleteBlock, isDismissedEditorImageMedia, makeImageFeatured, removeImageBlock, removeSupplementalImage };
}
