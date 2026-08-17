import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import BlockFormatToolbar from './BlockFormatToolbar';
import BlockEditorResizeHandle, { useBlockEditorHeight } from './BlockEditorResizeHandle';
import { EditorInspectorBubbleHost } from '../editor/host/EditorInspectorBubbleHost';
import ArticleHtmlInspectorModal from './ArticleHtmlInspectorModal';
import { resolveLinkEditorAnchorRect } from '../utils/linkEditorAnchor';
import { normalizeInlineLinks, analyzeInlineLinks } from '../utils/inlineLinkNormalizer';
import {
    captureEditorInsertionContext,
    getEditorInsertionContext,
    freezeEditorInsertionContext,
    isAssistantFocusStealTarget,
} from '../utils/editorInsertionContext';
import { normalizeOrphanQuoteCharacters } from '../utils/orphanQuoteNormalizer';
import {
    TIPTAP_HTML_PARSE_OPTIONS,
} from '../utils/inlineWhitespaceGuard';
import { isUsableTipTapDocument } from '../utils/articleEditorDocument';
import {
    ensureTiptapHeadingCursorParagraph,
    leadingHeadingLevel,
    persistBlockHtmlFromEditor,
    resetTipTapEditorHistory,
} from '../utils/editorHtmlUtils';
import { isSameTiptapBlockContent } from '../utils/contentDocumentHelpers';
import { getDefaultArticleEditorRuntime } from '../editor/runtime/defaultArticleEditorRuntime';
import { createClipboardPasteHandler } from '@media-addon/utils/seoMediaApi.js';
import { t } from '../utils/i18n';
import {
    getSelectionHtmlFromEditor,
    getSelectionTextFromEditor,
} from '../utils/editorSelectionUtils';
import EditorContextMenu from './EditorContextMenu';
import { findHeadingByIndex } from '../utils/editorCommands/headingSplitEngine';
import { captureEditorContextMenuSnapshot } from '../utils/editorContextMenuController';

/**
 * Active (focused) TipTap block editor extracted from SeoArticleEditor.jsx
 * (Task 7 frontend extraction). Mechanical move - no behavior change.
 */
