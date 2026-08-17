import { TIPTAP_HTML_PARSE_OPTIONS } from '../utils/inlineWhitespaceGuard';
import {
    buildClientOutlineTree,
    extractOutlineHeadingFromBlock,
    normalizeOutlineHeadingText,
    outlineHeadingFingerprint,
} from '../utils/articleEditorClientOutline';
import {
    changeHeadingLevelInHtml,
    deleteHeadingKeepContentInHtml,
    deleteHeadingWithContentInHtml,
    insertAfterHeadingSectionInHtml,
    renameHeadingInHtml,
    setOutlineVisibleInHtml,
} from '../utils/articleEditorOutlineMutations';
import { executeEditorCommand } from '../utils/editorCommands';
import {
    buildEditorSections,
    createEmptySectionBlock,
    createEmptyTextBlock,
    findBlockIdForOutlineHeading,
    flattenOutlineHeadingKeys,
    flattenOutlineNodes,
    isPersistedOutlineHeadingId,
    outlineApiRequest,
    outlineHeadingKey,
    resolveBlockIdFromOutlineHeadingId,
    truncateOutlineHeadingText,
} from '../utils/contentDocumentHelpers';
import { callEditArticleLivewire } from '../utils/articleEditorLivewire';
import { normalizeBlocks, parseFeaturedSnippetNewSectionBlocks } from '@media-addon/utils/blockImageUtils.js';
import { setArticleAutosaveLock } from '../utils/articleAutosaveLock';
import { t } from '../utils/i18n';
import { useCallback, useEffect, useRef } from 'react';

