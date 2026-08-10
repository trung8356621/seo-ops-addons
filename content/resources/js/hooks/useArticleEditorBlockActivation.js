import {
    articleHasFaqShortcode,
    blockHasOutlineHeading,
    createEmptyImageBlock,
    createEmptyTextBlock,
    createFaqShortcodeBlock,
    dispatchActiveBlockContext,
    extractHeadingScopedPlainText,
    findBlockIdForOutlineHeading,
    getActiveBlockContextText,
    getHtmlFromBlocks,
    outlineHeadingKey,
} from '../utils/contentDocumentHelpers';
import { captureEditorInsertionContext } from '../utils/editorInsertionContext';
import { extractOutlineHeadingFromBlock } from '../utils/articleEditorClientOutline';
import { normalizeBlocks } from '@media-addon/utils/blockImageUtils.js';
import { t } from '../utils/i18n';
import { useCallback, useEffect } from 'react';

/**
 * useArticleEditorBlockActivation - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorBlockActivation({ activeBlockId, activeBlockIdRef, armBlockOutsideClickGuard, articleId, blocks, blocksRef, clearTempMerge, collapsedSectionIds, commitActiveBlock, focusedOutlineHeadingRef, intraSelectionRef, isIntroBlockId, notifyIntroNoImages, outlineHasSavedHeadings, outlineHeadingIdsByBlockIdRef, outlineHeadingKeys, sectionByBlockId, setActiveBlockId, setBlocks, setCollapsedSectionIds, setGlobalEditor, setInsertMenu, setOutlineHeadingCommand, tempMerge, tempMergeRef }) {
    useEffect(() => {
        const publishSelectionContext = () => {
            if (!activeBlockId) {
                dispatchActiveBlockContext(articleId, '', '', false, null);
                return;
            }

            const intra = intraSelectionRef.current;
            const blockText = getActiveBlockContextText(
                blocksRef.current,
                activeBlockId,
                tempMergeRef.current,
            );
            const blockHtml = getHtmlFromBlocks(
                blocksRef.current,
                activeBlockId,
                tempMergeRef.current,
            );

            const text = intra.text.length >= 12 ? intra.text : blockText;
            const html = intra.html.length >= 12 ? intra.html : blockHtml;
            let contextText = text;
            let contextHtml = html;

            if (intra.text.length < 12) {
                const focused = focusedOutlineHeadingRef.current;
                if (focused?.headingText && blockHtml) {
                    const scoped = extractHeadingScopedPlainText(
                        blockHtml,
                        Number(focused.level ?? 0),
                        String(focused.headingText ?? ''),
                    );
                    if (scoped.length >= 12) {
                        contextText = scoped;
                        contextHtml = scoped;
                    }
                }
            }

            dispatchActiveBlockContext(articleId, contextText, contextHtml, true, activeBlockId);
        };

        const onIntra = (event) => {
            intraSelectionRef.current = {
                text: (event.detail?.text ?? '').trim(),
                html: (event.detail?.html ?? '').trim(),
            };
            publishSelectionContext();
        };

        window.addEventListener('seo-editor-intra-selection', onIntra);
        publishSelectionContext();

        return () => window.removeEventListener('seo-editor-intra-selection', onIntra);
    }, [activeBlockId, blocks, tempMerge, articleId]);

    const clearOutlineFocus = useCallback(() => {
        focusedOutlineHeadingRef.current = null;
        setOutlineHeadingCommand({
            token: Date.now(),
            action: 'clear',
        });
    }, []);

    const syncOutlineFocusFromBlock = useCallback((block, action = 'focus') => {
        const meta = extractOutlineHeadingFromBlock(block);
        const headingId = outlineHeadingIdsByBlockIdRef.current.get(block?.id);
        if (!meta && headingId == null) {
            return;
        }

        if (headingId == null && outlineHasSavedHeadings && meta) {
            const key = outlineHeadingKey(meta.level, meta.headingText);
            if (!outlineHeadingKeys.has(key)) {
                return;
            }
        }

        setOutlineHeadingCommand({
            token: Date.now(),
            level: meta?.level,
            headingText: meta?.headingText,
            headingId: headingId ?? null,
            action,
        });
    }, [outlineHasSavedHeadings, outlineHeadingKeys]);

    const isBlockOutlineSynced = useCallback(
        (block) => {
            if (outlineHeadingIdsByBlockIdRef.current.has(block?.id)) {
                return true;
            }

            const meta = extractOutlineHeadingFromBlock(block);
            if (!meta) {
                return false;
            }

            const key = outlineHeadingKey(meta.level, meta.headingText);
            if (!outlineHeadingKeys.has(key)) {
                return false;
            }

            const matchedBlockId = findBlockIdForOutlineHeading(
                blocksRef.current,
                meta.level,
                meta.headingText,
            );

            return matchedBlockId === block.id;
        },
        [outlineHeadingKeys],
    );

    const activateBlock = useCallback(
        (id) => {
            setInsertMenu(null);
            armBlockOutsideClickGuard();
            const sectionId = sectionByBlockId.get(id);
            if (sectionId && collapsedSectionIds[sectionId]) {
                setCollapsedSectionIds((prev) => ({ ...prev, [sectionId]: false }));
            }
            captureEditorInsertionContext({
                sectionId: sectionId ?? null,
                blockId: id,
            });
            if (tempMergeRef.current) {
                clearTempMerge();
                setGlobalEditor(null);
                activeBlockIdRef.current = id;
                setActiveBlockId(id);
                if (outlineHasSavedHeadings) {
                    const block = blocksRef.current.find((item) => item.id === id);
                    if (block && (blockHasOutlineHeading(block) || isBlockOutlineSynced(block))) {
                        syncOutlineFocusFromBlock(block);
                    } else {
                        clearOutlineFocus();
                    }
                }
                return;
            }
            if (id === activeBlockId) {
                return;
            }
            commitActiveBlock();
            activeBlockIdRef.current = id;
            setActiveBlockId(id);
            setGlobalEditor(null);
            if (outlineHasSavedHeadings) {
                const block = blocksRef.current.find((item) => item.id === id);
                if (block && (blockHasOutlineHeading(block) || isBlockOutlineSynced(block))) {
                    syncOutlineFocusFromBlock(block);
                } else {
                    clearOutlineFocus();
                }
            }
        },
        [
            activeBlockId,
            armBlockOutsideClickGuard,
            clearOutlineFocus,
            collapsedSectionIds,
            commitActiveBlock,
            clearTempMerge,
            isBlockOutlineSynced,
            outlineHasSavedHeadings,
            sectionByBlockId,
            syncOutlineFocusFromBlock,
        ],
    );

    const insertBlockRelative = useCallback(
        (refBlockId, position, type) => {
            if (tempMergeRef.current) return;

            if (type === 'image' && isIntroBlockId(refBlockId)) {
                setInsertMenu(null);
                notifyIntroNoImages();

                return;
            }

            if (type === 'faq' && articleHasFaqShortcode(blocksRef.current)) {
                setInsertMenu(null);
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_faq_shortcode_exists'),
                            body: t('editor_faq_shortcode_exists_body'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            commitActiveBlock();

            const newBlock =
                type === 'image'
                    ? createEmptyImageBlock()
                    : type === 'faq'
                      ? createFaqShortcodeBlock()
                      : createEmptyTextBlock();
            const newId = newBlock.id;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) return prev;
                const insertAt = position === 'before' ? index : index + 1;
                const next = [...prev];
                next.splice(insertAt, 0, newBlock);
                return next;
            });

            setInsertMenu(null);
            activeBlockIdRef.current = newId;
            setActiveBlockId(newId);
            setGlobalEditor(null);
            if (type === 'image') {
                armBlockOutsideClickGuard(360);
            } else {
                armBlockOutsideClickGuard();
            }
            if (outlineHasSavedHeadings) {
                clearOutlineFocus();
            }
        },
        [
            armBlockOutsideClickGuard,
            clearOutlineFocus,
            commitActiveBlock,
            isIntroBlockId,
            notifyIntroNoImages,
            outlineHasSavedHeadings,
        ],
    );

    const insertHtmlBlockRelative = useCallback(
        (refBlockId, position, html) => {
            if (tempMergeRef.current) {
                return;
            }

            const content = String(html ?? '').trim();
            if (!content) {
                return;
            }

            commitActiveBlock();

            const newBlock = {
                ...createEmptyTextBlock(),
                content,
            };
            const newId = newBlock.id;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === refBlockId);
                if (index < 0) {
                    return prev;
                }

                const insertAt = position === 'before' ? index : index + 1;
                const next = [...prev];
                next.splice(insertAt, 0, newBlock);

                return normalizeBlocks(next);
            });

            setInsertMenu(null);
            setActiveBlockId(newId);
            setGlobalEditor(null);
        },
        [commitActiveBlock],
    );

    return { activateBlock, clearOutlineFocus, insertBlockRelative, syncOutlineFocusFromBlock };
}