export default
function ActiveBlockEditor({
    block,
    sectionId = null,
    displayContent,
    suppressBlockUpdate,
    onUpdate,
    onRegisterFlush,
    onRegisterEditor,
    setGlobalEditor,
    onDelete,
    canDeleteBlock,
    articleId,
    siteId,
    editable = true,
    focusHeadingIndex = null,
    focusHeadingToken = 0,
}) {
    const [linkAnchor, setLinkAnchor] = useState(null);
    const [htmlInspectorOpen, setHtmlInspectorOpen] = useState(false);
    const [htmlInspectorSnapshot, setHtmlInspectorSnapshot] = useState('');
    const [blockStyleTick, setBlockStyleTick] = useState(0);
    const [contextMenu, setContextMenu] = useState(null);
    const editorContainerRef = useRef(null);
    const sourceHtml = displayContent ?? block.content;
    const isHydratingRef = useRef(false);
    const acceptUpdatesRef = useRef(false);
    const initialEditorContent = useMemo(() => {
        if (isUsableTipTapDocument(block.editorDocument)) {
            return block.editorDocument;
        }

        return normalizeOrphanQuoteCharacters(ensureTiptapHeadingCursorParagraph(sourceHtml) || '<p></p>');
    }, [block.id]);
    const { minHeight, setMinHeight, persistHeight, minH, maxH } = useBlockEditorHeight(block.id);

    const pushHtml = useCallback(
        (html) => {
            if (suppressBlockUpdate || isHydratingRef.current || !acceptUpdatesRef.current) return;
            onUpdate(persistBlockHtmlFromEditor(sourceHtml, html));
        },
        [suppressBlockUpdate, onUpdate, sourceHtml],
    );

    const clipboardPasteHandler = useCallback(
        createClipboardPasteHandler({
            articleId,
            siteId,
            defaultAltTitle: (window.__SEO_MAIN_KEYWORD__ ?? '').trim(),
        }),
        [articleId, siteId],
    );

    // TipTap v3 useEditor runs compareOptions after every render (empty deps).
    // Unstable editorProps/extensions → setOptions → selectionUpdate → setState → #185 loop.
    const documentExtensions = useMemo(
        () => getDefaultArticleEditorRuntime().getDocumentExtensions(),
        [],
    );
    const editorProps = useMemo(() => ({
        attributes: {
            class: 'prose prose-slate max-w-none dark:prose-invert min-h-[48px] focus:outline-none tiptap-editor-content',
            'data-placeholder': t('editor_enter_content'),
        },
        handlePaste: clipboardPasteHandler,
    }), [clipboardPasteHandler]);

    // Phase 6A — TipTap extensions from internal runtime registry (stable identity).
    const editor = useEditor({
        extensions: documentExtensions,
        content: initialEditorContent,
        editable: Boolean(editable),
        parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
        editorProps,
        onCreate: () => {
            // Initial content must not dirty / autosave. Enable after first paint.
            acceptUpdatesRef.current = false;
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    acceptUpdatesRef.current = true;
                });
            });
        },
        onUpdate: ({ editor: ed, transaction }) => {
            if (!acceptUpdatesRef.current || isHydratingRef.current) {
                return;
            }
            if (transaction?.getMeta?.('preventUpdate')) {
                return;
            }
            setBlockStyleTick((n) => n + 1);
            pushHtml(ed.getHTML());
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
        },
        onSelectionUpdate: ({ editor: ed }) => {
            setBlockStyleTick((n) => n + 1);
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
        },
        onBlur: ({ editor: ed, event }) => {
            const related = event?.relatedTarget ?? document.activeElement;
            if (isAssistantFocusStealTarget(related)) {
                // Freeze last caret; do not let blur rewrite bookmark to doc end.
                freezeEditorInsertionContext(
                    (() => {
                        captureEditorInsertionContext({
                            sectionId,
                            blockId: block.id,
                            editor: ed,
                        });
                        return getEditorInsertionContext();
                    })(),
                );
                return;
            }
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
        },
        onFocus: ({ editor: ed }) => {
            setGlobalEditor(ed);
            captureEditorInsertionContext({
                sectionId,
                blockId: block.id,
                editor: ed,
            });
        },
    }, [block.id]);

    useEffect(() => {
        if (!editor) return;

        resetTipTapEditorHistory(editor);
    }, [editor]);

    useEffect(() => {
        if (!editor) return;
        const nextEditable = Boolean(editable);
        if (editor.isEditable === nextEditable) {
            return;
        }
        editor.setEditable(nextEditable);
    }, [editor, editable]);

    useEffect(() => {
        if (!editor) return;

        if (isUsableTipTapDocument(block.editorDocument)) {
            // JSON hydrate once per block mount; avoid HTML re-parse remount.
            return;
        }

        const nextHtml = normalizeOrphanQuoteCharacters(
            ensureTiptapHeadingCursorParagraph(sourceHtml) || '<p></p>',
        );
        // Khi user đang gõ, parent state đổi theo từng key stroke. Nếu hydrate lại
        // bằng setContent dù HTML tương đương, Tiptap sẽ reset selection/caret về cuối đoạn.
        if (isSameTiptapBlockContent(sourceHtml, editor.getHTML(), nextHtml)) {
            return;
        }

        isHydratingRef.current = true;
        acceptUpdatesRef.current = false;
        editor.commands.setContent(nextHtml, {
            emitUpdate: false,
            parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
        });
        resetTipTapEditorHistory(editor);
        isHydratingRef.current = false;
        requestAnimationFrame(() => {
            acceptUpdatesRef.current = true;
        });
    }, [editor, block.id, sourceHtml]);

    useEffect(() => {
        if (!editor) {
            return undefined;
        }

        onRegisterEditor?.(editor);

        return () => {
            onRegisterEditor?.(null);
        };
    }, [editor, onRegisterEditor]);

    useEffect(() => {
        if (!onRegisterFlush) return undefined;

        onRegisterFlush(() => {
            if (!editor || editor.isDestroyed) return;
            pushHtml(editor.getHTML());
        });

        return () => onRegisterFlush(null);
    }, [editor, onRegisterFlush, pushHtml]);

    useEffect(() => {
        if (editor) {
            editor.commands.focus();
            setGlobalEditor(editor);
        }
    }, [editor, setGlobalEditor]);

    useEffect(() => {
        if (!editor) {
            return undefined;
        }

        const publishIntraSelection = () => {
            const text = getSelectionTextFromEditor(editor);
            const html = getSelectionHtmlFromEditor(editor);

            window.dispatchEvent(
                new CustomEvent('seo-editor-intra-selection', {
                    detail: { text, html },
                }),
            );
        };

        editor.on('selectionUpdate', publishIntraSelection);
        publishIntraSelection();

        return () => {
            editor.off('selectionUpdate', publishIntraSelection);
        };
    }, [editor]);

    const openLinkEditorAtSelection = useCallback((savedSelection = null) => {
        if (!editor) {
            return;
        }

        if (
            savedSelection &&
            typeof savedSelection.from === 'number' &&
            typeof savedSelection.to === 'number'
        ) {
            const docSize = editor.state.doc.content.size;
            const from = Math.min(Math.max(0, savedSelection.from), docSize);
            const to = Math.min(Math.max(from, savedSelection.to), docSize);
            editor.chain().focus().setTextSelection({ from, to }).run();
        }

        // Chỉ extend khi caret nằm trong link (selection rỗng). Selection có vùng chọn
        // phải giữ nguyên — tránh cắt cụm text+strong thành một phần link cũ.
        if (editor.isActive('link') && editor.state.selection.empty) {
            editor.chain().focus().extendMarkRange('link').run();
        }

        const rect = resolveLinkEditorAnchorRect(editor);
        if (rect) {
            setLinkAnchor(rect);
        }
    }, [editor]);

    useEffect(() => {
        if (!editor) return undefined;

        const onLinkClick = (event) => {
            const link = event.target?.closest?.('a');
            if (!link || !editor.view.dom.contains(link)) return;

            event.preventDefault();
            event.stopPropagation();

            const start = editor.view.posAtDOM(link, 0);
            const end = editor.view.posAtDOM(link, link.childNodes.length);
            editor.chain().focus().setTextSelection({ from: start, to: end }).run();
            const rect = resolveLinkEditorAnchorRect(editor);
            if (rect) {
                setLinkAnchor(rect);
            }
        };

        editor.view.dom.addEventListener('click', onLinkClick, true);
        return () => editor.view.dom.removeEventListener('click', onLinkClick, true);
    }, [editor]);

    useEffect(() => {
        if (!editor || editor.isDestroyed || focusHeadingIndex == null) {
            return undefined;
        }

        const found = findHeadingByIndex(editor.state.doc, Number(focusHeadingIndex));
        if (!found) {
            return undefined;
        }

        const from = found.pos + 1;
        const to = found.pos + found.node.nodeSize - 1;
        editor.chain().focus().setTextSelection({ from, to }).run();
        try {
            const dom = editor.view.nodeDOM(found.pos);
            const el = dom instanceof HTMLElement ? dom : dom?.parentElement;
            el?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
        } catch {
            // ignore
        }

        return undefined;
    }, [editor, focusHeadingIndex, focusHeadingToken]);

    const handleContextMenu = useCallback((event) => {
        if (!editor || editor.isDestroyed || !editable) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        const snapshot = captureEditorContextMenuSnapshot(editor, {
            clientX: event.clientX,
            clientY: event.clientY,
            blockId: block.id,
        });
        if (!snapshot) {
            return;
        }
        setContextMenu({
            x: event.clientX,
            y: event.clientY,
            snapshot,
        });
    }, [block.id, editable, editor]);

    return (
        <div className="block-editor-active" ref={editorContainerRef}>
            <span className="block-editor-badge" data-style-tick={blockStyleTick}>
                {suppressBlockUpdate
                    ? t('editor_temp_merge')
                    : block.isWp
                        ? 'WP Block'
                        : (() => {
                            if (editor) {
                                for (let level = 1; level <= 6; level += 1) {
                                    if (editor.isActive('heading', { level })) {
                                        return t(`style_heading_${level}`);
                                    }
                                }
                                if (editor.isActive('codeBlock')) {
                                    return t('style_preformatted');
                                }
                            }
                            const level = leadingHeadingLevel(sourceHtml);
                            if (level) {
                                return t(`style_heading_${level}`);
                            }
                            return t('editor_paragraph');
                        })()}
            </span>
            <BlockFormatToolbar
                editor={editor}
                onDelete={onDelete}
                canDelete={canDeleteBlock}
                onEditLink={openLinkEditorAtSelection}
                onViewHtml={() => {
                    if (!editor) {
                        return;
                    }
                    const current = editor.getHTML();
                    const analysis = analyzeInlineLinks(current);
                    if (analysis.duplicateAdjacentCount > 0 || analysis.nestedAnchorCount > 0) {
                        // Dev-only signal — không toast khi đang gõ; chỉ khi mở inspector.
                        if (typeof process !== 'undefined' && process.env?.NODE_ENV === 'development') {
                            // eslint-disable-next-line no-console
                            console.warn('[SeoArticleEditor] split/nested anchors detected', analysis.warnings);
                        }
                    }
                    setHtmlInspectorSnapshot(current);
                    setHtmlInspectorOpen(true);
                }}
            />
            <div
                className="seo-block-editor-body px-2 pb-2"
                style={{ minHeight }}
                onContextMenu={handleContextMenu}
            >
                <EditorContent editor={editor} />
            </div>
            <EditorContextMenu
                editor={editor}
                snapshot={contextMenu?.snapshot ?? null}
                siteId={siteId}
                articleId={articleId}
                open={Boolean(contextMenu)}
                x={contextMenu?.x ?? 0}
                y={contextMenu?.y ?? 0}
                onClose={() => setContextMenu(null)}
            />
            <BlockEditorResizeHandle
                minHeight={minHeight}
                minH={minH}
                maxH={maxH}
                onMinHeightChange={setMinHeight}
                onResizeEnd={persistHeight}
            />
            {linkAnchor && editor ? (
                <EditorInspectorBubbleHost
                    runtime={getDefaultArticleEditorRuntime()}
                    slot="bubble.link"
                    editor={editor}
                    anchorRect={linkAnchor}
                    containerRef={editorContainerRef}
                    onClose={() => setLinkAnchor(null)}
                    articleId={articleId}
                    siteId={siteId}
                />
            ) : null}
            <ArticleHtmlInspectorModal
                open={htmlInspectorOpen}
                html={htmlInspectorSnapshot}
                onClose={() => setHtmlInspectorOpen(false)}
                onApplyHtml={(nextHtml) => {
                    if (!editor) {
                        return { ok: false, error: t('html_inspector_invalid_html') };
                    }
                    try {
                        const normalized = normalizeInlineLinks(String(nextHtml ?? ''));
                        editor.commands.setContent(normalized || '<p></p>', {
                            emitUpdate: true,
                            parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
                        });
                        setHtmlInspectorSnapshot(editor.getHTML());
                        return { ok: true };
                    } catch (error) {
                        return {
                            ok: false,
                            error: error instanceof Error ? error.message : t('html_inspector_invalid_html'),
                        };
                    }
                }}
            />
        </div>
    );
}