/**
 * useArticleEditorOutline - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorOutline({ activeBlockId, activateBlock = null, articleId, articleTitle, blockEditorsRef, blockFlushRef, blocksRef, canGenerateFeaturedSnippet, collapseSectionsExcept, commitActiveBlock, editorSections, featuredSnippetGenerating, featuredSnippetPreviewHtml, featuredSnippetTargetRef, focusImageBlock, focusKeyword, focusedOutlineHeadingRef, outlineAppendDoneRef, outlineAppendInflightRef, outlineFingerprintRef, outlineHasSavedHeadings, outlineHeadingIdsByBlockIdRef, outlineHeadingIdsByKeyRef, outlineRailRef, markSeoStale, sectionByBlockId, sectionHeadingBlockIds, setActiveBlockId, setBlocks, setClientOutline, setCollapsedSectionIds, setFeaturedSnippetGenerating, setFeaturedSnippetPreviewHtml, setFeaturedSnippetPromptOpen, setGlobalEditor, setImagesTabJumpTarget, setInsertMenu, setOutlineHasSavedHeadings, setOutlineHeadingKeys, setOutlineJumpTarget = null, setOutlineTreeSync, setSectionTitleEditRequest, syncOutlineFocusFromBlock, tempMergeRef }) {
    const resolveBlockIdForOutlineHeadingId = useCallback(
        (headingId) =>
            resolveBlockIdFromOutlineHeadingId(headingId, outlineHeadingIdsByBlockIdRef.current),
        [],
    );

    const mutateOutlineHeading = useCallback((node, commandName, payload, htmlMutator) => {
        const blockId = String(node?.block_id ?? '').trim()
            || resolveBlockIdForOutlineHeadingId(node?.id);
        const headingIndex = Number(node?.heading_index ?? 0);
        if (!blockId) {
            return false;
        }

        commitActiveBlock?.();
        const editor = blockEditorsRef.current.get(blockId);
        if (editor && !editor.isDestroyed) {
            const result = executeEditorCommand(commandName, {
                editor,
                headingIndex,
                ...payload,
            }, { notifyOnFailure: false });
            if (result?.ok && result.transaction_applied) {
                outlineFingerprintRef.current = '';
                return true;
            }
        }

        if (typeof htmlMutator !== 'function') {
            return false;
        }

        setBlocks((prev) => prev.map((block) => {
            if (block.id !== blockId || block.type !== 'text') {
                return block;
            }
            const nextHtml = htmlMutator(block.content || '', headingIndex);
            if (nextHtml === block.content) {
                return block;
            }

            return { ...block, content: nextHtml };
        }));
        outlineFingerprintRef.current = '';

        return true;
    }, [commitActiveBlock, resolveBlockIdForOutlineHeadingId]);

    const applyOutlineHeadingText = useCallback(({ level, oldText, newText, headingId = null, headingIndex = null, blockId = null }) => {
        const targetLevel = Number(level) || 0;
        const target = truncateOutlineHeadingText(oldText);
        const replacement = truncateOutlineHeadingText(newText);
        if (target === '' || replacement === '' || target === replacement) {
            return;
        }

        const mappedBlockId = String(blockId ?? '').trim() || resolveBlockIdForOutlineHeadingId(headingId);
        const index = Number.isFinite(Number(headingIndex)) ? Number(headingIndex) : null;
        const editor = mappedBlockId ? blockEditorsRef.current.get(mappedBlockId) : null;
        if (editor && !editor.isDestroyed && index != null) {
            executeEditorCommand('rename_heading', {
                editor,
                headingIndex: index,
                text: replacement,
            }, { notifyOnFailure: false });
            outlineFingerprintRef.current = '';
            return;
        }

        const selector = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2, h3, h4';
        const headingTag = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2';
        let replaced = false;

        setBlocks((prev) =>
            prev.map((block) => {
                if (replaced || block.type !== 'text' || !block.content) {
                    return block;
                }

                if (mappedBlockId && block.id !== mappedBlockId) {
                    return block;
                }

                if (index != null) {
                    const nextHtml = renameHeadingInHtml(block.content, index, replacement);
                    if (nextHtml !== block.content) {
                        replaced = true;
                        return { ...block, content: nextHtml };
                    }
                }

                const doc = new DOMParser().parseFromString(block.content, 'text/html');
                let headingNode = Array.from(doc.body.querySelectorAll(selector)).find(
                    (node) => truncateOutlineHeadingText(node.textContent) === target,
                );

                if (!headingNode && mappedBlockId === block.id) {
                    headingNode = doc.body.querySelector(selector);
                }

                if (!headingNode && mappedBlockId === block.id) {
                    replaced = true;

                    return {
                        ...block,
                        content: `<${headingTag}>${replacement}</${headingTag}><p></p>`,
                    };
                }

                if (!headingNode) {
                    return block;
                }

                headingNode.textContent = replacement;
                replaced = true;

                return { ...block, content: doc.body.innerHTML };
            }),
        );

        setOutlineHeadingKeys((prev) => {
            const next = new Set(prev);
            const oldKey = outlineHeadingKey(targetLevel, target);
            const newKey = outlineHeadingKey(targetLevel, replacement);
            if (next.has(oldKey)) {
                next.delete(oldKey);
            }
            next.add(newKey);

            const mappedHeadingId = outlineHeadingIdsByKeyRef.current.get(oldKey);
            if (mappedHeadingId != null) {
                outlineHeadingIdsByKeyRef.current.delete(oldKey);
                outlineHeadingIdsByKeyRef.current.set(newKey, mappedHeadingId);
            }

            return next;
        });
    }, [resolveBlockIdForOutlineHeadingId]);

    const resolveHeadingInnerHtml = useCallback((node) => {
        const level = Number(node?.level ?? 0);
        const headingText = normalizeOutlineHeadingText(node?.heading_text);
        if (headingText === '') {
            return '';
        }

        const blockId =
            resolveBlockIdForOutlineHeadingId(node?.id) ??
            findBlockIdForOutlineHeading(blocksRef.current, level, headingText);
        if (!blockId) {
            return '';
        }

        const block = blocksRef.current.find((item) => item.id === blockId);
        if (!block?.content) {
            return '';
        }

        const selector = level >= 2 && level <= 4 ? `h${level}` : 'h2, h3, h4';
        const doc = new DOMParser().parseFromString(block.content, 'text/html');
        const target = truncateOutlineHeadingText(headingText);
        const headingNode =
            Array.from(doc.body.querySelectorAll(selector)).find(
                (item) => truncateOutlineHeadingText(item.textContent) === target,
            ) ?? doc.body.querySelector(selector);

        return String(headingNode?.innerHTML ?? '').trim();
    }, [resolveBlockIdForOutlineHeadingId]);

    const applyOutlineHeadingHtml = useCallback(({ level, oldText, headingHtml, newText, headingId = null }) => {
        const normalizeText = (value) => String(value ?? '').replace(/\s+/g, ' ').trim();
        const targetLevel = Number(level) || 0;
        const target = normalizeText(oldText);
        const replacementHtml = String(headingHtml ?? '').trim();
        const replacementText = normalizeText(newText);
        if (target === '' || replacementHtml === '') {
            return;
        }

        const selector = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2, h3, h4';
        const mappedBlockId = resolveBlockIdForOutlineHeadingId(headingId);
        const headingTag = targetLevel >= 2 && targetLevel <= 4 ? `h${targetLevel}` : 'h2';
        let replacedBlockId = null;
        let nextHtml = '';

        setBlocks((prev) =>
            prev.map((block) => {
                if (replacedBlockId || block.type !== 'text' || !block.content) {
                    return block;
                }

                if (mappedBlockId && block.id !== mappedBlockId) {
                    return block;
                }

                const doc = new DOMParser().parseFromString(block.content, 'text/html');
                let headingNode = Array.from(doc.body.querySelectorAll(selector)).find(
                    (node) => normalizeText(node.textContent) === target,
                );

                if (!headingNode && mappedBlockId === block.id) {
                    headingNode = doc.body.querySelector(selector);
                }

                if (!headingNode && mappedBlockId === block.id) {
                    replacedBlockId = block.id;
                    nextHtml = `<${headingTag}>${replacementHtml}</${headingTag}><p></p>`;

                    return { ...block, content: nextHtml };
                }

                if (!headingNode) {
                    return block;
                }

                headingNode.innerHTML = replacementHtml;
                replacedBlockId = block.id;
                nextHtml = doc.body.innerHTML;

                return { ...block, content: nextHtml };
            }),
        );

        if (replacedBlockId && nextHtml !== '') {
            const activeEditor = blockEditorsRef.current.get(replacedBlockId);
            if (activeEditor && !activeEditor.isDestroyed) {
                activeEditor.commands.setContent(nextHtml, {
                    emitUpdate: false,
                    parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                });
            }
        }

        if (replacementText !== '') {
            setOutlineHeadingKeys((prev) => {
                const next = new Set(prev);
                const oldKey = outlineHeadingKey(targetLevel, target);
                const newKey = outlineHeadingKey(targetLevel, replacementText);
                if (next.has(oldKey)) {
                    next.delete(oldKey);
                }
                next.add(newKey);
                return next;
            });
        }
    }, [resolveBlockIdForOutlineHeadingId]);

    const handleOutlineLoaded = useCallback((outline) => {
        const nodes = Array.isArray(outline) ? outline : [];
        const hasOutline = nodes.length > 0;
        setOutlineHasSavedHeadings(hasOutline);
        setOutlineHeadingKeys(flattenOutlineHeadingKeys(nodes));

        const byKey = new Map();
        for (const node of flattenOutlineNodes(nodes)) {
            const level = Number(node?.level ?? 0);
            const text = normalizeOutlineHeadingText(node?.heading_text);
            if (level >= 2 && text !== '' && node?.id != null) {
                byKey.set(outlineHeadingKey(level, text), node.id);
            }
        }
        outlineHeadingIdsByKeyRef.current = byKey;

        for (const block of blocksRef.current) {
            const meta = extractOutlineHeadingFromBlock(block);
            if (!meta) {
                continue;
            }
            const headingId = byKey.get(outlineHeadingKey(meta.level, meta.headingText));
            if (headingId != null) {
                outlineHeadingIdsByBlockIdRef.current.set(block.id, headingId);
            }
        }
    }, []);

    const handleOutlineHeadingAppended = useCallback(({ blockId, headingId, heading }) => {
        if (blockId && headingId != null) {
            outlineHeadingIdsByBlockIdRef.current.set(blockId, headingId);
            outlineAppendDoneRef.current.add(blockId);
        }

        const level = Number(heading?.level ?? 2);
        const text = normalizeOutlineHeadingText(heading?.heading_text);
        if (text !== '') {
            const key = outlineHeadingKey(level, text);
            setOutlineHeadingKeys((prev) => {
                const next = new Set(prev);
                next.add(key);
                return next;
            });
            if (headingId != null) {
                outlineHeadingIdsByKeyRef.current.set(key, headingId);
            }
        }

        setOutlineHasSavedHeadings(true);
    }, []);

    const appendOutlineHeadingForBlock = useCallback(
        async (blockId, meta, options = {}) => {
            const id = String(blockId ?? '').trim();
            if (!id || !meta?.headingText || outlineAppendDoneRef.current.has(id)) {
                return;
            }

            if (outlineAppendInflightRef.current.has(id)) {
                return;
            }

            outlineAppendInflightRef.current.add(id);

            // Phase 4: outline is client-derived — no POST /outline on section add.
            const clientHeadingId = `client:${id}`;
            const heading = {
                id: clientHeadingId,
                heading_text: meta.headingText,
                level: meta.level ?? 2,
                block_id: id,
                children: [],
            };

            try {
                handleOutlineHeadingAppended({
                    blockId: id,
                    headingId: clientHeadingId,
                    heading,
                });
                outlineFingerprintRef.current = '';
                const tree = buildClientOutlineTree(blocksRef.current);
                outlineFingerprintRef.current = outlineHeadingFingerprint(blocksRef.current);
                setClientOutline(tree);
                if (options.focusEdit === true) {
                    setOutlineTreeSync({
                        token: Date.now(),
                        action: 'focusNew',
                        headingId: null,
                        blockId: id,
                        matchText: meta.headingText,
                        focusEdit: true,
                    });
                }
            } finally {
                outlineAppendInflightRef.current.delete(id);
            }
        },
        [handleOutlineHeadingAppended],
    );

    const syncOutlineForNewSectionBlock = useCallback(
        (headingBlock, afterHeadingId = null, options = {}) => {
            if (!articleId || !headingBlock) {
                return;
            }

            const meta = extractOutlineHeadingFromBlock(headingBlock);
            if (!meta) {
                return;
            }

            void appendOutlineHeadingForBlock(headingBlock.id, meta, {
                afterHeadingId,
                focusEdit: options.focusEdit === true,
            });
        },
        [appendOutlineHeadingForBlock, articleId],
    );

    const resolveOutlineHeadingIdForSection = useCallback((section) => {
        if (!section?.blockIds?.length || section.isIntro) {
            return null;
        }

        const headingBlockId = section.blockIds[0];
        const cached = outlineHeadingIdsByBlockIdRef.current.get(headingBlockId);
        if (cached) {
            return cached;
        }

        const block = blocksRef.current.find((item) => item.id === headingBlockId);
        const meta = block ? extractOutlineHeadingFromBlock(block) : null;
        if (!meta) {
            return null;
        }

        const headingId = outlineHeadingIdsByKeyRef.current.get(
            outlineHeadingKey(meta.level, meta.headingText),
        );
        if (headingId != null) {
            outlineHeadingIdsByBlockIdRef.current.set(headingBlockId, headingId);
        }

        return headingId ?? null;
    }, []);

    const updateOutlineHeadingTitle = useCallback(
        async ({ level, oldText, newText, headingId = null, headingHtml = null, blockId = null, headingIndex = null }) => {
            const trimmed = truncateOutlineHeadingText(newText);
            const old = truncateOutlineHeadingText(oldText);
            if (trimmed === '' || trimmed === old) {
                return { ok: true, skipped: true };
            }

            const resolvedBlockId =
                String(blockId ?? '').trim()
                || resolveBlockIdForOutlineHeadingId(headingId);
            const effectiveHeadingId =
                headingId ?? (resolvedBlockId ? `client:${resolvedBlockId}` : null);

            if (headingHtml != null && String(headingHtml).trim() !== '') {
                applyOutlineHeadingHtml({
                    level,
                    oldText: old,
                    headingHtml,
                    newText: trimmed,
                    headingId: effectiveHeadingId,
                });
            } else {
                applyOutlineHeadingText({
                    level,
                    oldText: old,
                    newText: trimmed,
                    headingId: effectiveHeadingId,
                    headingIndex,
                    blockId: resolvedBlockId,
                });
            }

            if (effectiveHeadingId != null) {
                setOutlineTreeSync({
                    token: Date.now(),
                    action: 'patchText',
                    headingId: effectiveHeadingId,
                    newText: trimmed,
                });
            }

            if (!isPersistedOutlineHeadingId(headingId) || !articleId) {
                return { ok: true, localOnly: true };
            }

            try {
                const data = await outlineApiRequest(articleId, `/${headingId}`, {
                    method: 'PUT',
                    body: JSON.stringify({ heading_text: trimmed }),
                });

                return { ok: true, data };
            } catch (error) {
                return { ok: false, error };
            }
        },
        [applyOutlineHeadingHtml, applyOutlineHeadingText, articleId, resolveBlockIdForOutlineHeadingId],
    );

    const saveSectionTitleFromHeader = useCallback(
        async (section, newText) => {
            if (section?.isIntro) {
                return;
            }

            const trimmed = truncateOutlineHeadingText(newText);
            const oldText = truncateOutlineHeadingText(section.title);
            if (trimmed === '' || trimmed === oldText) {
                return;
            }

            const headingBlockId = section.blockIds[0];
            const block = blocksRef.current.find((item) => item.id === headingBlockId);
            const meta = block ? extractOutlineHeadingFromBlock(block) : null;
            const level = meta?.level ?? 2;
            const headingId = resolveOutlineHeadingIdForSection(section);

            if (headingId == null) {
                await updateOutlineHeadingTitle({
                    level,
                    oldText,
                    newText: trimmed,
                    headingId: null,
                    blockId: headingBlockId,
                });

                if (!articleId) {
                    return;
                }

                const sections = buildEditorSections(blocksRef.current);
                const sectionIndex = sections.findIndex((item) => item.id === section.id);
                let afterHeadingId = null;
                if (sectionIndex > 0) {
                    for (let i = sectionIndex - 1; i >= 0; i--) {
                        const candidate = resolveOutlineHeadingIdForSection(sections[i]);
                        if (candidate != null) {
                            afterHeadingId = candidate;
                            break;
                        }
                    }
                }

                try {
                    await appendOutlineHeadingForBlock(
                        headingBlockId,
                        { level, headingText: trimmed },
                        { afterHeadingId, focusEdit: false },
                    );
                } catch (error) {
                    window.dispatchEvent(
                        new CustomEvent('seo-article-editor-notify', {
                            detail: {
                                title: 'Outline',
                                body: error?.message || 'Không thêm được tiêu đề section vào outline.',
                                status: 'danger',
                            },
                        }),
                    );
                }

                return;
            }

            const result = await updateOutlineHeadingTitle({
                level,
                oldText,
                newText: trimmed,
                headingId,
                blockId: headingBlockId,
            });

            if (result?.ok === false) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Outline',
                            body: result.error?.message || 'Không lưu được tiêu đề section.',
                            status: 'danger',
                        },
                    }),
                );
            }
        },
        [
            appendOutlineHeadingForBlock,
            articleId,
            resolveOutlineHeadingIdForSection,
            updateOutlineHeadingTitle,
        ],
    );

    const scrollPageToTop = useCallback(() => {
        window.scrollTo({ top: 0, left: 0, behavior: 'auto' });
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;

        document.querySelector('.seo-article-edit-page .fi-main-ctn')?.scrollTo?.({ top: 0, left: 0, behavior: 'auto' });
        document.querySelector('.seo-article-edit-page .fi-main')?.scrollTo?.({ top: 0, left: 0, behavior: 'auto' });
    }, []);

    const openImageAssistantPanel = useCallback(() => {
        window.dispatchEvent(
            new CustomEvent('seo-assistant-switch-panel', {
                detail: { panel: 'images' },
            }),
        );
    }, []);

    const openOutlineRail = useCallback(() => {
        const rail = outlineRailRef.current;
        if (!rail) {
            return;
        }

        rail.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
        rail.classList.add('is-pulse');
        window.setTimeout(() => rail.classList.remove('is-pulse'), 1200);
    }, []);

    const focusOutlineFromSectionHeader = useCallback(
        (section) => {
            if (section?.isIntro || !section?.blockIds?.length) {
                return;
            }

            const headingBlock = blocksRef.current.find((item) => item.id === section.blockIds[0]);
            if (!headingBlock) {
                return;
            }

            syncOutlineFocusFromBlock(headingBlock, 'focus');
            outlineRailRef.current?.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest',
                inline: 'start',
            });
        },
        [syncOutlineFocusFromBlock],
    );

    const handleOutlineHeadingFromEditor = useCallback(
        (action, block) => {
            syncOutlineFocusFromBlock(block, action);
            openOutlineRail();
        },
        [openOutlineRail, syncOutlineFocusFromBlock],
    );

    const jumpToOutlineHeading = useCallback(
        (node) => {
            focusedOutlineHeadingRef.current = {
                level: Number(node?.level ?? 0),
                headingText: String(node?.heading_text ?? ''),
                headingId: node?.id ?? null,
            };

            const fromBlockId = String(node?.block_id ?? '').trim();
            const clientId = String(node?.id ?? '');
            const headingIndex = Number(node?.heading_index ?? 0);
            const blockId =
                fromBlockId
                || (clientId.startsWith('client:') ? clientId.slice('client:'.length).split(':')[0] : '')
                || findBlockIdForOutlineHeading(
                    blocksRef.current,
                    Number(node?.level ?? 0),
                    String(node?.heading_text ?? ''),
                );
            if (!blockId) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: 'Outline',
                            body: 'Không tìm thấy heading tương ứng trong editor.',
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            if (sectionHeadingBlockIds.has(blockId) || sectionByBlockId.get(blockId)) {
                const sectionId = sectionByBlockId.get(blockId);
                if (sectionId) {
                    collapseSectionsExcept(sectionId);
                    window.requestAnimationFrame(() => {
                        const sectionEl = document.querySelector(`[data-seo-section-id="${sectionId}"]`);
                        sectionEl?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        sectionEl?.classList.add('is-outline-jump-highlight');
                        window.setTimeout(() => sectionEl?.classList.remove('is-outline-jump-highlight'), 2400);
                    });
                }
            }

            setOutlineJumpTarget?.({
                blockId,
                headingIndex: Number.isFinite(headingIndex) ? headingIndex : 0,
                token: Date.now(),
            });
            if (typeof activateBlock === 'function') {
                activateBlock(blockId);
            } else {
                focusImageBlock(blockId);
            }
        },
        [activateBlock, collapseSectionsExcept, focusImageBlock, sectionByBlockId, sectionHeadingBlockIds, setOutlineJumpTarget],
    );

    useEffect(() => {
        const onOpenImagesTab = (event) => {
            const detail = event?.detail ?? {};
            const src = String(detail?.src ?? '').trim();
            const seoMediaId = Number(detail?.seoMediaId ?? detail?.seo_media_id ?? 0);

            openImageAssistantPanel();
            setImagesTabJumpTarget({
                token: Date.now(),
                seoMediaId: seoMediaId > 0 ? seoMediaId : null,
                src,
            });
        };

        window.addEventListener('seo-open-images-tab', onOpenImagesTab);

        return () => {
            window.removeEventListener('seo-open-images-tab', onOpenImagesTab);
        };
    }, [openImageAssistantPanel]);

    useEffect(() => {
        if (!activeBlockId) {
            return;
        }

        const sectionId = sectionByBlockId.get(activeBlockId);
        if (!sectionId) {
            return;
        }

        setCollapsedSectionIds((prev) =>
            prev[sectionId]
                ? {
                      ...prev,
                      [sectionId]: false,
                  }
                : prev,
        );
    }, [activeBlockId, sectionByBlockId]);

    const toggleSectionCollapse = useCallback((sectionId) => {
        setCollapsedSectionIds((prev) => ({
            ...prev,
            [sectionId]: !prev[sectionId],
        }));
    }, []);

    const collapseAllSections = useCallback(() => {
        if (editorSections.length === 0) {
            return;
        }

        commitActiveBlock();

        const next = {};
        editorSections.forEach((section) => {
            next[section.id] = true;
        });
        setCollapsedSectionIds(next);
    }, [commitActiveBlock, editorSections]);

    const collapsedSectionsInitializedRef = useRef(false);

    useEffect(() => {
        if (editorSections.length === 0) {
            return;
        }

        if (collapsedSectionsInitializedRef.current) {
            return;
        }

        collapsedSectionsInitializedRef.current = true;
        setCollapsedSectionIds((prev) => {
            if (Object.keys(prev).length > 0) {
                return prev;
            }

            const next = { ...prev };
            editorSections.forEach((section, index) => {
                if (index > 0) {
                    next[section.id] = true;
                }
            });

            return next;
        });
    }, [editorSections]);

    const focusNewSectionHeader = useCallback((sectionUiId) => {
        setSectionTitleEditRequest({ sectionId: sectionUiId, token: Date.now() });
        window.requestAnimationFrame(() => {
            document.querySelector(`[data-seo-section-id="${sectionUiId}"]`)?.scrollIntoView({
                behavior: 'smooth',
                block: 'center',
            });
        });
    }, []);

    const addSection = useCallback(() => {
        if (tempMergeRef.current) {
            return;
        }

        commitActiveBlock();

        const newSectionBlock = createEmptySectionBlock();
        const sectionId = `section-${newSectionBlock.id}`;
        const sections = buildEditorSections(blocksRef.current);
        const lastSection = [...sections].reverse().find((item) => !item.isIntro) ?? null;
        const afterHeadingId = lastSection ? resolveOutlineHeadingIdForSection(lastSection) : null;

        setBlocks((prev) => normalizeBlocks([...prev, newSectionBlock]));
        setInsertMenu(null);
        setActiveBlockId(null);
        setGlobalEditor(null);
        blockFlushRef.current = null;
        setCollapsedSectionIds((prev) => ({
            ...prev,
            [sectionId]: false,
        }));

        syncOutlineForNewSectionBlock(newSectionBlock, afterHeadingId, { focusEdit: true });
        focusNewSectionHeader(sectionId);
    }, [
        commitActiveBlock,
        focusNewSectionHeader,
        resolveOutlineHeadingIdForSection,
        syncOutlineForNewSectionBlock,
    ]);

    const addSectionAfter = useCallback(
        (section) => {
            if (tempMergeRef.current || !section?.blockIds?.length) {
                return;
            }

            commitActiveBlock();

            const lastBlockId = section.blockIds[section.blockIds.length - 1];
            const newSectionBlock = createEmptySectionBlock();
            const sectionId = `section-${newSectionBlock.id}`;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === lastBlockId);
                if (index < 0) {
                    return prev;
                }

                const next = [...prev];
                next.splice(index + 1, 0, newSectionBlock);

                return normalizeBlocks(next);
            });
            setInsertMenu(null);
            setActiveBlockId(null);
            setGlobalEditor(null);
            blockFlushRef.current = null;
            setCollapsedSectionIds((prev) => ({
                ...prev,
                [sectionId]: false,
            }));

            const afterHeadingId = resolveOutlineHeadingIdForSection(section);
            syncOutlineForNewSectionBlock(newSectionBlock, afterHeadingId, { focusEdit: true });
            focusNewSectionHeader(sectionId);
        },
        [
            commitActiveBlock,
            focusNewSectionHeader,
            resolveOutlineHeadingIdForSection,
            syncOutlineForNewSectionBlock,
        ],
    );

    const insertFeaturedSnippetAsNewSectionAfter = useCallback(
        async (pending, html) => {
            if (tempMergeRef.current || !pending?.anchorLastBlockId) {
                return;
            }

            commitActiveBlock();

            const keyword = (focusKeyword || articleTitle || '').trim();
            const { headingBlock, contentBlocks } = parseFeaturedSnippetNewSectionBlocks(
                html,
                createEmptyTextBlock,
                keyword,
            );

            if (!headingBlock) {
                return;
            }

            const anchorSection = buildEditorSections(blocksRef.current).find(
                (item) => item.id === pending.anchorSectionId,
            );
            const insertBlocks = [headingBlock, ...contentBlocks];
            const sectionUiId = `section-${headingBlock.id}`;
            const lastBlockId =
                contentBlocks.length > 0 ? contentBlocks[contentBlocks.length - 1].id : headingBlock.id;

            setBlocks((prev) => {
                const index = prev.findIndex((b) => b.id === pending.anchorLastBlockId);
                if (index < 0) {
                    return prev;
                }

                const next = [...prev];
                next.splice(index + 1, 0, ...insertBlocks);

                return next;
            });

            setInsertMenu(null);
            setActiveBlockId(lastBlockId);
            setGlobalEditor(null);
            setCollapsedSectionIds((prev) => ({
                ...prev,
                [sectionUiId]: false,
            }));

            if (outlineHasSavedHeadings && anchorSection) {
                const meta = extractOutlineHeadingFromBlock(headingBlock);
                if (meta) {
                    const afterHeadingId = resolveOutlineHeadingIdForSection(anchorSection);
                    await appendOutlineHeadingForBlock(headingBlock.id, meta, { afterHeadingId });
                }
            }
        },
        [
            appendOutlineHeadingForBlock,
            articleTitle,
            commitActiveBlock,
            focusKeyword,
            outlineHasSavedHeadings,
            resolveOutlineHeadingIdForSection,
        ],
    );

    const runFeaturedSnippetPromptGenerate = useCallback(async () => {
        if (!canGenerateFeaturedSnippet || featuredSnippetGenerating) {
            return;
        }
        const keyword = (focusKeyword || articleTitle || '').trim();
        if (!keyword) {
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_generate_featured_snippet'),
                        body: t('editor_featured_snippet_no_keyword'),
                        status: 'warning',
                    },
                }),
            );
            return;
        }

        const sections = buildEditorSections(blocksRef.current);
        const anchorSection = [...sections].reverse().find((item) => !item.isIntro) ?? sections[0] ?? null;
        const anchorLastBlockId = anchorSection?.blockIds?.[anchorSection.blockIds.length - 1]
            ?? blocksRef.current[blocksRef.current.length - 1]?.id
            ?? null;
        if (!anchorLastBlockId) {
            return;
        }

        featuredSnippetTargetRef.current = {
            mode: 'prompt-preview',
            anchorSectionId: anchorSection?.id ?? null,
            anchorLastBlockId,
        };
        setFeaturedSnippetGenerating(true);
        setArticleAutosaveLock('generate-featured-snippet', true);

        try {
            await callEditArticleLivewire(
                'generateFeaturedSnippetFromEditor',
                anchorLastBlockId,
                'after',
            );
        } catch (error) {
            featuredSnippetTargetRef.current = null;
            setFeaturedSnippetGenerating(false);
            window.dispatchEvent(
                new CustomEvent('seo-article-editor-notify', {
                    detail: {
                        title: t('editor_generate_featured_snippet'),
                        body: error?.message ?? t('editor_featured_snippet_failed'),
                        status: 'danger',
                    },
                }),
            );
        } finally {
            setArticleAutosaveLock('generate-featured-snippet', false);
        }
    }, [articleTitle, canGenerateFeaturedSnippet, featuredSnippetGenerating, focusKeyword]);

    const confirmFeaturedSnippetPromptInsert = useCallback(() => {
        const pending = featuredSnippetTargetRef.current;
        const html = String(featuredSnippetPreviewHtml || pending?.previewHtml || '').trim();
        if (!html || !pending?.anchorLastBlockId) {
            return;
        }
        featuredSnippetTargetRef.current = null;
        setFeaturedSnippetPromptOpen(false);
        setFeaturedSnippetGenerating(true);
        void insertFeaturedSnippetAsNewSectionAfter(
            {
                mode: 'new-section-after',
                anchorSectionId: pending.anchorSectionId,
                anchorLastBlockId: pending.anchorLastBlockId,
            },
            html,
        ).finally(() => {
            setFeaturedSnippetGenerating(false);
            setFeaturedSnippetPreviewHtml('');
            markSeoStale();
        });
    }, [featuredSnippetPreviewHtml, insertFeaturedSnippetAsNewSectionAfter, markSeoStale]);

    const requestGenerateFeaturedSnippetAfterSection = useCallback(
        async (section) => {
            if (
                !canGenerateFeaturedSnippet ||
                featuredSnippetGenerating ||
                section?.isIntro ||
                !section?.blockIds?.length
            ) {
                return;
            }

            const keyword = (focusKeyword || articleTitle || '').trim();
            if (!keyword) {
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_generate_featured_snippet'),
                            body: t('editor_featured_snippet_no_keyword'),
                            status: 'warning',
                        },
                    }),
                );

                return;
            }

            featuredSnippetTargetRef.current = {
                mode: 'new-section-after',
                anchorSectionId: section.id,
                anchorLastBlockId: section.blockIds[section.blockIds.length - 1],
            };
            setFeaturedSnippetGenerating(true);
            setArticleAutosaveLock('generate-featured-snippet', true);

            try {
                await callEditArticleLivewire(
                    'generateFeaturedSnippetFromEditor',
                    featuredSnippetTargetRef.current.anchorLastBlockId,
                    'after',
                );
            } catch (error) {
                featuredSnippetTargetRef.current = null;
                setFeaturedSnippetGenerating(false);
                window.dispatchEvent(
                    new CustomEvent('seo-article-editor-notify', {
                        detail: {
                            title: t('editor_generate_featured_snippet'),
                            body: error?.message ?? t('editor_featured_snippet_failed'),
                            status: 'danger',
                        },
                    }),
                );
            } finally {
                setArticleAutosaveLock('generate-featured-snippet', false);
            }
        },
        [articleTitle, canGenerateFeaturedSnippet, featuredSnippetGenerating, focusKeyword],
    );

    const changeOutlineHeadingLevel = useCallback((node, level) => {
        mutateOutlineHeading(
            node,
            'change_heading_level',
            { level },
            (html, headingIndex) => changeHeadingLevelInHtml(html, headingIndex, level),
        );
    }, [mutateOutlineHeading]);

    const deleteOutlineHeadingKeepContent = useCallback((node) => {
        mutateOutlineHeading(
            node,
            'delete_heading_keep_content',
            {},
            deleteHeadingKeepContentInHtml,
        );
    }, [mutateOutlineHeading]);

    const deleteOutlineHeadingWithContent = useCallback((node) => {
        mutateOutlineHeading(
            node,
            'delete_heading_with_content',
            {},
            deleteHeadingWithContentInHtml,
        );
    }, [mutateOutlineHeading]);

    const toggleOutlineHeadingVisible = useCallback((node, visible) => {
        mutateOutlineHeading(
            node,
            'set_heading_outline_visible',
            { visible },
            (html, headingIndex) => setOutlineVisibleInHtml(html, headingIndex, visible),
        );
    }, [mutateOutlineHeading]);

    const addOutlineNode = useCallback((node, kind) => {
        if (kind === 'h2-below') {
            const section = editorSections.find((item) => item.blockIds?.includes(String(node?.block_id ?? '')));
            if (section) {
                addSectionAfter(section);
                return;
            }
            addSection();
            return;
        }

        const text = kind === 'paragraph'
            ? ''
            : `${t('editor_new_section_heading')} ${String(Date.now()).slice(-4)}`;
        const level = kind === 'h4-child' ? 4 : 3;
        const ok = mutateOutlineHeading(
            node,
            'insert_heading_after',
            {
                level,
                text: text || 'Heading',
                insertParagraph: kind !== 'paragraph',
                paragraphOnly: kind === 'paragraph',
            },
            (html, headingIndex) => insertAfterHeadingSectionInHtml(html, {
                headingIndex,
                level,
                text,
                paragraph: kind === 'paragraph',
            }),
        );

        if (ok && kind !== 'paragraph') {
            setOutlineTreeSync({
                token: Date.now(),
                action: 'focusNew',
                headingId: null,
                blockId: node?.block_id ?? null,
                matchText: text,
                parentHeadingId: node?.id ?? null,
                focusEdit: true,
            });
        }
    }, [addSection, addSectionAfter, editorSections, mutateOutlineHeading]);

    return { addOutlineNode, addSection, addSectionAfter, applyOutlineHeadingHtml, applyOutlineHeadingText, changeOutlineHeadingLevel, collapseAllSections, confirmFeaturedSnippetPromptInsert, deleteOutlineHeadingKeepContent, deleteOutlineHeadingWithContent, focusOutlineFromSectionHeader, handleOutlineHeadingFromEditor, handleOutlineLoaded, insertFeaturedSnippetAsNewSectionAfter, jumpToOutlineHeading, requestGenerateFeaturedSnippetAfterSection, resolveHeadingInnerHtml, runFeaturedSnippetPromptGenerate, saveSectionTitleFromHeader, toggleOutlineHeadingVisible, toggleSectionCollapse, updateOutlineHeadingTitle };
}
