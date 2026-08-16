import { TIPTAP_HTML_PARSE_OPTIONS } from '../utils/inlineWhitespaceGuard';
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
    buildLocalSlugRenameErrorNotify,
    buildQuickFixIndexByBlockId,
    collectImagesFromBlocks,
    computeQuickFixAltTitleSupplementalOutcome,
    computeQuickFixSlugSupplementalOutcome,
    enrichWpRenamedWithRequestMeta,
    ensureLocalRenameResultsCoverQueue,
    ensureWpRenameResultsCoverQueue,
    executeSeoMediaSlugRenamesTwoPhase,
    filterSupplementalDuplicatesOfBlockRows,
    finalizeBlocksAfterWpRename,
    mapArticleSlugFixReplacementsToLocalResults,
    omitFailedLocalSlugRenameQueueItems,
    resetSupplementalImagesAfterSlugRename,
    resolveImageRefIds,
    resolveWpRenameOldUrl,
    shouldRenameSlugOnWordPress,
    slugFromUrl,
    syncProductAlbumUrlsFromBlockImages,
} from '@media-addon/utils/articleImagesUtils.js';
import {
    buildGallerySupplementalRows,
    distributeProductImagesToEmptySections,
    exportBlocksToHtml,
    normalizeImageSrcKey,
} from '../utils/contentDocumentHelpers';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { clearArticleMediaPickerCache } from '@media-addon/utils/articleMediaPickerCache.js';
import {
    clearDraft,
    hashContent,
    saveDraft,
    writeSyncedLocalSnapshot,
} from '../utils/articleEditorStorage';
import { clearFeaturedImageStorage, loadFeaturedImage, saveFeaturedImage } from '@media-addon/utils/articleFeaturedImageStorage.js';
import { confirmSlugRename } from '@media-addon/utils/imageSlugRenameConfirm.js';
import { dispatchWordPressAttachmentMetaUpdate } from '@media-addon/utils/imageAttachmentMetaUpdate.js';
import { findPlainTextRangeInRoot } from '../utils/articlePlainTextRange';
import {
    fixArticleMediaSlugs,
    renameSeoMedia,
    renameSeoMediaByUrl,
    updateSeoMediaMeta,
} from '@media-addon/utils/seoMediaApi.js';
import { getEditorConflictTokens, setEditorConflictTokens } from '../utils/articleEditorApi';
import { isBulkSlugRenameSafeMedia, isWordPressProtectedMedia } from '@media-addon/utils/mediaSourceClassification.js';
import { loadProductAlbum } from '@media-addon/utils/articleProductAlbumStorage.js';
import { mediaActions } from '@media-addon/editor/domains/media/state.js';
import { persistBlockHtmlFromEditor } from '../utils/editorHtmlUtils';
import { rowRequiresLocalSlugFix, unifiedInventorySlugFixCandidates } from '@media-addon/utils/unifiedArticleImagesInventory.js';
import { saveCurrentArticleFromEditor } from '../utils/articleEditorSaveQueue';
import { setArticleAutosaveLock } from '../utils/articleAutosaveLock';
import { showArticleOperationOverlay } from '../utils/articleOperationTracker';
import { t } from '../utils/i18n';
import { useCallback } from 'react';

