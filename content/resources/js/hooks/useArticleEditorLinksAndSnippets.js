import { SEO_EDITOR_LINK_CLASS, SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS } from '../utils/articleEditorTransientMarkup';
import { TIPTAP_HTML_PARSE_OPTIONS } from '../utils/inlineWhitespaceGuard';
import { assertWritableEditorSession } from '../utils/editorSessionState';
import {
    captureEditorInsertionContext,
    clearFrozenEditorInsertionContext,
    getEditorInsertionContext,
    getFrozenEditorInsertionContext,
    getInsertionContextForCommand,
    scrollElementIntoViewIfNeeded,
    syncInsertionContextFromLiveEditors,
} from '../utils/editorInsertionContext';
import {
    countMatchingAnchorsInHtml,
    countPlainTextInHtml,
    findBlockIdForExportOffset,
    removeMatchingAnchorsFromHtml,
    scrollToFaqByIndex,
    scrollToFaqKeyword,
    scrollToKeywordAnchor,
    scrollToPlainTextInBlock,
} from '../utils/articleLinkScroll';
import { executeEditorCommand } from '../utils/editorCommands';
import { exportBlocksToHtml, getPlainTextFromBlocks } from '../utils/contentDocumentHelpers';
import {
    filterSuggestedInternalLinks,
    isInternalHrefForSite,
    normalizeHrefForCompare,
    normalizeLinkLabel,
} from '../utils/articleLinkSuggestionFilter';
import { isCtaPlainTextType } from '../utils/ctaLinkFormat';
import { replaceFirstPlainTextWithLink, replaceFirstPlainTextWithText, wrapPlainTextWithLinkInBlocks } from '../utils/articleLinkInsert';
import { saveDraft } from '../utils/articleEditorStorage';
import { t } from '../utils/i18n';
import { useCallback, useEffect } from 'react';

