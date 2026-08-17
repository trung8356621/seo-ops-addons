import { AI_PLACEHOLDER_LOADING_URL, fetchArticleAiMediaJobs } from '@media-addon/utils/seoMediaApi.js';
import { EDITOR_COMMAND_CODES } from '../utils/editorCommands/editorCommandResult';
import {
    blockHasOutlineHeading,
    buildEditorSections,
    createEmptyTextBlock,
    dispatchActiveBlockContext,
    findBlockIdForOutlineHeading,
    getBlocksInRange,
    isSectionHeadingBlock,
    mergeBlockHtmlContents,
    outlineApiRequest,
    outlineHeadingKey,
} from '../utils/contentDocumentHelpers';
import { applyReplaceBlocksAt } from '../utils/editorCommands/replaceBlocksAt';
import { extractOutlineHeadingFromBlock, normalizeOutlineHeadingText } from '../utils/articleEditorClientOutline';
import { normalizeBlocks } from '@media-addon/utils/blockImageUtils.js';
import { persistBlockHtmlFromEditor } from '../utils/editorHtmlUtils';
import { reorderBlockWithinSection } from '../utils/articleEditorBlockReorder';
import { t } from '../utils/i18n';
import { useCallback, useEffect } from 'react';

/**
 * useArticleEditorInsertAndSections - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorInsertAndSections({ activeBlockId, activeBlockIdRef, applyCompletedMediaToPlaceholder, articleId, blockEditorsRef, blockFlushRef, blocks, blocksRef, commitActiveBlock, deleteBlock, dismissedEditorImageMediaIdsRef, editorSections, insertBlockRelative = null, notifyIntroNoImages, outlineAppendDoneRef, outlineAppendInflightRef, outlineHasSavedHeadings, outlineHeadingIdsByBlockIdRef, patchImageInBlocks, pendingAiMediaRef, sectionHeadingBlockIds, setActiveBlockId, setBlocks, setGlobalEditor, setInsertMenu, setOutlineHeadingKeys, setOutlineTreeSync, setTempMerge, startMediaStatusPolling, structureMutationRef, tempMergeRef }) {
    useEffect(() => {
        if (!articleId) {
            return undefined;
        }

        const hasProcessingPlaceholder = blocks.some(
            (block) => block?.type === 'image' && Boolean(block?.image?.isProcessing),
        );
        if (!hasProcessingPlaceholder) {
            return undefined;
        }

        let cancelled = false;

        const reconcile = async () => {
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
                    if (mediaId <= 0 || mediaType === 'video') {
                        continue;
                    }
                    if (dismissedEditorImageMediaIdsRef.current.has(mediaId)) {
                        continue;
                    }

                    if (status === 'completed' && jobUrl && !jobUrl.includes('placeholder-loading')) {
                        if (applyCompletedMediaToPlaceholder(mediaId, 'image', jobUrl)) {
                            return;
                        }
                    }

                    if (status === 'processing') {
                        const unbound = blocksRef.current.find((block) => {
                            if (block?.type !== 'image' || !block?.image?.isProcessing) {
                                return false;
                            }
                            const seoId = Number(block.image?.seoMediaId ?? block.image?.seo_media_id ?? 0);
                            return seoId <= 0 || seoId === mediaId;
                        });
                        if (unbound) {
                            const seoId = Number(unbound.image?.seoMediaId ?? unbound.image?.seo_media_id ?? 0);
                            if (seoId !== mediaId) {
                                patchImageInBlocks(
                                    unbound.id,
                                    {
                                        seoMediaId: mediaId,
                                        isProcessing: true,
                                        src: jobUrl || AI_PLACEHOLDER_LOADING_URL,
                                    },
                                    true,
                                );
                            }
                            pendingAiMediaRef.current.set(mediaId, {
                                blockId: unbound.id,
                                mediaType: 'image',
                            });
                            startMediaStatusPolling(mediaId, 'image');
                            return;
                        }
                    }
                }
            } catch {
                // ignore transient API errors
            }
        };

        void reconcile();
        const timer = window.setInterval(reconcile, 8_000);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [
        articleId,
        applyCompletedMediaToPlaceholder,
        blocks,
        patchImageInBlocks,
        startMediaStatusPolling,
    ]);

    const insertVideoAfterBlock = useCallback(
        (refBlockId, videoUrl) => {
            const url = (videoUrl ?? '').trim();
            if (!refBlockId || !url) {
                return;
            }
            if (tempMergeRef.current) {
                return;
            }

            commitActiveBlock();

            const safeUrl = url
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;');
            const newBlock = {
                ...createEmptyTextBlock(),
                content: `<figure class="wp-block-video"><video controls src="${safeUrl}"></video></figure>`,
            };

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) {
                    return prev;
                }
                const next = [...prev];
                next.splice(index + 1, 0, newBlock);
                return normalizeBlocks(next);
            });

            setActiveBlockId(newBlock.id);
            setGlobalEditor(null);
        },
        [commitActiveBlock],
    );

    const toggleInsertMenu = useCallback((blockId, position) => {
        setInsertMenu((current) =>
            current?.blockId === blockId && current?.position === position
                ? null
                : { blockId, position },
        );
    }, []);

    const moveBlockToSection = useCallback(
        (blockId, direction) => {
            if (tempMergeRef.current) {
                return;
            }

            const id = String(blockId ?? '').trim();
            if (!id) {
                return;
            }

            const movingBlock = blocksRef.current.find((block) => block.id === id);
            if (
                movingBlock &&
                outlineHasSavedHeadings &&
                blockHasOutlineHeading(movingBlock) &&
                !sectionHeadingBlockIds.has(id)
            ) {
                return;
            }

            const currentSectionIndex = editorSections.findIndex((section) => section.blockIds.includes(id));
            if (currentSectionIndex < 0) {
                return;
            }

            const targetSectionIndex =
                direction === 'prev' ? currentSectionIndex - 1 : currentSectionIndex + 1;
            if (targetSectionIndex < 0 || targetSectionIndex >= editorSections.length) {
                return;
            }

            const targetSection = editorSections[targetSectionIndex];
            const targetIds = (targetSection?.blockIds ?? []).filter((candidateId) => candidateId !== id);

            setInsertMenu(null);

            const activeId = activeBlockIdRef.current;
            let flushedHtml = null;
            const activeEditor = activeId ? blockEditorsRef.current.get(activeId) : null;

            if (activeEditor && !activeEditor.isDestroyed) {
                const sourceHtml = blocksRef.current.find((block) => block.id === activeId)?.content ?? '';
                flushedHtml = persistBlockHtmlFromEditor(sourceHtml, activeEditor.getHTML());
            } else {
                blockFlushRef.current?.();
            }

            setBlocks((prev) => {
                let working = prev;
                if (flushedHtml != null && activeId) {
                    working = working.map((block) =>
                        block.id === activeId ? { ...block, content: flushedHtml } : block,
                    );
                }

                const fromIndex = working.findIndex((block) => block.id === id);
                if (fromIndex < 0) {
                    return prev;
                }

                const moving = working[fromIndex];
                if (targetSection?.isIntro && moving?.type === 'image') {
                    notifyIntroNoImages();

                    return prev;
                }

                const next = [...working];
                next.splice(fromIndex, 1);

                let insertAt = next.length;
                if (direction === 'prev') {
                    const lastTargetId = targetIds[targetIds.length - 1];
                    const lastIndex = lastTargetId ? next.findIndex((block) => block.id === lastTargetId) : -1;
                    insertAt = lastIndex >= 0 ? lastIndex + 1 : 0;
                } else {
                    const lastTargetId = targetIds[targetIds.length - 1];
                    const lastIndex = lastTargetId ? next.findIndex((block) => block.id === lastTargetId) : -1;
                    insertAt = lastIndex >= 0 ? lastIndex + 1 : next.length;
                }

                next.splice(insertAt, 0, moving);

                return next;
            });

            setActiveBlockId(id);
            setGlobalEditor(null);
        },
        [editorSections, notifyIntroNoImages, outlineHasSavedHeadings, sectionHeadingBlockIds],
    );

    const applyMoveBlockWithinSectionMutation = (payload = {}) => {
        const command = 'move_block_within_section';
        const blockId = String(payload.blockId ?? '').trim();
        const direction = String(payload.direction ?? '').trim().toLowerCase();
        let sectionId = String(payload.sectionId ?? payload.section_id ?? '').trim();

        const fail = (code, meta = {}) => ({
            ok: false,
            code,
            command,
            editor_id: blockId || null,
            transaction_applied: false,
            document_changed: false,
            selection_changed: false,
            new_selection: null,
            history_step: false,
            error: { code, message_key: `editor_command.${code}` },
            meta,
        });

        if (tempMergeRef.current) {
            return fail(EDITOR_COMMAND_CODES.NO_CHANGE);
        }
        if (!blockId) {
            return fail(EDITOR_COMMAND_CODES.TARGET_MISSING);
        }
        if (direction !== 'up' && direction !== 'down') {
            return fail(EDITOR_COMMAND_CODES.SELECTION_INVALID, { direction });
        }

        const sections = buildEditorSections(blocksRef.current);
        const section = sectionId
            ? sections.find((row) => row.id === sectionId)
            : sections.find((row) => (row.blockIds ?? []).includes(blockId));
        if (!section) {
            return fail('section_missing', { blockId, sectionId: sectionId || null });
        }
        if (sectionId && section.id !== sectionId) {
            return fail(EDITOR_COMMAND_CODES.SECTION_MISMATCH, { blockId, sectionId });
        }
        if (!(section.blockIds ?? []).includes(blockId)) {
            return fail(EDITOR_COMMAND_CODES.SECTION_MISMATCH, { blockId, sectionId: section.id });
        }
        sectionId = section.id;

        setInsertMenu(null);

        const activeId = activeBlockIdRef.current;
        let flushedHtml = null;
        const activeEditor = activeId ? blockEditorsRef.current.get(activeId) : null;
        if (activeEditor && !activeEditor.isDestroyed) {
            const sourceHtml = blocksRef.current.find((block) => block.id === activeId)?.content ?? '';
            flushedHtml = persistBlockHtmlFromEditor(sourceHtml, activeEditor.getHTML());
        } else {
            blockFlushRef.current?.();
        }

        let working = blocksRef.current;
        if (flushedHtml != null && activeId) {
            working = working.map((block) =>
                block.id === activeId ? { ...block, content: flushedHtml } : block,
            );
        }

        // Re-resolve section ids against flushed working set (same ids, same ownership).
        const liveSections = buildEditorSections(working);
        const liveSection = liveSections.find((row) => row.id === sectionId)
            ?? liveSections.find((row) => (row.blockIds ?? []).includes(blockId));
        if (!liveSection) {
            return fail('section_missing', { blockId, sectionId });
        }

        const result = reorderBlockWithinSection(working, {
            blockId,
            direction,
            sectionBlockIds: liveSection.blockIds,
            sectionId: liveSection.id,
        });

        if (!result.ok) {
            return fail(result.code, {
                sectionId: liveSection.id,
                fromIndex: result.fromIndex,
                toIndex: result.toIndex,
            });
        }

        // One setBlocks → one history step. Do not normalizeBlocks (avoids new image ids).
        blocksRef.current = result.blocks;
        setBlocks(result.blocks);
        setActiveBlockId(blockId);
        setGlobalEditor(null);

        return {
            ok: true,
            code: EDITOR_COMMAND_CODES.MOVED,
            command,
            editor_id: blockId,
            transaction_applied: true,
            document_changed: true,
            selection_changed: false,
            new_selection: null,
            history_step: true,
            error: null,
            meta: {
                sectionId: liveSection.id,
                fromIndex: result.fromIndex,
                toIndex: result.toIndex,
            },
        };
    };

    structureMutationRef.current = (name, payload) => {
        if (name === 'delete_block') {
            deleteBlock(payload.blockId, { skipConfirm: Boolean(payload.skipConfirm) });
            return true;
        }
        if (name === 'replace_article_document' && Array.isArray(payload.blocks)) {
            setBlocks(payload.blocks);
            return true;
        }
        if (name === 'move_block_within_section') {
            return applyMoveBlockWithinSectionMutation(payload);
        }
        if (name === 'move_block_to_adjacent_section') {
            const direction = payload.direction === 'prev' || payload.direction === 'next'
                ? payload.direction
                : null;
            if (!direction) {
                return {
                    ok: false,
                    code: EDITOR_COMMAND_CODES.SELECTION_INVALID,
                    command: name,
                    editor_id: payload.blockId ?? null,
                    transaction_applied: false,
                    document_changed: false,
                    selection_changed: false,
                    new_selection: null,
                    history_step: false,
                    error: {
                        code: EDITOR_COMMAND_CODES.SELECTION_INVALID,
                        message_key: 'editor_command.selection_invalid',
                    },
                    meta: {},
                };
            }
            moveBlockToSection(payload.blockId, direction);
            return true;
        }
        if (name === 'insert_block_relative' && typeof insertBlockRelative === 'function') {
            insertBlockRelative(
                payload.blockId,
                payload.position === 'before' ? 'before' : 'after',
                payload.type || 'text',
            );
            return true;
        }
        if (name === 'replace_blocks_at') {
            if (tempMergeRef.current) {
                return {
                    ok: false,
                    code: EDITOR_COMMAND_CODES.NO_CHANGE,
                    document_changed: false,
                    meta: { reason: 'temp_merge' },
                };
            }
            const sourceBlockId = String(payload.sourceBlockId ?? payload.blockId ?? '').trim();
            const beforeIds = blocksRef.current.map((block) => block.id);
            const applied = applyReplaceBlocksAt(blocksRef.current, {
                sourceBlockId,
                replacements: payload.replacements ?? payload.contents,
                preserveSourceId: payload.preserveSourceId !== false,
                focusIndex: payload.focusIndex,
                createBlock: createEmptyTextBlock,
            });
            if (!applied.ok) {
                return {
                    ok: false,
                    code: applied.reason === 'source_missing'
                        ? EDITOR_COMMAND_CODES.TARGET_MISSING
                        : EDITOR_COMMAND_CODES.NO_CHANGE,
                    document_changed: false,
                    transaction_applied: false,
                    meta: {
                        reason: applied.reason,
                        sourceBlockId,
                        before: beforeIds,
                        after: beforeIds,
                    },
                };
            }

            const focusIndex = payload.focusIndex == null ? null : Number(payload.focusIndex);
            const created = applied.blocks.slice(
                applied.sourceIndex,
                applied.sourceIndex + applied.createdIds.length,
            );
            const focusBlock = Number.isFinite(focusIndex) && focusIndex >= 0
                ? created[focusIndex] ?? created[created.length - 1] ?? null
                : created.find((block) => !/^<h[2-4]\b/i.test(String(block.content ?? ''))) ?? created[0];
            const focusPos = Number.isFinite(focusIndex)
                && focusIndex === 0
                && created.length > 1
                ? 'end'
                : 'start';

            blockFlushRef.current = null;
            blockEditorsRef.current.delete(sourceBlockId);
            setGlobalEditor(null);
            setInsertMenu(null);
            setBlocks(applied.blocks);
            blocksRef.current = applied.blocks;
            activeBlockIdRef.current = focusBlock?.id ?? null;
            setActiveBlockId(focusBlock?.id ?? null);

            if (typeof window !== 'undefined') {
                window.requestAnimationFrame(() => {
                    window.requestAnimationFrame(() => {
                        const editor = blockEditorsRef.current.get(focusBlock?.id);
                        try {
                            editor?.commands?.focus?.(focusPos);
                        } catch {
                            // remount may still be pending
                        }
                    });
                });
            }

            return {
                ok: true,
                code: EDITOR_COMMAND_CODES.UPDATED,
                document_changed: true,
                transaction_applied: true,
                meta: {
                    reason: null,
                    sourceBlockId,
                    createdIds: applied.createdIds,
                    focusBlockId: focusBlock?.id ?? null,
                    beforeCount: applied.beforeCount,
                    afterCount: applied.afterCount,
                    before: beforeIds,
                    after: applied.blocks.map((block) => block.id),
                },
            };
        }
        return false;
    };

    const moveSection = useCallback(
        (sectionId, direction) => {
            if (tempMergeRef.current) {
                return;
            }

            const sections = editorSections;
            const currentIndex = sections.findIndex((section) => section.id === sectionId);
            if (currentIndex < 0) {
                return;
            }

            const section = sections[currentIndex];
            if (section?.isIntro) {
                return;
            }

            const headingBlock = blocksRef.current.find((block) => block.id === section.blockIds[0]);
            if (!isSectionHeadingBlock(headingBlock, section)) {
                return;
            }

            const targetIndex = direction === 'prev' ? currentIndex - 1 : currentIndex + 1;
            if (targetIndex < 0 || targetIndex >= sections.length) {
                return;
            }

            const targetSection = sections[targetIndex];
            if (targetSection?.isIntro) {
                return;
            }

            setInsertMenu(null);

            const activeId = activeBlockIdRef.current;
            let flushedHtml = null;
            const activeEditor = activeId ? blockEditorsRef.current.get(activeId) : null;

            if (activeEditor && !activeEditor.isDestroyed) {
                const sourceHtml = blocksRef.current.find((block) => block.id === activeId)?.content ?? '';
                flushedHtml = persistBlockHtmlFromEditor(sourceHtml, activeEditor.getHTML());
            } else {
                blockFlushRef.current?.();
            }

            setBlocks((prev) => {
                let working = prev;
                if (flushedHtml != null && activeId) {
                    working = working.map((block) =>
                        block.id === activeId ? { ...block, content: flushedHtml } : block,
                    );
                }

                const fromBlocks = section.blockIds
                    .map((blockId) => working.find((block) => block.id === blockId))
                    .filter(Boolean);
                if (fromBlocks.length !== section.blockIds.length) {
                    return prev;
                }

                const withoutMoved = working.filter((block) => !section.blockIds.includes(block.id));
                const targetStart = withoutMoved.findIndex((block) => block.id === targetSection.blockIds[0]);
                if (targetStart < 0) {
                    return prev;
                }

                const insertAt =
                    direction === 'prev'
                        ? targetStart
                        : targetStart + targetSection.blockIds.length;

                return [
                    ...withoutMoved.slice(0, insertAt),
                    ...fromBlocks,
                    ...withoutMoved.slice(insertAt),
                ];
            });

            setActiveBlockId(section.blockIds.find((blockId) => !sectionHeadingBlockIds.has(blockId)) ?? section.blockIds[0] ?? null);
            setGlobalEditor(null);
        },
        [editorSections, sectionHeadingBlockIds],
    );

    const deleteSection = useCallback(
        (section, options = {}) => {
            if (section?.isIntro) {
                return;
            }

            const headingBlockId = section.blockIds[0];
            const headingBlock = blocksRef.current.find((block) => block.id === headingBlockId);
            if (!isSectionHeadingBlock(headingBlock, section)) {
                return;
            }

            if (blocksRef.current.length <= section.blockIds.length) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('cannot_delete_last_block'),
                            body: t('editor_delete_section_last_block_hint'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            const skipConfirm = options.skipConfirm === true;
            if (!skipConfirm && !window.confirm(t('editor_delete_section_confirm'))) {
                return;
            }

            commitActiveBlock();
            setInsertMenu(null);

            const idsToRemove = new Set(section.blockIds);
            if (activeBlockId && idsToRemove.has(activeBlockId)) {
                blockFlushRef.current = null;
                setActiveBlockId(null);
                setGlobalEditor(null);
                dispatchActiveBlockContext(articleId, '', '', false, null);
            }

            setBlocks((prev) => {
                const next = prev.filter((block) => !idsToRemove.has(block.id));
                return next.length > 0 ? normalizeBlocks(next) : prev;
            });

            outlineAppendDoneRef.current.delete(headingBlockId);
            outlineAppendInflightRef.current.delete(headingBlockId);

            const headingId = outlineHeadingIdsByBlockIdRef.current.get(headingBlockId);
            if (headingId != null) {
                outlineHeadingIdsByBlockIdRef.current.delete(headingBlockId);
                void outlineApiRequest(articleId, `/${headingId}`, { method: 'DELETE' }).catch(() => {});
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'remove',
                    headingId,
                });
            } else {
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'remove',
                    headingId: `pending-${headingBlockId}`,
                });
                const meta = extractOutlineHeadingFromBlock(headingBlock);
                if (meta) {
                    setOutlineHeadingKeys((prev) => {
                        const next = new Set(prev);
                        next.delete(outlineHeadingKey(meta.level, meta.headingText));
                        return next;
                    });
                }
            }
        },
        [activeBlockId, articleId, commitActiveBlock],
    );

    const resolveSectionForOutlineNode = useCallback(
        (node) => {
            if (!node || Number(node.level) !== 2) {
                return null;
            }

            for (const section of editorSections) {
                if (section.isIntro) {
                    continue;
                }

                const blockId = section.blockIds[0];
                const headingId = outlineHeadingIdsByBlockIdRef.current.get(blockId);
                if (headingId != null && Number(headingId) === Number(node.id)) {
                    return section;
                }
            }

            const blockId = findBlockIdForOutlineHeading(
                blocksRef.current,
                Number(node.level),
                String(node.heading_text ?? ''),
            );
            if (!blockId) {
                return null;
            }

            const section = editorSections.find((item) => item.blockIds[0] === blockId) ?? null;
            if (!section || section.isIntro) {
                return null;
            }

            const block = blocksRef.current.find((item) => item.id === blockId);
            if (!isSectionHeadingBlock(block, section)) {
                return null;
            }

            return section;
        },
        [editorSections],
    );

    const removeHeadingFromBlocks = useCallback((level, headingText) => {
        const targetLevel = Number(level) || 0;
        const target = normalizeOutlineHeadingText(headingText);
        if (target === '') {
            return;
        }

        const selector = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2, h3, h4';

        setBlocks((prev) =>
            prev.map((block) => {
                if (block.type !== 'text' || !block.content) {
                    return block;
                }

                const doc = new DOMParser().parseFromString(block.content, 'text/html');
                const headingNode = Array.from(doc.body.querySelectorAll(selector)).find(
                    (node) => normalizeOutlineHeadingText(node.textContent) === target,
                );
                if (!headingNode) {
                    return block;
                }

                headingNode.remove();
                const nextContent = doc.body.innerHTML.trim();

                return {
                    ...block,
                    content: nextContent !== '' ? nextContent : '<p></p>',
                };
            }),
        );
    }, []);

    const purgeOutlineHeadingState = useCallback((node) => {
        const level = Number(node?.level ?? 0);
        const text = normalizeOutlineHeadingText(node?.heading_text);

        for (const [blockId, headingId] of outlineHeadingIdsByBlockIdRef.current.entries()) {
            if (Number(headingId) === Number(node?.id)) {
                outlineHeadingIdsByBlockIdRef.current.delete(blockId);
                outlineAppendDoneRef.current.delete(blockId);
            }
        }

        if (text !== '') {
            setOutlineHeadingKeys((prev) => {
                const next = new Set(prev);
                next.delete(outlineHeadingKey(level, text));
                return next;
            });
        }
    }, []);

    const handleOutlineMoveHeading = useCallback(
        (node, direction) => {
            if (!node) {
                return;
            }

            const level = Number(node.level ?? 0);

            if (level === 2) {
                const section = resolveSectionForOutlineNode(node);
                if (section) {
                    moveSection(section.id, direction);
                }

                return;
            }

            const blockId = findBlockIdForOutlineHeading(
                blocksRef.current,
                level,
                String(node.heading_text ?? ''),
            );
            if (!blockId) {
                return;
            }

            const section = editorSections.find((item) => item.blockIds[0] === blockId) ?? null;
            if (!section || section.isIntro) {
                return;
            }

            const block = blocksRef.current.find((item) => item.id === blockId);
            if (!isSectionHeadingBlock(block, section)) {
                return;
            }

            moveSection(section.id, direction);
        },
        [editorSections, moveSection, resolveSectionForOutlineNode],
    );

    const handleOutlineDeleteHeading = useCallback(
        (node) => {
            if (!node?.id) {
                return;
            }

            const level = Number(node.level ?? 0);

            if (level === 2) {
                const section = resolveSectionForOutlineNode(node);
                if (section) {
                    deleteSection(section);

                    return;
                }

                if (!window.confirm(t('editor_delete_section_confirm'))) {
                    return;
                }

                purgeOutlineHeadingState(node);
                void outlineApiRequest(articleId, `/${node.id}`, { method: 'DELETE' }).catch(() => {});
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'remove',
                    headingId: node.id,
                });

                return;
            }

            if (
                !window.confirm(
                    `Xóa heading H${level} "${String(node.heading_text ?? '').trim()}" khỏi bài viết?`,
                )
            ) {
                return;
            }

            commitActiveBlock();
            removeHeadingFromBlocks(level, node.heading_text);
            purgeOutlineHeadingState(node);
            void outlineApiRequest(articleId, `/${node.id}`, { method: 'DELETE' }).catch(() => {});
            setOutlineTreeSync({
                token: Date.now(),
                action: 'remove',
                headingId: node.id,
            });
        },
        [
            articleId,
            commitActiveBlock,
            deleteSection,
            purgeOutlineHeadingState,
            removeHeadingFromBlocks,
            resolveSectionForOutlineNode,
        ],
    );

    const startTempMerge = useCallback(
        (targetId) => {
            if (!activeBlockId || !targetId || activeBlockId === targetId) return;

            const rangeBlocks = getBlocksInRange(blocksRef.current, activeBlockId, targetId);
            if (rangeBlocks.length < 2) return;

            setTempMerge({
                anchorId: activeBlockId,
                rangeIds: rangeBlocks.map((b) => b.id),
                mergedHtml: mergeBlockHtmlContents(rangeBlocks),
            });
        },
        [activeBlockId],
    );

    return { deleteSection, handleOutlineDeleteHeading, handleOutlineMoveHeading, insertVideoAfterBlock, moveBlockToSection, startTempMerge, toggleInsertMenu };
}