/**
 * useArticleEditorImageSlugRename - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorImageSlugRename({ articleId, articleTitle, blockEditorsRef, blockFlushRef, blockOutsideClickGuardUntilRef, blocksRef, cancelLocalDraftSave, commitActiveBlock, connectionHashRef, draftScope, focusKeyword, getExportHtml, pendingLocalRenameQueueRef, pendingLocalRenameResultsRef, pendingQuickFixKeywordRef, pendingWpRenameRequestRef, publishEditorImagesCatalogRef, quickFixSlugAllBusy, requestAnalyze, scheduleAutosave, setActiveBlockId, setBlocks, setGlobalEditor, setImagesReloadKey, setMediaHealthTick, setQuickFixSlugAllBusy, setSaveStatus, siteId, siteIdRef, skipNextAutosave, slugRenameManagedByBatchRef, supplementalImages, supplementalImagesRef, supportsProductGallery, tempMergeRef, unifiedImageRows, unifiedImagesInventory, updateBlocksWithoutHistory, withDraftSite }) {
    const armBlockOutsideClickGuard = useCallback((ms = 220) => {
        blockOutsideClickGuardUntilRef.current = Date.now() + ms;
    }, []);

    const selectPlainTextInBlock = useCallback((blockId, text, occurrenceIndex = 0, onSelected) => {
        const maxAttempts = 30;

        const attempt = (attemptNo) => {
            const editor = blockEditorsRef.current.get(blockId);
            if (!editor || editor.isDestroyed) {
                if (attemptNo < maxAttempts) {
                    window.setTimeout(() => attempt(attemptNo + 1), 20);
                }
                return;
            }

            const match = findPlainTextRangeInRoot(editor.view.dom, text, occurrenceIndex);
            if (!match) {
                return;
            }

            const from = editor.view.posAtDOM(match.node, match.start);
            const to = editor.view.posAtDOM(match.endNode, match.endOffset);
            if (to <= from) {
                return;
            }

            editor.chain().focus().setTextSelection({ from, to }).run();
            const domAt = editor.view.domAtPos(from);
            const target =
                domAt.node instanceof Element
                    ? domAt.node
                    : domAt.node?.parentElement;
            target?.scrollIntoView?.({
                behavior: attemptNo === 0 ? 'smooth' : 'auto',
                block: 'center',
            });
            onSelected?.(editor);
        };

        attempt(0);
    }, []);

    const persistEditorContentImmediately = useCallback(
        (editor, blockId) => {
            const currentBlocks = blocksRef.current;
            const block = currentBlocks.find((item) => item.id === blockId);
            if (!block || !editor || editor.isDestroyed) {
                return;
            }

            const content = persistBlockHtmlFromEditor(block.content ?? '', editor.getHTML());
            const nextBlocks = currentBlocks.map((item) =>
                item.id === blockId ? { ...item, content } : item,
            );

            blocksRef.current = nextBlocks;
            setBlocks(nextBlocks);
            if (articleId) {
                saveDraft(articleId, connectionHashRef.current, {
                    content: exportBlocksToHtml(nextBlocks),
                });
                setSaveStatus('saved');
            }
        },
        [articleId],
    );

    const distributeProductGalleryImages = useCallback(() => {
        if (!supportsProductGallery) {
            return;
        }

        commitActiveBlock();

        const galleryRows = buildGallerySupplementalRows(supplementalImages, null, articleId);
        if (galleryRows.length === 0) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('product_gallery_distribute_none_title'),
                        body: t('product_gallery_distribute_no_images'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        let inserted = 0;
        const result = distributeProductImagesToEmptySections(blocksRef.current, galleryRows);
        inserted = result.inserted;
        if (inserted > 0) {
            setBlocks(result.blocks);
            scheduleAutosave();
            requestAnalyze();
        }

        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: inserted > 0
                    ? {
                          title: t('product_gallery_distribute_success_title'),
                          body: t('product_gallery_distribute_success', { count: inserted }),
                          status: 'success',
                      }
                    : {
                          title: t('product_gallery_distribute_none_title'),
                          body: t('product_gallery_distribute_no_sections'),
                          status: 'warning',
                      },
            }),
        );
    }, [supportsProductGallery, supplementalImages, articleId, commitActiveBlock, requestAnalyze, scheduleAutosave]);


    const patchImageInBlocks = useCallback(
        (blockId, patch, withoutHistory = false) => {
            const updater = (prev) => applyImagePatchToBlocks(prev, blockId, patch);
            if (withoutHistory) {
                updateBlocksWithoutHistory(updater);
            } else {
                setBlocks(updater);
            }

            if (patch && Object.prototype.hasOwnProperty.call(patch, 'excludeQuickFix')) {
                setImagesReloadKey((key) => key + 1);
                scheduleAutosave();
            }
        },
        [scheduleAutosave, updateBlocksWithoutHistory],
    );

    const requestWordPressRenames = useCallback((items, options = {}) => {
        if (!items?.length) {
            return;
        }

        const silent = options.silent === true;
        pendingWpRenameRequestRef.current = Array.isArray(items) ? [...items] : [];

        window.dispatchEvent(
            new CustomEvent('seo-rename-attachment-slugs-loading', {
                detail: { count: items.length },
            }),
        );

        callEditArticleLivewire('renameAttachmentSlugsOnWordPress', items, silent).catch((error) => {
            pendingWpRenameRequestRef.current = [];
            window.dispatchEvent(
                new CustomEvent('seo-attachment-slugs-rename-finished', {
                    detail: {
                        success: false,
                        renamed: [],
                        message: error?.message ?? t('editor_try_again_later'),
                    },
                }),
            );
        });
    }, []);

    const renameLocalMediaByUrl = useCallback(
        (mediaUrl, newSlug, options = {}) =>
            renameSeoMediaByUrl(mediaUrl, newSlug, {
                siteId,
                articleId,
                seoMediaId: options?.seoMediaId ?? null,
            }),
        [siteId, articleId],
    );

    const requestWordPressAttachmentMetaUpdate = useCallback((items, options = {}) => {
        dispatchWordPressAttachmentMetaUpdate(items, { silent: options.silent === true });
    }, []);

    const notifyEditor = useCallback((title, body, status = 'success') => {
        window.dispatchEvent(
            new CustomEvent('seo-article-editor-notify', {
                detail: { title, body, status },
            }),
        );
    }, []);

    const notifyLocalSlugRenameErrors = useCallback((errors, attemptedCount) => {
        const detail = buildLocalSlugRenameErrorNotify(errors, attemptedCount);
        if (!detail) {
            return;
        }
        const body = detail.body
            || (detail.bodyKey ? t(detail.bodyKey, detail.bodyParams) : t('editor_try_again_later'));
        notifyEditor(t(detail.titleKey), body, detail.status);
    }, [notifyEditor]);

    const pushAltTitleMetaToStores = useCallback(
        (row, altTitle) => {
            const trimmed = String(altTitle ?? '').trim();
            if (!trimmed || !row) {
                return;
            }

            const { seoMediaId, wpAttachmentId } = buildAltTitleMetaUpdatePayload(row, trimmed);

            if (seoMediaId > 0) {
                updateSeoMediaMeta([
                    {
                        id: seoMediaId,
                        alt_text: trimmed,
                        title: trimmed,
                    },
                ]).catch((error) => {
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: t('editor_cannot_update_image_meta'),
                                body: error?.message ?? t('editor_try_again_later'),
                                status: 'danger',
                            },
                        }),
                    );
                });
            }

            if (wpAttachmentId > 0) {
                requestWordPressAttachmentMetaUpdate([
                    {
                        attachment_id: wpAttachmentId,
                        alt_text: trimmed,
                        title: trimmed,
                    },
                ]);
            }
        },
        [requestWordPressAttachmentMetaUpdate],
    );

    const handleImageSlugChange = useCallback(
        (row, newSlug, applyPatch) => {
            const trimmed = newSlug.trim();
            if (!trimmed || trimmed === (row.slug || '').trim()) {
                return true;
            }

            const { wpAttachmentId, seoMediaId, isLocal, src: localSrc } = resolveImageRefIds(row);
            const renameSrc = String(localSrc || row.src || '').trim();

            if (shouldRenameSlugOnWordPress(row)) {
                if (!confirmSlugRename({ count: 1 })) {
                    return false;
                }

                pendingQuickFixKeywordRef.current = '';
                requestWordPressRenames([
                    {
                        attachment_id: wpAttachmentId,
                        new_slug: trimmed,
                        old_url: resolveWpRenameOldUrl(row),
                        old_slug: (row.slug || '').trim(),
                        block_id: String(row?.blockId ?? row?.block_id ?? '').trim(),
                    },
                ]);

                return true;
            }

            // Ưu tiên rename-by-url khi local /storage — tránh ID WP stale gọi /media/{id}/rename.
            if ((isLocal || renameSrc.includes('/storage/uploads/seo_media/')) && renameSrc) {
                const oldSlug = (row.slug || '').trim();
                renameLocalMediaByUrl(renameSrc, trimmed, { seoMediaId: seoMediaId > 0 ? seoMediaId : null })
                    .then((data) => {
                        applyPatch({
                            slug: data.slug,
                            src: data.url,
                            seoMediaId: data.id ?? row.seoMediaId,
                            originalSlug: oldSlug,
                        });
                    })
                    .catch((error) => {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('editor_cannot_rename_image_slug'),
                                    body: error?.message ?? t('editor_try_again_later'),
                                    status: 'danger',
                                },
                            }),
                        );
                    });

                return true;
            }

            if (seoMediaId > 0) {
                renameSeoMedia(seoMediaId, trimmed, { articleId })
                    .then((data) => {
                        applyPatch({
                            slug: data.slug,
                            src: data.url,
                            seoMediaId: data.id ?? seoMediaId,
                        });
                    })
                    .catch((error) => {
                        window.dispatchEvent(
                            new CustomEvent('seo-article-editor-notify', {
                                detail: {
                                    title: t('editor_cannot_rename_image_slug'),
                                    body: error?.message ?? t('editor_try_again_later'),
                                    status: 'danger',
                                },
                            }),
                        );
                    });

                return true;
            }

            applyPatch({ slug: trimmed });

            return true;
        },
        [renameLocalMediaByUrl, requestWordPressRenames],
    );

    const patchSupplementalImageRow = useCallback((targetRow, patch = {}) => {
        const targetWpId = Number(targetRow?.wpAttachmentId ?? targetRow?.wp_attachment_id ?? 0);
        const targetSeoId = Number(targetRow?.seoMediaId ?? targetRow?.seo_media_id ?? 0);
        const targetSrc = normalizeImageSrcKey(targetRow?.src);

        mediaActions.setSupplementalImages((prev) =>
            (Array.isArray(prev) ? prev : []).map((row) => {
                const rowWpId = Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
                const rowSeoId = Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                const rowSrc = normalizeImageSrcKey(row?.src);
                const matched =
                    (targetWpId > 0 && rowWpId > 0 && targetWpId === rowWpId) ||
                    (targetSeoId > 0 && rowSeoId > 0 && targetSeoId === rowSeoId) ||
                    (targetSrc !== '' && rowSrc !== '' && targetSrc === rowSrc);

                if (!matched) {
                    return row;
                }

                const nextSrc = String(patch.src ?? row.src ?? '').trim();
                const isLocal = nextSrc.includes('/storage/uploads/seo_media/');
                return {
                    ...row,
                    ...patch,
                    src: nextSrc || row.src,
                    wp_url:
                        patch.wp_url ??
                        (isLocal ? String(row.wp_url ?? '') : nextSrc || String(row.wp_url ?? '')),
                    local_src:
                        patch.local_src ??
                        (isLocal ? nextSrc || String(row.local_src ?? '') : String(row.local_src ?? '')),
                };
            }),
        );
    }, []);

    const handleImageAltTitleChange = useCallback(
        (row, altTitle) => {
            const trimmed = String(altTitle ?? '').trim();
            if (!row || trimmed === String(row?.alt || '').trim()) {
                return;
            }

            const patch = { alt: trimmed, title: trimmed };
            const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();

            if (blockId) {
                patchImageInBlocks(blockId, patch);
            } else {
                patchSupplementalImageRow(row, patch);
            }

            pushAltTitleMetaToStores(row, trimmed);
            setImagesReloadKey((k) => k + 1);
        },
        [patchImageInBlocks, patchSupplementalImageRow, pushAltTitleMetaToStores],
    );

    const enrichSupplementalRow = useCallback((row, fallbackIndex = 0) => {
        return Number(row?.quickFixIndex ?? 0) > 0
            ? row
            : {
                  ...row,
                  quickFixIndex: Number(fallbackIndex ?? 0) > 0 ? Number(fallbackIndex) : 0,
              };
    }, []);

    const runSupplementalLocalRenames = useCallback(
        (localRenames, supplementalOnlyRows) => {
            if (!localRenames.length) {
                return Promise.resolve();
            }

            const supplementalLocalRenameKeys = new Set();
            const uniqueLocalRenames = [];
            localRenames.forEach((item) => {
                const localKey =
                    Number(item.seo_media_id ?? 0) > 0
                        ? `id:${Number(item.seo_media_id)}`
                        : `src:${normalizeImageSrcKey(item.src)}`;
                if (supplementalLocalRenameKeys.has(localKey)) {
                    return;
                }
                supplementalLocalRenameKeys.add(localKey);
                uniqueLocalRenames.push(item);
            });

            return (async () => {
                try {
                    const results = await executeSeoMediaSlugRenamesTwoPhase(uniqueLocalRenames, {
                        renameById: (id, slug) => renameSeoMedia(id, slug, { articleId }),
                        renameByUrl: (src, slug, opts) => renameLocalMediaByUrl(src, slug, opts),
                    });

                    pendingLocalRenameResultsRef.current = [
                        ...pendingLocalRenameResultsRef.current,
                        ...results,
                    ];

                    const skipped = results.errors?.length ?? 0;
                    if (skipped > 0) {
                        pendingLocalRenameQueueRef.current = omitFailedLocalSlugRenameQueueItems(
                            pendingLocalRenameQueueRef.current,
                            results.errors,
                        );
                        notifyLocalSlugRenameErrors(results.errors, uniqueLocalRenames.length);
                    }
                } catch (error) {
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: t('editor_cannot_rename_image_slug'),
                                body: error?.message ?? t('editor_try_again_later'),
                                status: 'danger',
                            },
                        }),
                    );
                }

                setImagesReloadKey((k) => k + 1);
            })();
        },
        [notifyLocalSlugRenameErrors, renameLocalMediaByUrl],
    );

    const buildQuickFixContext = useCallback(
        (imageRows = null) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword) {
                return null;
            }

            const inventoryRows = Array.isArray(imageRows) && imageRows.length > 0
                ? imageRows
                : unifiedImageRows;
            const baseRows = (Array.isArray(inventoryRows) ? inventoryRows : []).filter(
                (row) => !row?.excludeQuickFix,
            );
            const sourceRows = [];
            const seenRows = new Set();
            const appendSourceRow = (row) => {
                if (!row || row?.excludeQuickFix) {
                    return;
                }

                const key =
                    String(row?.identity_key ?? '').trim()
                    || normalizeImageSrcKey(row?.src)
                    || String(row?.blockId ?? row?.block_id ?? '').trim()
                    || `row:${sourceRows.length}`;
                if (!key || seenRows.has(key)) {
                    return;
                }

                seenRows.add(key);
                sourceRows.push(row);
            };

            baseRows.forEach(appendSourceRow);

            // Ensure local Featured/Gallery slug-fix candidates are present even if panel filter omitted them.
            unifiedInventorySlugFixCandidates(unifiedImagesInventory).forEach(appendSourceRow);

            const indexedRows = assignInArticleQuickFixIndices(
                filterSupplementalDuplicatesOfBlockRows(sourceRows),
            );
            const indexByBlockId = buildQuickFixIndexByBlockId(indexedRows);

            const supplementalOnlyRows = indexedRows.filter(
                (row) => String(row?.blockId ?? row?.block_id ?? '').trim() === '',
            );

            return { keyword, sourceRows: indexedRows, indexByBlockId, supplementalOnlyRows };
        },
        [focusKeyword, articleTitle, unifiedImageRows, unifiedImagesInventory],
    );

    const assertNoLocalSlugFixBeforeWpSync = useCallback(() => {
        const context = buildQuickFixContext();
        if (!context) {
            return;
        }

        const pendingRows = (context.sourceRows ?? []).filter((row) => rowRequiresLocalSlugFix(row));
        if (pendingRows.length === 0) {
            return;
        }

        const preview = pendingRows
            .slice(0, 3)
            .map((row) => String(row?.slug ?? slugFromUrl(row?.src ?? row?.url ?? '') ?? '').trim())
            .filter(Boolean)
            .join(', ');
        const body = preview
            ? `Còn ${pendingRows.length} ảnh local chưa chuẩn slug (${preview}). Bấm Fix slug all trước khi Sync WP.`
            : `Còn ${pendingRows.length} ảnh local chưa chuẩn slug. Bấm Fix slug all trước khi Sync WP.`;

        notifyEditor('Chưa thể Sync WP', body, 'warning');

        const error = new Error(body);
        error.code = 'local_image_slug_fix_required';
        throw error;
    }, [buildQuickFixContext, notifyEditor]);

    const applyQuickFixSlugPreview = useCallback(
        (preview, keyword, options = {}) => {
            const renameCount = preview.renameQueue.length;
            const localRenameCount = (preview.localRenameQueue ?? []).length;
            const silent = options.silent === true;

            pendingQuickFixKeywordRef.current = keyword;
            pendingLocalRenameResultsRef.current = [];
            pendingLocalRenameQueueRef.current = Array.isArray(preview.localRenameQueue)
                ? [...preview.localRenameQueue]
                : [];

            const tasks = [];

            if (renameCount > 0) {
                requestWordPressRenames(preview.renameQueue, { silent });
            } else if (localRenameCount === 0) {
                setImagesReloadKey((k) => k + 1);
            }

            if (localRenameCount > 0) {
                const blockRenames = (preview.localRenameQueue ?? []).filter(
                    (item) => String(item?.block_id ?? '').trim() !== '',
                );
                const supplementalRenames = (preview.localRenameQueue ?? []).filter(
                    (item) => String(item?.block_id ?? '').trim() === '',
                );

                const runLocalRenames = async (items) => {
                    if (!items.length) {
                        return [];
                    }

                    return executeSeoMediaSlugRenamesTwoPhase(items, {
                        renameById: (id, slug) => renameSeoMedia(id, slug, { articleId }),
                        renameByUrl: (src, slug, opts) => renameLocalMediaByUrl(src, slug, opts),
                    });
                };

                // Chạy tuần tự + gộp 1 lần — tránh race ghi đè pendingLocalRenameResultsRef.
                tasks.push(
                    (async () => {
                        const merged = [];
                        const allErrors = [];
                        try {
                            if (blockRenames.length > 0) {
                                const blockResults = await runLocalRenames(blockRenames);
                                merged.push(...blockResults);
                                if (Array.isArray(blockResults.errors)) {
                                    allErrors.push(...blockResults.errors);
                                }
                            }
                            if (supplementalRenames.length > 0) {
                                const supplementalResults = await runLocalRenames(supplementalRenames);
                                merged.push(...supplementalResults);
                                if (Array.isArray(supplementalResults.errors)) {
                                    allErrors.push(...supplementalResults.errors);
                                }
                            }
                        } catch (error) {
                            window.dispatchEvent(
                                new CustomEvent('seo-article-editor-notify', {
                                    detail: {
                                        title: t('editor_cannot_rename_local_image_slug'),
                                        body: error?.message ?? t('editor_try_again_later'),
                                        status: 'danger',
                                    },
                                }),
                            );
                        }

                        if (allErrors.length > 0) {
                            pendingLocalRenameQueueRef.current = omitFailedLocalSlugRenameQueueItems(
                                pendingLocalRenameQueueRef.current,
                                allErrors,
                            );
                            notifyLocalSlugRenameErrors(
                                allErrors,
                                blockRenames.length + supplementalRenames.length,
                            );
                        }

                        pendingLocalRenameResultsRef.current = [
                            ...pendingLocalRenameResultsRef.current,
                            ...merged,
                        ];
                    })(),
                );
            }

            return tasks.length > 0 ? Promise.all(tasks) : Promise.resolve();
        },
        [notifyLocalSlugRenameErrors, renameLocalMediaByUrl, requestWordPressRenames],
    );

    const applySlugRenameFinished = useCallback((detail) => {
        const rawWpRenamed = enrichWpRenamedWithRequestMeta(
            Array.isArray(detail?.renamed) ? detail.renamed : [],
            pendingWpRenameRequestRef.current,
        );
        // success === false: chỉ dùng renamed thật. Còn lại: fill queue thiếu (file đã rename sẵn).
        const wpRenamed = detail?.success === false
            ? rawWpRenamed
            : ensureWpRenameResultsCoverQueue(pendingWpRenameRequestRef.current, rawWpRenamed);
        pendingWpRenameRequestRef.current = [];
        const localResults = detail?.skipLocalQueueRecovery
            ? (pendingLocalRenameResultsRef.current ?? [])
            : ensureLocalRenameResultsCoverQueue(
                pendingLocalRenameQueueRef.current,
                pendingLocalRenameResultsRef.current ?? [],
            );
        pendingLocalRenameResultsRef.current = [];
        pendingLocalRenameQueueRef.current = [];
        pendingQuickFixKeywordRef.current = '';

        // Deactivate TipTap trước khi patch — tránh flush HTML cũ đè URL mới.
        if (!tempMergeRef.current) {
            blockFlushRef.current = null;
        }
        setActiveBlockId(null);
        setGlobalEditor(null);

        const nextBlocks = finalizeBlocksAfterWpRename(blocksRef.current, wpRenamed, localResults);
        blocksRef.current = nextBlocks;
        setBlocks(nextBlocks);

        // Sync TipTap document thật (không chỉ DOM) cho mọi editor còn sống.
        nextBlocks.forEach((block) => {
            if (String(block?.type ?? '') === 'image') {
                return;
            }
            const editor = blockEditorsRef.current.get(block.id);
            if (!editor || editor.isDestroyed) {
                return;
            }
            const nextHtml = String(block.content ?? '').trim() || '<p></p>';
            try {
                editor.commands.setContent(nextHtml, {
                    emitUpdate: false,
                    parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                });
            } catch {
                // ignore destroyed/race
            }
        });

        mediaActions.setSupplementalImages((prev) =>
            resetSupplementalImagesAfterSlugRename(prev, nextBlocks, wpRenamed, localResults),
        );

        const urlMap = buildExactRenameUrlMap(wpRenamed, localResults);
        if (articleId && Object.keys(urlMap).length > 0) {
            syncProductAlbumUrlsFromBlockImages(articleId, nextBlocks, wpRenamed, localResults);
            applyRenameMapToFeaturedImageStorage(articleId, wpRenamed, localResults);
        }

        const siteIdForCache = Number(siteIdRef.current ?? 0) || 0;
        if (siteIdForCache > 0) {
            clearArticleMediaPickerCache(siteIdForCache);
        }

        setImagesReloadKey((k) => k + 1);
        queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        scheduleAutosave();

        return { nextBlocks, wpRenamed, localResults, urlMap };
    }, [articleId, scheduleAutosave, setGlobalEditor]);

    const waitForWordPressSlugRenameFinished = useCallback((batchCount = 1) => {
        const total = Number(batchCount);
        if (total <= 0) {
            return Promise.resolve(null);
        }

        return new Promise((resolve) => {
            let remaining = total;
            let lastDetail = null;

            const onFinished = (event) => {
                lastDetail = event?.detail ?? null;
                remaining -= 1;
                if (remaining > 0) {
                    return;
                }

                window.removeEventListener('seo-attachment-slugs-rename-finished', onFinished);
                resolve(lastDetail);
            };

            window.addEventListener('seo-attachment-slugs-rename-finished', onFinished);
        });
    }, []);

    const finalizeSlugRenameSideEffects = useCallback((wpRenamed = [], localResults = []) => {
        const currentBlocks = blocksRef.current;

        if (supportsProductGallery && articleId) {
            syncProductAlbumUrlsFromBlockImages(articleId, currentBlocks, wpRenamed, localResults);
            const album = loadProductAlbum(articleId);
            if (album.length > 0) {
                saveFeaturedImage(articleId, {
                    url: album[0].url,
                    wpAttachmentId: album[0].id,
                    seoMediaId: album[0].id,
                });
            } else {
                clearFeaturedImageStorage(articleId);
            }
        }

        const siteIdForCache = Number(siteIdRef.current ?? 0) || 0;
        if (siteIdForCache > 0) {
            clearArticleMediaPickerCache(siteIdForCache);
        }

        setImagesReloadKey((key) => key + 1);
        queueMicrotask(() => publishEditorImagesCatalogRef.current?.(true));
        // Recompute Media Health immediately — do not wait for SEO re-analyze.
        mediaActions.setFeaturedHealthSnapshot(loadFeaturedImage(articleId));
        mediaActions.markDirty();
        setMediaHealthTick((tick) => tick + 1);
        window.dispatchEvent(new CustomEvent('seo-assistant-widget-health-refresh'));
        scheduleAutosave();
    }, [articleId, scheduleAutosave, supportsProductGallery]);

    const prepareImageSlugsBeforeWpSync = useCallback(async () => {
        if (quickFixSlugAllBusy) {
            throw new Error(t('editor_try_again_later'));
        }

        const context = buildQuickFixContext();
        if (!context) {
            return false;
        }

        const { keyword, indexByBlockId, supplementalOnlyRows, sourceRows } = context;
        const enrichmentByBlockId = {};
        (sourceRows ?? []).forEach((row) => {
            const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
            if (blockId) {
                enrichmentByBlockId[blockId] = row;
            }
        });

        const preview = applyQuickFixSlugToBlocks(
            blocksRef.current,
            keyword,
            indexByBlockId,
            enrichmentByBlockId,
            { wpOnly: false, includeWordPressRenames: false },
        );
        const localRenameQueue = [...(preview.localRenameQueue ?? [])];
        const blockEligibleCount = collectImagesFromBlocks(blocksRef.current).filter(
            (row) => !row?.excludeQuickFix,
        ).length;

        let supplementalOrdinal = blockEligibleCount;
        supplementalOnlyRows.forEach((row) => {
            if (row?.excludeQuickFix) {
                return;
            }

            supplementalOrdinal += 1;
            const enriched = { ...row, quickFixIndex: supplementalOrdinal };
            const outcome = computeQuickFixSlugSupplementalOutcome(enriched, keyword, {
                wpOnly: false,
            });
            if (outcome.localRename) {
                localRenameQueue.push(outcome.localRename);
            }
        });

        const uniqueLocalRenames = [];
        const seenLocalRenames = new Set();
        localRenameQueue.forEach((item) => {
            const id = Number(item?.seo_media_id ?? 0);
            const key = id > 0 ? `id:${id}` : `src:${normalizeImageSrcKey(item?.src)}`;
            if (!key || seenLocalRenames.has(key)) {
                return;
            }

            seenLocalRenames.add(key);
            uniqueLocalRenames.push(item);
        });

        if (uniqueLocalRenames.length === 0) {
            return false;
        }

        setQuickFixSlugAllBusy(true);
        window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang chuẩn hóa tên ảnh…');

        try {
            const localResults = await executeSeoMediaSlugRenamesTwoPhase(uniqueLocalRenames, {
                renameById: (id, slug) => renameSeoMedia(id, slug, { articleId }),
                renameByUrl: (src, slug, opts) => renameLocalMediaByUrl(src, slug, opts),
            });
            const nextBlocks = finalizeBlocksAfterWpRename(blocksRef.current, [], localResults);
            const nextSupplemental = resetSupplementalImagesAfterSlugRename(
                supplementalImagesRef.current,
                nextBlocks,
                [],
                localResults,
            );

            blocksRef.current = nextBlocks;
            supplementalImagesRef.current = nextSupplemental;
            setBlocks(nextBlocks);
            mediaActions.setSupplementalImages(nextSupplemental);
            finalizeSlugRenameSideEffects();

            const skipped = localResults.errors?.length ?? 0;
            if (skipped > 0) {
                notifyLocalSlugRenameErrors(localResults.errors, uniqueLocalRenames.length);
            }

            return localResults.length > 0 || skipped > 0;
        } catch (error) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_cannot_rename_local_image_slug'),
                        body: error?.message ?? t('editor_try_again_later'),
                        status: 'danger',
                    },
                }),
            );
            throw error;
        } finally {
            setQuickFixSlugAllBusy(false);
        }
    }, [
        buildQuickFixContext,
        finalizeSlugRenameSideEffects,
        notifyLocalSlugRenameErrors,
        quickFixSlugAllBusy,
        renameLocalMediaByUrl,
    ]);

    const quickFixSlugAllImages = useCallback(
        async (imageRows = null) => {
            if (quickFixSlugAllBusy) {
                return;
            }

            const context = buildQuickFixContext(imageRows);
            if (!context) {
                return;
            }

            const { keyword, indexByBlockId, supplementalOnlyRows, sourceRows } = context;

            const enrichmentByBlockId = {};
            (sourceRows ?? []).forEach((row) => {
                const blockId = String(row?.blockId ?? row?.block_id ?? '').trim();
                if (blockId) {
                    enrichmentByBlockId[blockId] = row;
                }
            });

            // Fix Slug All: local/safe media only — never WordPress attachments.
            const preview = applyQuickFixSlugToBlocks(
                blocksRef.current,
                keyword,
                indexByBlockId,
                enrichmentByBlockId,
                { wpOnly: false, includeWordPressRenames: false },
            );

            const allBodyRows = collectImagesFromBlocks(blocksRef.current);
            let skippedWordPress = Number(preview.skippedWordPress ?? 0)
                || allBodyRows.filter((row) => isWordPressProtectedMedia(row)).length;

            const blockEligibleCount = allBodyRows.filter((row) => isBulkSlugRenameSafeMedia(row)).length;
            const extraLocalRenames = [...(preview.localRenameQueue ?? [])];
            const localRenameSeen = new Set(
                extraLocalRenames.map((item) => {
                    const id = Number(item?.seo_media_id ?? 0);
                    if (id > 0) {
                        return `id:${id}`;
                    }

                    return `src:${normalizeImageSrcKey(item?.src)}`;
                }).filter(Boolean),
            );

            let supplementalOrdinal = blockEligibleCount;
            supplementalOnlyRows.forEach((row) => {
                if (isWordPressProtectedMedia(row)) {
                    skippedWordPress += 1;
                    return;
                }
                if (!isBulkSlugRenameSafeMedia(row)) {
                    return;
                }

                supplementalOrdinal += 1;
                const enriched = { ...row, quickFixIndex: supplementalOrdinal };
                const outcome = computeQuickFixSlugSupplementalOutcome(enriched, keyword, {
                    wpOnly: false,
                });

                if (Object.keys(outcome.patch ?? {}).length > 0 && !outcome.wpRename) {
                    patchSupplementalImageRow(enriched, outcome.patch);
                }

                if (outcome.localRename && !outcome.wpRename) {
                    const localId = Number(outcome.localRename.seo_media_id ?? 0);
                    const localKey =
                        localId > 0
                            ? `id:${localId}`
                            : `src:${normalizeImageSrcKey(outcome.localRename.src)}`;
                    if (localKey && !localRenameSeen.has(localKey)) {
                        localRenameSeen.add(localKey);
                        extraLocalRenames.push(outcome.localRename);
                    }
                }
            });

            const mergedPreview = {
                ...preview,
                renameQueue: [],
                localRenameQueue: extraLocalRenames,
            };

            const totalWpRenames = 0;
            const totalLocalRenames = (mergedPreview.localRenameQueue ?? []).length;
            const skippedAlreadyValid = Number(preview.skippedAlreadyValid ?? 0) || 0;
            const eligibleCount = Number(preview.eligibleCount ?? 0) || blockEligibleCount;

            if (totalLocalRenames === 0) {
                let body = t('editor_quick_fix_slug_all_noop_body');
                if (skippedWordPress > 0 && skippedAlreadyValid > 0) {
                    body = t('editor_quick_fix_slug_all_noop_mixed', {
                        wp: skippedWordPress,
                        valid: skippedAlreadyValid,
                    });
                } else if (skippedWordPress > 0) {
                    body = t('editor_quick_fix_slug_all_wp_skipped_only', { count: skippedWordPress });
                } else if (skippedAlreadyValid > 0 || eligibleCount > 0) {
                    body = t('editor_quick_fix_slug_all_noop_already_valid', {
                        count: skippedAlreadyValid || eligibleCount,
                    });
                } else {
                    body = t('editor_quick_fix_slug_all_noop_no_local');
                }
                notifyEditor(
                    t('editor_quick_fix_slug_all_noop_title'),
                    body,
                    'warning',
                );
                return;
            }

            setQuickFixSlugAllBusy(true);
            showArticleOperationOverlay('processing', 'media_slug_fix');
            window.__seoArticleHeavyActionOverlay?.show('sync', {
                persistUntilUnload: true,
                title: 'Đang sửa slug ảnh',
                message: 'Vui lòng không chỉnh sửa bài viết trong lúc đổi slug.',
            });
            setArticleAutosaveLock('quick-fix-slug-all', true);

            await new Promise((resolve) => {
                window.requestAnimationFrame(() => resolve());
            });

            try {
                slugRenameManagedByBatchRef.current = true;

                // Always save trước rename — tránh rename song song với editor dirty / body stale.
                // Docs: docs/article-editor/image-slug-rename.md
                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang lưu bài viết trước khi sửa slug…');
                try {
                    await saveCurrentArticleFromEditor({
                        reason: 'before_fix_slug_all',
                        siteId: Number(siteIdRef.current ?? 0) || 0,
                        keepOverlay: true,
                        silentNotification: true,
                    });
                } catch (saveError) {
                    throw new Error(
                        String(saveError?.message ?? t('editor_try_again_later')),
                    );
                }

                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.(
                    'Đang sửa slug ảnh local…',
                );

                const wpDetail = null;

                let localFixResult = null;
                let localSkipped = 0;
                let localFixed = 0;
                if (totalLocalRenames > 0) {
                    localFixResult = await fixArticleMediaSlugs(
                        articleId,
                        (mergedPreview.localRenameQueue ?? []).map((item) => ({
                            seo_media_id: Number(item?.seo_media_id ?? 0) || null,
                            url: String(item?.src ?? item?.url ?? '').trim(),
                            new_slug: String(item?.new_slug ?? '').trim(),
                            old_slug: String(item?.old_slug ?? '').trim(),
                        })),
                    );
                    localSkipped = Number(localFixResult?.skipped_count ?? 0) || 0;
                    const renamedList = Array.isArray(localFixResult?.renamed)
                        ? localFixResult.renamed
                        : (Array.isArray(localFixResult?.replacements) ? localFixResult.replacements : []);
                    localFixed = renamedList.length > 0
                        ? renamedList.length
                        : Math.max(0, totalLocalRenames - localSkipped);
                }

                // Sync session version after server body rewrite — prevents false Version conflict
                // when after_fix_slug_all save runs against bumped document_version.
                const syncVersionAfterSlugFix = (payload) => {
                    const nextVersion = Number(payload?.document_version ?? 0) || 0;
                    if (nextVersion > 0) {
                        window.__SEO_EDITOR_DOCUMENT_VERSION__ = nextVersion;
                        window.__seoEditorSessionClient?.setDocumentVersion?.(nextVersion);
                    }
                    if (payload?.editor_document_hash) {
                        window.__SEO_EDITOR_DOCUMENT_HASH__ = String(payload.editor_document_hash);
                    }
                    if (payload?.updated_at || payload?.content_hash) {
                        const tokens = getEditorConflictTokens();
                        setEditorConflictTokens({
                            expected_updated_at: payload.updated_at || tokens.expected_updated_at,
                            expected_content_hash: payload.content_hash
                                ? String(payload.content_hash)
                                : tokens.expected_content_hash,
                        });
                    }
                };
                syncVersionAfterSlugFix(localFixResult);

                // Patch editor document/state từ exact rename map — không đoán URL / không chỉ sửa DOM.
                skipNextAutosave.current = true;
                pendingLocalRenameQueueRef.current = [...(mergedPreview.localRenameQueue ?? [])];
                const apiReplacements = Array.isArray(localFixResult?.renamed) && localFixResult.renamed.length > 0
                    ? localFixResult.renamed.map((row) => ({
                        media_id: Number(row?.image_id ?? row?.media_id ?? 0) || null,
                        old_url: String(row?.old_url ?? '').trim(),
                        new_url: String(row?.new_url ?? '').trim(),
                        old_slug: String(row?.old_filename ?? row?.old_slug ?? '').replace(/\.[^.]+$/, ''),
                        new_slug: String(row?.new_slug ?? row?.new_filename ?? '').replace(/\.[^.]+$/, ''),
                    }))
                    : (localFixResult?.replacements ?? []);
                pendingLocalRenameResultsRef.current = mapArticleSlugFixReplacementsToLocalResults(
                    apiReplacements,
                    mergedPreview.localRenameQueue ?? [],
                );
                const applied = applySlugRenameFinished({
                    ...(wpDetail ?? { success: true, renamed: [] }),
                    skipLocalQueueRecovery: true,
                });
                finalizeSlugRenameSideEffects(applied?.wpRenamed ?? [], applied?.localResults ?? []);

                cancelLocalDraftSave();
                window.__seoCancelArticleDraftAutosave?.();
                const htmlAfterFix = getExportHtml();
                const tokensAfterFix = getEditorConflictTokens();
                // Keep server content_hash from slug-fix ACK — TipTap export hash must not
                // poison expected_content_hash before the following persist.
                clearDraft(articleId, connectionHashRef.current, draftScope());
                writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                    content: htmlAfterFix,
                    base_updated_at: tokensAfterFix.expected_updated_at || null,
                    base_content_hash: tokensAfterFix.expected_content_hash || null,
                    version: tokensAfterFix.expected_content_hash || hashContent(htmlAfterFix),
                }));

                // Persist URL mới lần nữa — tránh save sau đó ghi đè body server bằng state cũ.
                window.__seoArticleHeavyActionOverlay?.setStatusMessage?.('Đang lưu URL ảnh mới…');
                try {
                    await saveCurrentArticleFromEditor({
                        reason: 'after_fix_slug_all',
                        siteId: Number(siteIdRef.current ?? 0) || 0,
                        keepOverlay: true,
                        silentNotification: true,
                    });
                } catch (afterSaveError) {
                    const conflictPayload = afterSaveError?.data ?? afterSaveError?.sessionError?.data ?? null;
                    const isVersionConflict = afterSaveError?.conflict === true
                        || String(afterSaveError?.code ?? '').includes('document_version')
                        || String(afterSaveError?.code ?? '').includes('content_hash');
                    if (isVersionConflict && conflictPayload) {
                        syncVersionAfterSlugFix({
                            document_version: conflictPayload?.conflict?.actual_document_version
                                ?? conflictPayload?.document_version,
                            content_hash: conflictPayload?.conflict?.actual_content_hash
                                ?? conflictPayload?.content_hash,
                            updated_at: conflictPayload?.conflict?.actual_updated_at
                                ?? conflictPayload?.updated_at,
                            editor_document_hash: conflictPayload?.editor_document_hash,
                        });
                        // Do not replace ACK content_hash with TipTap export hash.
                        try {
                            await saveCurrentArticleFromEditor({
                                reason: 'after_fix_slug_all_retry',
                                siteId: Number(siteIdRef.current ?? 0) || 0,
                                keepOverlay: true,
                                silentNotification: true,
                            });
                        } catch (retryError) {
                            notifyEditor(
                                t('editor_quick_fix_slug_all_failed_title'),
                                String(retryError?.message ?? t('editor_try_again_later')),
                                'warning',
                            );
                        }
                    } else {
                        notifyEditor(
                            t('editor_quick_fix_slug_all_failed_title'),
                            String(afterSaveError?.message ?? t('editor_try_again_later')),
                            'warning',
                        );
                    }
                }

                const totalDone = localFixed;

                if (localSkipped > 0) {
                    notifyLocalSlugRenameErrors(
                        Array.from({ length: localSkipped }, () => ({
                            message: '',
                        })),
                        Math.max(localSkipped, totalLocalRenames || localSkipped),
                    );
                }

                window.dispatchEvent(new CustomEvent('seo-assistant-widget-health-refresh'));
                notifyEditor(
                    t('editor_quick_fix_slug_all_done_title'),
                    skippedWordPress > 0
                        ? t('editor_quick_fix_slug_all_done_with_wp_skip', {
                            local: totalDone,
                            skipped: skippedWordPress,
                        })
                        : t('editor_quick_fix_slug_all_done_body', {
                            count: totalDone,
                        }),
                    'success',
                );

                showArticleOperationOverlay('success', 'media_slug_fix');
            } catch (error) {
                notifyEditor(
                    t('editor_quick_fix_slug_all_failed_title'),
                    String(error?.message ?? t('editor_try_again_later')),
                    'danger',
                );
            } finally {
                slugRenameManagedByBatchRef.current = false;
                setArticleAutosaveLock('quick-fix-slug-all', false);
                if (window.__seoArticleHeavyActionOverlay) {
                    window.__seoArticleHeavyActionOverlay.persistUntilUnload = false;
                }
                window.__seoEndArticleHeavyActionClient?.();
                setQuickFixSlugAllBusy(false);
            }
        },
        [
            applySlugRenameFinished,
            articleId,
            buildQuickFixContext,
            cancelLocalDraftSave,
            draftScope,
            finalizeSlugRenameSideEffects,
            getExportHtml,
            notifyEditor,
            notifyLocalSlugRenameErrors,
            patchSupplementalImageRow,
            quickFixSlugAllBusy,
            requestWordPressRenames,
            waitForWordPressSlugRenameFinished,
            withDraftSite,
        ],
    );

    const quickFixAltTitleAllImages = useCallback(
        (imageRows = null) => {
            const context = buildQuickFixContext(imageRows);
            if (!context) {
                return;
            }

            const { keyword, sourceRows, supplementalOnlyRows } = context;

            const supplementalOutcomes = supplementalOnlyRows.map((row, index) => ({
                row,
                outcome: computeQuickFixAltTitleSupplementalOutcome(
                    Number(row?.quickFixIndex ?? 0) > 0 ? row : { ...row, quickFixIndex: index + 1 },
                    keyword,
                ),
            }));

            const preview = applyQuickFixAltTitleToBlocks(blocksRef.current, keyword);

            if (preview.applied === 0 && supplementalOutcomes.length === 0) {
                return;
            }

            if (!window.confirm(t('editor_quick_fix_alt_title_all_confirm'))) {
                return;
            }

            setBlocks(preview.blocks);

            sourceRows
                .filter((row) => !row?.excludeQuickFix)
                .forEach((row) => {
                    patchSupplementalImageRow(row, { alt: keyword, title: keyword });
                });

            supplementalOutcomes
                .filter(({ row }) => !row?.excludeQuickFix)
                .forEach(({ row, outcome }) => {
                    patchSupplementalImageRow(row, outcome.patch);
                });

            const seoMetaItems = [];
            const wpMetaItems = [];
            const pushedSeo = new Set();
            const pushedWp = new Set();
            const wpIdsSyncedViaSeo = new Set();

            const enqueueRowMeta = (row, phrase) => {
                const trimmed = String(phrase ?? '').trim();
                if (!trimmed || !row) {
                    return;
                }

                const { seoMediaId, wpAttachmentId } = buildAltTitleMetaUpdatePayload(row, trimmed);

                if (seoMediaId > 0 && !pushedSeo.has(seoMediaId)) {
                    pushedSeo.add(seoMediaId);
                    seoMetaItems.push({
                        id: seoMediaId,
                        alt_text: trimmed,
                        title: trimmed,
                    });
                    if (wpAttachmentId > 0) {
                        wpIdsSyncedViaSeo.add(wpAttachmentId);
                    }
                }

                if (wpAttachmentId > 0 && !pushedWp.has(wpAttachmentId)) {
                    pushedWp.add(wpAttachmentId);
                    wpMetaItems.push({
                        attachment_id: wpAttachmentId,
                        alt_text: trimmed,
                        title: trimmed,
                    });
                }
            };

            sourceRows
                .filter((row) => !row?.excludeQuickFix)
                .forEach((row) => enqueueRowMeta(row, keyword));

            supplementalOutcomes
                .filter(({ row }) => !row?.excludeQuickFix)
                .forEach(({ row, outcome }) => {
                    const phrase = String(outcome?.patch?.alt ?? keyword).trim() || keyword;
                    enqueueRowMeta(row, phrase);
                });

            (preview.wpMetaQueue ?? []).forEach((item) => {
                const attachmentId = Number(item?.attachment_id ?? 0);
                if (attachmentId <= 0 || pushedWp.has(attachmentId)) {
                    return;
                }
                pushedWp.add(attachmentId);
                wpMetaItems.push(item);
            });

            const wpOnlyItems = wpMetaItems.filter(
                (item) => !wpIdsSyncedViaSeo.has(Number(item.attachment_id ?? 0)),
            );

            const finishNotify = (wpCount, localCount, errorMessage = null) => {
                if (errorMessage) {
                    notifyEditor(
                        t('editor_cannot_update_image_meta'),
                        errorMessage,
                        'danger',
                    );
                    return;
                }

                const total = Math.max(localCount, wpCount, preview.applied);
                notifyEditor(
                    t('editor_quick_fix_alt_title_all_done_title'),
                    wpCount > 0
                        ? t('editor_quick_fix_alt_title_all_done_body_wp', {
                              count: total,
                              wp: wpCount,
                          })
                        : t('editor_quick_fix_alt_title_all_done_body', { count: total }),
                    'success',
                );
            };

            const seoPromise =
                seoMetaItems.length > 0
                    ? updateSeoMediaMeta(seoMetaItems)
                    : Promise.resolve({ updated_count: 0, wp_updated_count: 0 });

            seoPromise
                .then((data) => {
                    const localCount = Number(data?.updated_count ?? seoMetaItems.length);
                    const wpFromSeo = Number(data?.wp_updated_count ?? 0);

                    if (wpOnlyItems.length > 0) {
                        // Một lần Livewire → một toast Filament (không spam từng ảnh).
                        requestWordPressAttachmentMetaUpdate(wpOnlyItems);
                        return;
                    }

                    finishNotify(wpFromSeo, localCount || preview.applied);
                })
                .catch((error) => {
                    if (wpOnlyItems.length > 0) {
                        // Vẫn đẩy WP batch; toast Filament báo kết quả WP.
                        requestWordPressAttachmentMetaUpdate(wpOnlyItems);
                        return;
                    }
                    finishNotify(0, 0, error?.message ?? t('editor_try_again_later'));
                });

            if (seoMetaItems.length === 0 && wpOnlyItems.length === 0 && preview.applied > 0) {
                finishNotify(0, preview.applied);
            }

            setImagesReloadKey((k) => k + 1);
        },
        [
            buildQuickFixContext,
            notifyEditor,
            patchSupplementalImageRow,
            requestWordPressAttachmentMetaUpdate,
        ],
    );

    const quickFixSlugSingleImage = useCallback(
        async (target) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword || !target) {
                return;
            }

            const rowHint = typeof target === 'object' ? target : null;
            const blockId =
                typeof target === 'string'
                    ? target
                    : String(target?.blockId ?? target?.block_id ?? '').trim();

            const resolveRow = () => {
                if (rowHint && typeof rowHint === 'object') {
                    return rowHint;
                }
                if (blockId) {
                    return collectImagesFromBlocks(blocksRef.current).find(
                        (entry) => entry.blockId === blockId,
                    ) ?? null;
                }

                return null;
            };
            const maybeWpRow = resolveRow();
            if (maybeWpRow && isWordPressProtectedMedia(maybeWpRow)) {
                window.dispatchEvent(new CustomEvent('seo-wordpress-media-rename-open', {
                    detail: {
                        siteId: Number(siteIdRef.current ?? 0) || 0,
                        articleId,
                        attachmentId: Number(
                            maybeWpRow.wpAttachmentId ?? maybeWpRow.wp_attachment_id ?? 0,
                        ),
                        oldUrl: resolveWpRenameOldUrl(maybeWpRow),
                        previewUrl: String(maybeWpRow.src ?? maybeWpRow.url ?? '').trim(),
                        currentSlug: String(maybeWpRow.slug ?? '').trim(),
                        sourceAction: 'article_editor',
                    },
                }));

                return;
            }

            if (blockId) {
                const enrichmentRow = rowHint ?? collectImagesFromBlocks(blocksRef.current).find(
                    (entry) => entry.blockId === blockId,
                );
                const preview = applyQuickFixSlugToBlock(
                    blocksRef.current,
                    keyword,
                    blockId,
                    enrichmentRow,
                    { wpOnly: false, includeWordPressRenames: false },
                );
                if (preview.applied === 0) {
                    return;
                }

                const renameCount = preview.renameQueue.length;
                const localRenameCount = (preview.localRenameQueue ?? []).length;

                if (renameCount > 0 && !confirmSlugRename({ count: 1, isQuickFix: true })) {
                    return;
                }

                if (renameCount === 0 && localRenameCount === 0) {
                    notifyEditor(
                        t('editor_quick_fix_slug_all_noop_title'),
                        t('editor_quick_fix_slug_all_noop_body'),
                        'warning',
                    );
                    return;
                }

                await applyQuickFixSlugPreview(preview, keyword);

                if (renameCount === 0 && (pendingLocalRenameResultsRef.current?.length ?? 0) > 0) {
                    applySlugRenameFinished({ renamed: [] });
                    finalizeSlugRenameSideEffects();
                }

                return;
            }

            const row = typeof target === 'object' ? target : null;
            if (!row || row.excludeQuickFix) {
                return;
            }

            const sourceRows = supplementalImages ?? [];
            const fallbackIndex = Math.max(
                1,
                sourceRows.findIndex((item) => {
                    const srcMatched =
                        normalizeImageSrcKey(item?.src) !== '' &&
                        normalizeImageSrcKey(item?.src) === normalizeImageSrcKey(row?.src);
                    const wpMatched =
                        Number(item?.wpAttachmentId ?? item?.wp_attachment_id ?? 0) > 0 &&
                        Number(item?.wpAttachmentId ?? item?.wp_attachment_id ?? 0) ===
                            Number(row?.wpAttachmentId ?? row?.wp_attachment_id ?? 0);
                    const seoMatched =
                        Number(item?.seoMediaId ?? item?.seo_media_id ?? 0) > 0 &&
                        Number(item?.seoMediaId ?? item?.seo_media_id ?? 0) ===
                            Number(row?.seoMediaId ?? row?.seo_media_id ?? 0);
                    return srcMatched || wpMatched || seoMatched;
                }) + 1,
            );

            const enrichedRow = enrichSupplementalRow(row, fallbackIndex);
            const outcome = computeQuickFixSlugSupplementalOutcome(enrichedRow, keyword, {
                wpOnly: false,
            });

            if (Object.keys(outcome.patch ?? {}).length > 0) {
                patchSupplementalImageRow(enrichedRow, outcome.patch);
            }

            if (!outcome?.wpRename && !outcome?.localRename) {
                notifyEditor(
                    t('editor_quick_fix_slug_all_noop_title'),
                    t('editor_quick_fix_slug_all_noop_body'),
                    'warning',
                );
                return;
            }

            if (outcome?.wpRename) {
                if (!confirmSlugRename({ count: 1, isQuickFix: true })) {
                    return;
                }
            }

            await applyQuickFixSlugPreview(
                {
                    applied: 1,
                    renameQueue: outcome?.wpRename ? [outcome.wpRename] : [],
                    localRenameQueue: outcome?.localRename ? [outcome.localRename] : [],
                },
                keyword,
            );

            if (!outcome?.wpRename && (pendingLocalRenameResultsRef.current?.length ?? 0) > 0) {
                applySlugRenameFinished({ renamed: [] });
                finalizeSlugRenameSideEffects();
            }
        },
        [
            applyQuickFixSlugPreview,
            applySlugRenameFinished,
            articleTitle,
            enrichSupplementalRow,
            finalizeSlugRenameSideEffects,
            focusKeyword,
            notifyEditor,
            patchSupplementalImageRow,
            supplementalImages,
        ],
    );

    const quickFixAltTitleSingleImage = useCallback(
        (target) => {
            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword || !target) {
                return;
            }

            const blockId =
                typeof target === 'string'
                    ? target
                    : String(target?.blockId ?? target?.block_id ?? '').trim();

            if (blockId) {
                const preview = applyQuickFixAltTitleToBlock(blocksRef.current, keyword, blockId);
                if (preview.applied === 0) {
                    return;
                }

                if (!window.confirm(t('editor_quick_fix_alt_title_one_confirm'))) {
                    return;
                }

                setBlocks(preview.blocks);
                const row = collectImagesFromBlocks(blocksRef.current).find(
                    (entry) => entry.blockId === blockId,
                );
                if (row) {
                    pushAltTitleMetaToStores(row, keyword);
                }
                setImagesReloadKey((k) => k + 1);

                return;
            }

            const row = typeof target === 'object' ? target : null;
            if (!row || row.excludeQuickFix) {
                return;
            }

            if (!window.confirm(t('editor_quick_fix_alt_title_one_confirm'))) {
                return;
            }

            const outcome = computeQuickFixAltTitleSupplementalOutcome(row, keyword);
            patchSupplementalImageRow(row, outcome.patch);
            pushAltTitleMetaToStores(row, keyword);
            setImagesReloadKey((k) => k + 1);
        },
        [
            focusKeyword,
            articleTitle,
            patchSupplementalImageRow,
            pushAltTitleMetaToStores,
        ],
    );

    return { applySlugRenameFinished, armBlockOutsideClickGuard, assertNoLocalSlugFixBeforeWpSync, handleImageAltTitleChange, patchImageInBlocks, persistEditorContentImmediately, quickFixAltTitleAllImages, quickFixAltTitleSingleImage, quickFixSlugAllImages, quickFixSlugSingleImage, selectPlainTextInBlock };
}