/**
 * useArticleEditorLinksAndSnippets - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorLinksAndSnippets({ activeBlockId, activeBlockIdRef, applySlugRenameFinished, articleId, articleTitle, blockById, blockEditorsRef, blockFlushRef, blocksRef, clearTempMerge, commitActiveBlock, connectionHashRef, editorSections, focusKeyword, intraSelectionRef, linkScrollTokenRef, notifyIntroNoImages, persistEditorContentImmediately, requestAnalyze, scheduleAutosave, sectionByBlockId, selectPlainTextInBlock, setActiveBlockId, setBlocks, setCollapsedSectionIds, setExtractedLinks, setGlobalEditor, setImageRenameBusy, setImageRenameBusyCount, setSaveStatus, setSuggestedExternalLinks, setSuggestedInternalLinks, siteDomainRef, slugRenameManagedByBatchRef, updateBlockContent }) {
    useEffect(() => {
        const onLoading = (e) => {
            setImageRenameBusy(true);
            setImageRenameBusyCount(Number(e.detail?.count ?? 0));
        };

        const onFinished = (e) => {
            setImageRenameBusy(false);
            setImageRenameBusyCount(0);

            if (slugRenameManagedByBatchRef.current) {
                return;
            }

            applySlugRenameFinished(e?.detail ?? {});
        };

        window.addEventListener('seo-rename-attachment-slugs-loading', onLoading);
        window.addEventListener('seo-attachment-slugs-rename-finished', onFinished);

        return () => {
            window.removeEventListener('seo-rename-attachment-slugs-loading', onLoading);
            window.removeEventListener('seo-attachment-slugs-rename-finished', onFinished);
        };
    }, [applySlugRenameFinished]);

    const collapseSectionsExcept = useCallback(
        (targetSectionId) => {
            if (!targetSectionId || editorSections.length === 0) {
                return;
            }

            const next = {};
            for (const section of editorSections) {
                next[section.id] = section.id !== targetSectionId;
            }

            setCollapsedSectionIds(next);
        },
        [editorSections],
    );

    const focusImageBlock = useCallback(
        (blockId) => {
            if (!blockId) {
                return;
            }

            const targetSectionId = sectionByBlockId.get(blockId);
            // Expand target only — do NOT collapse other sections (media/image UX).
            if (targetSectionId) {
                setCollapsedSectionIds((prev) =>
                    prev[targetSectionId]
                        ? { ...prev, [targetSectionId]: false }
                        : prev,
                );
            }

            clearTempMerge();
            blockFlushRef.current = null;

            const currentActive = activeBlockIdRef.current;
            const needsSwitch = currentActive !== blockId;

            if (needsSwitch && currentActive) {
                commitActiveBlock();
                blockFlushRef.current = null;
            }

            if (needsSwitch) {
                setActiveBlockId(blockId);
            }

            captureEditorInsertionContext({
                sectionId: targetSectionId,
                blockId,
            });

            const jump = () => {
                const slot = document.querySelector(`[data-seo-block-id="${blockId}"]`);
                if (!slot) {
                    return;
                }

                scrollElementIntoViewIfNeeded(slot, { behavior: 'smooth', block: 'nearest' });
                slot.classList.add(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS);
                window.setTimeout(
                    () =>
                        slot.classList.remove(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS),
                    2400,
                );
            };

            window.setTimeout(jump, needsSwitch || targetSectionId ? 90 : 0);
        },
        [clearTempMerge, commitActiveBlock, sectionByBlockId],
    );

    const quickGenerateImageForSection = useCallback(
        (section) => {
            if (section?.isIntro) {
                notifyIntroNoImages();

                return;
            }

            const sectionBlocks = section.blockIds
                .map((blockId) => blockById.get(blockId))
                .filter(Boolean);
            const sectionText = getPlainTextFromBlocks(sectionBlocks).trim();
            if (!sectionText) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('generate_image'),
                            body: 'Section has no plain text to build prompt.',
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const keyword = (focusKeyword || articleTitle || '').trim();
            const promptInput = keyword ? `${keyword}\n\n${sectionText}` : sectionText;
            const targetBlockId = String(section.blockIds?.[0] ?? activeBlockId ?? '').trim();

            window.dispatchEvent(
                new CustomEvent('generate-article-image', {
                    detail: {
                        selectionText: sectionText,
                        selectionHtml: '',
                        userBrief: promptInput,
                        activeBlockId: targetBlockId,
                    },
                }),
            );
        },
        [activeBlockId, articleTitle, blockById, focusKeyword, notifyIntroNoImages],
    );

    const scrollToFeaturedSnippetTable = useCallback(() => {
        const currentBlocks = blocksRef.current;
        let targetBlockId = null;

        for (const block of currentBlocks) {
            if (block.type === 'image' || !block.content) {
                continue;
            }

            if (/<table\b/i.test(block.content)) {
                targetBlockId = block.id;
                break;
            }
        }

        if (!targetBlockId) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: 'Table not found',
                        body: 'No table found in current content.',
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        const targetSectionId = sectionByBlockId.get(targetBlockId);
        if (targetSectionId) {
            collapseSectionsExcept(targetSectionId);
        }

        clearTempMerge();

        const currentActive = activeBlockIdRef.current;
        const needsSwitch = currentActive !== targetBlockId;

        if (needsSwitch && currentActive) {
            commitActiveBlock();
        }

        if (needsSwitch) {
            setActiveBlockId(targetBlockId);
        }

        const jump = () => {
            const slot = document.querySelector(`[data-seo-block-id="${targetBlockId}"]`);
            const table = slot?.querySelector?.('table');
            const target = table || slot;
            if (!target) {
                return;
            }

            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.add(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS);
            window.setTimeout(
                () =>
                    target.classList.remove(SEO_EDITOR_LINK_MARK_CLASS, SEO_EDITOR_LINK_SCROLL_LEGACY_CLASS),
                2400,
            );
        };

        window.setTimeout(jump, needsSwitch || targetSectionId ? 90 : 0);
    }, [clearTempMerge, collapseSectionsExcept, commitActiveBlock, sectionByBlockId]);

    const scrollToExtractedLink = useCallback(
        (detail) => {
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const preferHrefMatch = detail?.preferHrefMatch === true;
            if (!text && !href) {
                return;
            }

            const listIndex = Number(detail?.index) || 0;
            const linkType = String(detail?.type ?? 'internal');
            const searchPlainText = detail?.searchPlainText === true;
            const offset = typeof detail?.offset === 'number' ? detail.offset : null;
            const scrollToken = ++linkScrollTokenRef.current;

            clearTempMerge();

            if (linkType === 'faq') {
                const faqIndex =
                    typeof detail?.faqIndex === 'number' ? detail.faqIndex : listIndex;

                window.setTimeout(() => {
                    if (scrollToken !== linkScrollTokenRef.current) {
                        return;
                    }
                    if (!scrollToFaqByIndex(faqIndex)) {
                        scrollToFaqKeyword(text, 0);
                    }
                }, 0);
                return;
            }

            const currentBlocks = blocksRef.current;
            let targetBlockId = offset != null ? findBlockIdForExportOffset(currentBlocks, offset) : null;
            let localAnchorIndex = listIndex;

            if (targetBlockId) {
                const offsetBlock = currentBlocks.find((block) => block.id === targetBlockId);
                const countInOffsetBlock = offsetBlock?.content
                    ? (searchPlainText
                        ? countPlainTextInHtml(offsetBlock.content, text)
                        : countMatchingAnchorsInHtml(offsetBlock.content, text, href))
                    : 0;
                if (countInOffsetBlock < 1) {
                    // Offset stale after user edits: fallback to keyword+href scan.
                    targetBlockId = null;
                }
            }

            if (!targetBlockId) {
                let global = 0;
                for (const block of currentBlocks) {
                    if (block.type === 'image' || !block.content) {
                        continue;
                    }
                    const count = searchPlainText
                        ? countPlainTextInHtml(block.content, text)
                        : countMatchingAnchorsInHtml(
                            block.content,
                            preferHrefMatch ? '' : text,
                            href,
                        );
                    if (count === 0) {
                        continue;
                    }
                    if (listIndex < global + count) {
                        targetBlockId = block.id;
                        localAnchorIndex = listIndex - global;
                        break;
                    }
                    global += count;
                }
            } else {
                let before = 0;
                for (const block of currentBlocks) {
                    if (block.id === targetBlockId) {
                        break;
                    }
                    if (block.type !== 'image' && block.content) {
                        before += searchPlainText
                            ? countPlainTextInHtml(block.content, text)
                            : countMatchingAnchorsInHtml(
                                block.content,
                                preferHrefMatch ? '' : text,
                                href,
                            );
                    }
                }
                localAnchorIndex = Math.max(0, listIndex - before);
            }

            if (!targetBlockId) {
                for (const block of currentBlocks) {
                    if (block.type === 'image' || !block.content) {
                        continue;
                    }
                    const count = searchPlainText
                        ? countPlainTextInHtml(block.content, text)
                        : countMatchingAnchorsInHtml(
                            block.content,
                            preferHrefMatch ? '' : text,
                            href,
                        );
                    if (count > 0) {
                        targetBlockId = block.id;
                        localAnchorIndex = 0;
                        break;
                    }
                }
            }

            if (!targetBlockId) {
                window.setTimeout(() => {
                    if (scrollToken !== linkScrollTokenRef.current) {
                        return;
                    }
                    scrollToFaqKeyword(text, listIndex);
                }, 0);
                return;
            }

            const targetSectionId = sectionByBlockId.get(targetBlockId);
            if (targetSectionId) {
                collapseSectionsExcept(targetSectionId);
            }

            const currentActive = activeBlockIdRef.current;
            const needsDeactivate = currentActive != null && currentActive !== targetBlockId;

            if (needsDeactivate) {
                commitActiveBlock();
                setActiveBlockId(null);
                setGlobalEditor(null);
                blockFlushRef.current = null;
            }

            const runScroll = () => {
                if (scrollToken !== linkScrollTokenRef.current) {
                    return;
                }
                if (searchPlainText) {
                    scrollToPlainTextInBlock(targetBlockId, text, localAnchorIndex, {
                        onMiss: () => scrollToFaqKeyword(text, listIndex),
                    });
                    return;
                }
                scrollToKeywordAnchor(targetBlockId, preferHrefMatch ? '' : text, localAnchorIndex, href, {
                    onMiss: () => scrollToFaqKeyword(text, listIndex),
                });
            };

            const scrollDelay = needsDeactivate || targetSectionId ? 90 : 0;

            if (scrollDelay > 0) {
                window.setTimeout(runScroll, scrollDelay);
            } else {
                runScroll();
            }
        },
        [clearTempMerge, collapseSectionsExcept, commitActiveBlock, sectionByBlockId],
    );

    const insertSuggestedLinkIntoContent = useCallback(
        (detail) => {
            if (!assertWritableEditorSession('link_insert_blocked')) {
                return;
            }
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const occurrenceIndex = Math.max(0, Number(detail?.occurrence_index) || 0);
            if (!text || !href) {
                return;
            }

            const insertMode = String(detail?.insert_mode ?? detail?.insertMode ?? 'wrap').toLowerCase();
            if (insertMode === 'caret') {
                syncInsertionContextFromLiveEditors({
                    blockEditors: blockEditorsRef.current,
                    activeBlockId: activeBlockIdRef.current,
                    sectionByBlockId,
                });
                const insertionCtx = getEditorInsertionContext();
                const preferredBlockId = String(
                    detail?.target?.blockId
                    ?? insertionCtx.activeBlockId
                    ?? activeBlockIdRef.current
                    ?? '',
                ).trim();
                if (preferredBlockId) {
                    const sectionId = sectionByBlockId.get(preferredBlockId);
                    if (sectionId) {
                        setCollapsedSectionIds((prev) =>
                            prev[sectionId] ? { ...prev, [sectionId]: false } : prev,
                        );
                    }
                }
                const bookmark = detail?.target?.selectionBookmark ?? insertionCtx.selection;
                const tryCaretInsert = () => {
                    const result = executeEditorCommand('insert_link', {
                        editorId: preferredBlockId || undefined,
                        label: text,
                        text,
                        href,
                        bookmark,
                    }, { notifyOnFailure: true });
                    if (result && result.ok === false && (
                        result.code === 'editor_read_only'
                        || result.code === 'editor_session_not_owned'
                        || result.code === 'content_replace_conflict'
                        || result.code === 'permission_denied'
                    )) {
                        return 'blocked';
                    }
                    return Boolean(result?.ok && result.transaction_applied);
                };
                const afterCaretInsert = () => {
                    if (preferredBlockId) {
                        scrollElementIntoViewIfNeeded(
                            document.querySelector(`[data-seo-block-id="${preferredBlockId}"]`),
                            { behavior: 'smooth', block: 'nearest' },
                        );
                    }
                    window.dispatchEvent(
                        new CustomEvent('seo-editor-suggested-link-inserted', {
                            detail: { text, href, blockId: preferredBlockId },
                        }),
                    );
                };
                const caretStatus = tryCaretInsert();
                if (caretStatus === 'blocked') {
                    return;
                }
                if (caretStatus) {
                    afterCaretInsert();
                    return;
                }
                // Section may still be mounting TipTap after expand — one frame retry.
                requestAnimationFrame(() => {
                    syncInsertionContextFromLiveEditors({
                        blockEditors: blockEditorsRef.current,
                        activeBlockId: preferredBlockId || activeBlockIdRef.current,
                        sectionByBlockId,
                    });
                    const retryStatus = tryCaretInsert();
                    if (retryStatus === true) {
                        afterCaretInsert();
                    }
                });
                return;
            }

            const notifyInserted = (blockId, nextHtml) => {
                const currentBlocks = blocksRef.current;
                const nextBlocks = currentBlocks.map((item) =>
                    item.id === blockId ? { ...item, content: nextHtml } : item,
                );
                blocksRef.current = nextBlocks;
                setBlocks(nextBlocks);

                const activeId = activeBlockIdRef.current;
                if (activeId === blockId) {
                    const editor = blockEditorsRef.current.get(blockId);
                    if (editor && !editor.isDestroyed) {
                        editor.commands.setContent(nextHtml, {
                            emitUpdate: false,
                            parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                        });
                    }
                }

                if (articleId) {
                    saveDraft(articleId, connectionHashRef.current, {
                        content: exportBlocksToHtml(nextBlocks),
                    });
                    setSaveStatus('saved');
                }

                setExtractedLinks((prev) => {
                    const current = prev && typeof prev === 'object'
                        ? prev
                        : { internal: [], external: [] };
                    const isInternal = isInternalHrefForSite(href, siteDomainRef.current);
                    const bucketKey = isInternal ? 'internal' : 'external';
                    const bucket = Array.isArray(current[bucketKey]) ? current[bucketKey] : [];
                    const alreadyAdded = bucket.some(
                        (item) =>
                            normalizeLinkLabel(item?.text) === normalizeLinkLabel(text) ||
                            normalizeHrefForCompare(item?.href) === normalizeHrefForCompare(href),
                    );

                    return alreadyAdded
                        ? current
                        : {
                              ...current,
                              [bucketKey]: [...bucket, { text, href, occurrence_count: 1 }],
                          };
                });
                setSuggestedInternalLinks((prev) =>
                    filterSuggestedInternalLinks(prev, [{ text, href }]),
                );
                setSuggestedExternalLinks((prev) =>
                    filterSuggestedInternalLinks(prev, [{ text, href }]),
                );
                window.dispatchEvent(
                    new CustomEvent('seo-editor-suggested-link-inserted', {
                        detail: { text, href },
                    }),
                );
            };

            commitActiveBlock();

            const domResult = wrapPlainTextWithLinkInBlocks(
                blocksRef.current,
                text,
                href,
                occurrenceIndex,
            );
            if (domResult) {
                notifyInserted(domResult.blockId, domResult.html);
                return;
            }

            let remainingIndex = occurrenceIndex;
            let targetBlockId = null;
            let localIndex = 0;

            for (const block of blocksRef.current) {
                if (block.type === 'image' || !block.content) {
                    continue;
                }

                const count = countPlainTextInHtml(block.content, text);
                if (count <= 0) {
                    continue;
                }
                if (remainingIndex < count) {
                    targetBlockId = block.id;
                    localIndex = remainingIndex;
                    break;
                }
                remainingIndex -= count;
            }

            if (!targetBlockId) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_keyword_not_found'),
                            body: t('editor_keyword_not_found_body', { text }),
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const currentActive = activeBlockIdRef.current;
            if (currentActive !== targetBlockId) {
                setActiveBlockId(targetBlockId);
            }

            selectPlainTextInBlock(targetBlockId, text, localIndex, (editor) => {
                const result = executeEditorCommand('insert_link', {
                    editor,
                    editorId: targetBlockId,
                    label: text,
                    text,
                    href,
                }, { notifyOnFailure: true });
                if (!(result?.ok && result.transaction_applied)) {
                    return;
                }
                persistEditorContentImmediately(editor, targetBlockId);
                notifyInserted(targetBlockId, blocksRef.current.find((item) => item.id === targetBlockId)?.content ?? '');
            });
        },
        [
            articleId,
            commitActiveBlock,
            persistEditorContentImmediately,
            requestAnalyze,
            sectionByBlockId,
            selectPlainTextInBlock,
        ],
    );

    const removeInternalLinkFromContent = useCallback(
        (detail) => {
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            if (!text && !href) {
                return;
            }

            commitActiveBlock();

            let removedCount = 0;
            const nextBlocks = blocksRef.current.map((block) => {
                if (block.type === 'image' || !block.content) {
                    return block;
                }

                const nextContent = removeMatchingAnchorsFromHtml(block.content, text, href);
                if (nextContent === block.content) {
                    return block;
                }

                removedCount += countMatchingAnchorsInHtml(block.content, text, href);
                return { ...block, content: nextContent };
            });

            if (removedCount <= 0) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('links_remove_not_found_title'),
                            body: t('links_remove_not_found_body', { label: text || href }),
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const activeId = activeBlockIdRef.current;
            if (activeId) {
                const activeEditor = blockEditorsRef.current.get(activeId);
                const activeBlock = nextBlocks.find((block) => block.id === activeId);
                if (activeEditor && !activeEditor.isDestroyed && activeBlock?.content) {
                    activeEditor.commands.setContent(activeBlock.content, {
                        emitUpdate: false,
                        parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                    });
                }
            }

            blocksRef.current = nextBlocks;
            setBlocks(nextBlocks);
            setExtractedLinks((prev) => {
                const current = prev && typeof prev === 'object'
                    ? prev
                    : { internal: [], external: [] };
                const textKey = normalizeLinkLabel(text);
                const hrefKey = normalizeHrefForCompare(href);

                return {
                    ...current,
                    internal: (Array.isArray(current.internal) ? current.internal : []).filter(
                        (item) => {
                            const itemText = normalizeLinkLabel(item?.text);
                            const itemHref = normalizeHrefForCompare(item?.href);
                            const textMatches = !textKey || itemText === textKey;
                            const hrefMatches = !hrefKey || itemHref === hrefKey;

                            return !(textMatches && hrefMatches);
                        },
                    ),
                };
            });
            scheduleAutosave();

            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('links_removed_title'),
                        body: t('links_removed_body', { label: text || href }),
                        status: 'success',
                    },
                }),
            );
        },
        [commitActiveBlock, scheduleAutosave],
    );

    const insertCtaLinkIntoContent = useCallback(
        (detail) => {
            if (!assertWritableEditorSession('editor_read_only')) {
                return;
            }
            const text = String(detail?.text ?? '').trim();
            const href = String(detail?.href ?? '').trim();
            const type = String(detail?.type ?? '').toLowerCase();
            const plainText = isCtaPlainTextType(type) || detail?.is_placeholder === true;
            const occurrenceIndex = Math.max(0, Number(detail?.occurrence_index) || 0);

            if (!text || (!href && !plainText)) {
                return;
            }

            const isCtaSentence = detail?.is_sentence === true
                || detail?.is_cta_sentence === true
                || detail?.is_cta_block === true
                || Boolean(String(detail?.sentence ?? '').trim());

            const notifyCtaSuccess = (bodyText = text) => {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: isCtaSentence
                                ? t('editor_cta_block_inserted')
                                : t('editor_contact_value_inserted'),
                            body: `«${bodyText}»`,
                            status: 'success',
                        },
                    }),
                );
            };

            const applyWrappedBlockHtml = (blockId, nextHtml) => {
                const currentBlocks = blocksRef.current;
                const nextBlocks = currentBlocks.map((item) =>
                    item.id === blockId ? { ...item, content: nextHtml } : item,
                );
                blocksRef.current = nextBlocks;
                setBlocks(nextBlocks);

                const editor = blockEditorsRef.current.get(blockId);
                if (editor && !editor.isDestroyed) {
                    editor.commands.setContent(nextHtml, {
                        emitUpdate: false,
                        parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                    });
                }

                if (articleId) {
                    saveDraft(articleId, connectionHashRef.current, {
                        content: exportBlocksToHtml(nextBlocks),
                    });
                    setSaveStatus('saved');
                }

                clearFrozenEditorInsertionContext();
                requestAnalyze();
                notifyCtaSuccess();
            };

            // Contact-value insert mirrors internal-link wrap: find phrase in body, wrap href.
            // Highlight (scroll) only flashes <mark> — no TipTap selection — so caret insert misses target.
            if (!isCtaSentence && !plainText && href) {
                commitActiveBlock();

                const domResult = wrapPlainTextWithLinkInBlocks(
                    blocksRef.current,
                    text,
                    href,
                    occurrenceIndex,
                );
                if (domResult) {
                    applyWrappedBlockHtml(domResult.blockId, domResult.html);
                    return;
                }

                let remainingIndex = occurrenceIndex;
                let targetBlockId = null;
                let localIndex = 0;

                for (const block of blocksRef.current) {
                    if (block.type === 'image' || !block.content) {
                        continue;
                    }
                    const count = countPlainTextInHtml(block.content, text);
                    if (count <= 0) {
                        continue;
                    }
                    if (remainingIndex < count) {
                        targetBlockId = block.id;
                        localIndex = remainingIndex;
                        break;
                    }
                    remainingIndex -= count;
                }

                if (targetBlockId) {
                    if (activeBlockIdRef.current !== targetBlockId) {
                        setActiveBlockId(targetBlockId);
                    }
                    selectPlainTextInBlock(targetBlockId, text, localIndex, (editor) => {
                        const result = executeEditorCommand('insert_link', {
                            editor,
                            editorId: targetBlockId,
                            label: text,
                            text,
                            href,
                        }, { notifyOnFailure: true });
                        if (!(result?.ok && result.transaction_applied)) {
                            return;
                        }
                        persistEditorContentImmediately(editor, targetBlockId);
                        clearFrozenEditorInsertionContext();
                        notifyCtaSuccess();
                    });
                    return;
                }
                // Phrase absent in body — fall through to caret insert (add new contact).
            }

            // Do NOT re-sync from live editors after sidebar stole focus — that overwrites frozen caret.
            const frozen = getFrozenEditorInsertionContext();
            if (!frozen?.selection) {
                syncInsertionContextFromLiveEditors({
                    blockEditors: blockEditorsRef.current,
                    activeBlockId: activeBlockIdRef.current,
                    sectionByBlockId,
                });
            }

            const insertionCtx = getInsertionContextForCommand();
            const preferredBlockId = String(
                detail?.target?.blockId
                ?? insertionCtx.activeBlockId
                ?? activeBlockIdRef.current
                ?? '',
            ).trim();

            if (preferredBlockId) {
                const sectionId = sectionByBlockId.get(preferredBlockId);
                if (sectionId) {
                    setCollapsedSectionIds((prev) =>
                        prev[sectionId] ? { ...prev, [sectionId]: false } : prev,
                    );
                }
                if (activeBlockIdRef.current !== preferredBlockId) {
                    activeBlockIdRef.current = preferredBlockId;
                    setActiveBlockId(preferredBlockId);
                }
            }

            const bookmark = detail?.target?.selectionBookmark
                ?? insertionCtx.selection
                ?? frozen?.selection
                ?? null;
            const tryCtaInsert = () => {
                const commandName = isCtaSentence ? 'insert_contact_cta' : 'insert_contact_value';
                const result = executeEditorCommand(commandName, {
                    editorId: preferredBlockId || undefined,
                    type,
                    contactType: type,
                    label: text,
                    text,
                    href,
                    sentence: String(detail?.sentence ?? detail?.text ?? '').trim(),
                    value_label: String(detail?.value_label ?? '').trim() || undefined,
                    bookmark,
                }, { notifyOnFailure: true });
                if (result && result.ok === false && (
                    result.code === 'editor_read_only'
                    || result.code === 'editor_session_not_owned'
                    || result.code === 'content_replace_conflict'
                    || result.code === 'permission_denied'
                )) {
                    return 'blocked';
                }
                return Boolean(result?.ok && result.transaction_applied);
            };
            const afterCtaInsert = () => {
                clearFrozenEditorInsertionContext();
                if (preferredBlockId) {
                    const sectionId = sectionByBlockId.get(preferredBlockId);
                    if (sectionId) {
                        setCollapsedSectionIds((prev) =>
                            prev[sectionId] ? { ...prev, [sectionId]: false } : prev,
                        );
                    }
                    const slot = document.querySelector(`[data-seo-block-id="${preferredBlockId}"]`);
                    scrollElementIntoViewIfNeeded(slot, { behavior: 'smooth', block: 'nearest' });
                }
                // Dirty/analyze/autosave emitted once by command layer document-changed.
                notifyCtaSuccess();
            };

            const ctaInsertStatus = tryCtaInsert();
            if (ctaInsertStatus === 'blocked') {
                return;
            }
            if (ctaInsertStatus) {
                afterCtaInsert();
                return;
            }

            const selectedText = intraSelectionRef.current.text;
            const activeId = preferredBlockId || activeBlockIdRef.current;
            if (selectedText) {
                const activeBlock = blocksRef.current.find(
                    (block) => block.id === activeId && block.type !== 'image',
                );
                if (activeBlock) {
                    const replaceResult = plainText
                        ? replaceFirstPlainTextWithText(activeBlock.content ?? '', selectedText, text)
                        : replaceFirstPlainTextWithLink(
                              activeBlock.content ?? '',
                              selectedText,
                              text,
                              href,
                          );
                    if (replaceResult.replaced) {
                        clearFrozenEditorInsertionContext();
                        updateBlockContent(activeBlock.id, replaceResult.html);
                        requestAnalyze();
                        notifyCtaSuccess();
                        return;
                    }
                }
            }

            commitActiveBlock();

            const currentBlocks = blocksRef.current;
            // Fallback only to active block end — never silently insert into first section.
            const targetBlock = currentBlocks.find(
                (block) => block.id === activeId && block.type !== 'image',
            );

            if (!targetBlock) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_cta_insert_failed'),
                            body: t('editor_cta_insert_failed_body'),
                            status: 'warning',
                        },
                    }),
                );
                return;
            }

            const placeholderHtml =
                detail?.is_placeholder === true ? String(detail?.html ?? '').trim() : '';
            const safeText = text.replace(/</g, '&lt;').replace(/>/g, '&gt;');
            const valueLink = plainText
                ? safeText
                : `<a href="${href.replace(/"/g, '&quot;')}" class="${SEO_EDITOR_LINK_CLASS}">${safeText}</a>`;
            // Fallback also stays inline — never wrap in article-cta / new paragraph block.
            const insertion = placeholderHtml !== '' ? placeholderHtml : valueLink;
            const base = String(targetBlock.content ?? '').trim();
            const nextHtml = base !== '' ? `${base} ${insertion}` : `<p>${insertion}</p>`;

            clearFrozenEditorInsertionContext();
            updateBlockContent(targetBlock.id, nextHtml);
            requestAnalyze();
            notifyCtaSuccess();
        },
        [
            articleId,
            commitActiveBlock,
            persistEditorContentImmediately,
            requestAnalyze,
            sectionByBlockId,
            selectPlainTextInBlock,
            updateBlockContent,
        ],
    );

    return { collapseSectionsExcept, focusImageBlock, insertCtaLinkIntoContent, insertSuggestedLinkIntoContent, quickGenerateImageForSection, removeInternalLinkFromContent, scrollToExtractedLink, scrollToFeaturedSnippetTable };
}
