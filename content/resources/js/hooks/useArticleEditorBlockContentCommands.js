import { bindEditorCommandHost, unbindEditorCommandHost } from '../utils/editorCommands';
import { canMutateEditor, getArticleEditorSessionState } from '../utils/editorSessionState';
import { getAnalysisPolicy } from '@seo-addon/utils/articleAnalysisOwnership.js';
import { getDefaultArticleEditorRuntime } from '../editor/runtime/defaultArticleEditorRuntime';
import { resolveEditorForInsertion } from '../utils/editorInsertionContext';
import { useCallback, useEffect } from 'react';

/**
 * useArticleEditorBlockContentCommands - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorBlockContentCommands({ activeBlockIdRef, articleId, blockEditorsRef, blockFlushRef, documentVersion, editorHostActionsRef, editorSettings, globalEditorRef, initialEditorDocumentHash, initialPostType, perfDebug, reconcileImagesTabWithBlocks, requestAnalyzeRef, scheduleAutosaveRef, sessionReadOnly, setBlocks, setRuntimeContextRevision, structureMutationRef, tempMergeRef }) {
    const updateBlockContent = useCallback((id, newContent, imageData) => {
        if (!canMutateEditor()) {
            return;
        }
        setBlocks((prev) => {
            const current = prev.find((b) => b.id === id);
            if (!current) {
                return prev;
            }

            const contentUnchanged = current.content === newContent;
            const imageUnchanged = imageData === undefined
                || (imageData === null
                    ? current.image === undefined
                    : current.image === imageData);
            if (contentUnchanged && imageUnchanged) {
                return prev;
            }

            const nextBlocks = prev.map((b) =>
                b.id === id
                    ? {
                          ...b,
                          content: newContent,
                          ...(imageData === null
                              ? { image: undefined }
                              : imageData
                                ? { image: imageData }
                                : {}),
                      }
                    : b,
            );

            if (imageData !== undefined) {
                queueMicrotask(() => reconcileImagesTabWithBlocks(nextBlocks));
            }

            return nextBlocks;
        });
    }, [reconcileImagesTabWithBlocks]);

    const registerBlockFlush = useCallback((fn) => {
        blockFlushRef.current = fn;
    }, []);

    const registerBlockEditor = useCallback((blockId, editor) => {
        if (!blockId) {
            return;
        }

        if (editor) {
            try {
                editor.setEditable(!sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__);
            } catch {
                // ignore
            }
            blockEditorsRef.current.set(blockId, editor);
            return;
        }

        blockEditorsRef.current.delete(blockId);
    }, [sessionReadOnly]);

    useEffect(() => {
        const writable = !sessionReadOnly && !window.__SEO_EDITOR_READ_ONLY__;
        blockEditorsRef.current.forEach((editor) => {
            try {
                if (editor && typeof editor.setEditable === 'function' && editor.isEditable !== writable) {
                    editor.setEditable(writable);
                }
            } catch {
                // ignore destroyed editors
            }
        });
    }, [sessionReadOnly]);

    const resolveActiveEditor = useCallback(() => {
        return resolveEditorForInsertion({
            blockEditors: blockEditorsRef.current,
            activeBlockId: activeBlockIdRef.current,
            globalEditor: globalEditorRef.current,
        });
    }, []);

    const commitActiveBlock = useCallback(() => {
        if (tempMergeRef.current) return;
        blockFlushRef.current?.();
    }, []);

    // Phase 4/6C.2 — bind command host + module actions (no window event bus for Links/CTA/FAQ insert).
    useEffect(() => {
        bindEditorCommandHost({
            articleId,
            getEditorRegistry: () => blockEditorsRef.current,
            getActiveEditorId: () => activeBlockIdRef.current,
            getGlobalEditor: () => globalEditorRef.current,
            getDocumentModel: () => null,
            getMediaSnapshot: () => null,
            getAnalysisPolicy: () => getAnalysisPolicy() || editorSettings?.analysis_policy || null,
            getDocumentVersion: () => window.__SEO_EDITOR_DOCUMENT_VERSION__ || documentVersion || null,
            getLocalRevision: () => Number(window.__SEO_EDITOR_LOCAL_REVISION__ || 0),
            isArchived: () => Boolean(window.__SEO_EDITOR_ARCHIVED__),
            hasConflict: () => Boolean(window.__SEO_EDITOR_DOCUMENT_CONFLICT__),
            dispatchDocumentChanged: (payload) => {
                window.__SEO_EDITOR_LOCAL_REVISION__ = Number(payload?.local_revision) || 0;
            },
            notify: (detail) => {
                window.dispatchEvent(new CustomEvent('seo-article-editor-notify', { detail }));
            },
            scheduleAutosave: () => scheduleAutosaveRef.current?.(),
            requestAnalyze: () => requestAnalyzeRef.current?.(),
            commitActiveBlock: () => commitActiveBlock(),
            onStructureMutation: (name, payload) => structureMutationRef.current?.(name, payload) ?? false,
            actions: {
                insertSuggestedLink: (detail) => editorHostActionsRef.current.insertSuggestedLink?.(detail),
                insertCtaLink: (detail) => editorHostActionsRef.current.insertCtaLink?.(detail),
                removeInternalLink: (detail) => editorHostActionsRef.current.removeInternalLink?.(detail),
                scrollToLink: (detail) => editorHostActionsRef.current.scrollToLink?.(detail),
                applyExtractedFaqs: (detail) => editorHostActionsRef.current.applyExtractedFaqs?.(detail),
                applyEditorBlockImage: (detail) => editorHostActionsRef.current.applyEditorBlockImage?.(detail),
                generateArticleImage: (detail) => editorHostActionsRef.current.generateArticleImage?.(detail),
                generateArticleVideo: (detail) => editorHostActionsRef.current.generateArticleVideo?.(detail),
                openAiMedia: (detail) => editorHostActionsRef.current.openAiMedia?.(detail),
                getActiveBlockId: () => activeBlockIdRef.current,
                getExportHtml: () => editorHostActionsRef.current.getExportHtml?.() ?? '',
                getSelectionHtml: () => editorHostActionsRef.current.getSelectionHtml?.() ?? '',
            },
        });

        return () => {
            unbindEditorCommandHost();
        };
    }, [articleId, commitActiveBlock, documentVersion, editorSettings?.analysis_policy]);

    // Phase 6A — sync internal runtime context (host bridge; modules must not read globals).
    useEffect(() => {
        const runtime = getDefaultArticleEditorRuntime({
            article: {
                id: articleId,
                type: initialPostType || null,
                documentVersion,
                editorDocumentHash: initialEditorDocumentHash,
            },
            workflow: {
                archived: Boolean(window.__SEO_EDITOR_ARCHIVED__),
                belongsToContentProject: Boolean(window.__SEO_EDITOR_CONTENT_PROJECT_ID__),
                manualWpSyncAllowed: !Boolean(window.__SEO_EDITOR_CONTENT_PROJECT_ID__),
            },
            session: (() => {
                const sessionState = getArticleEditorSessionState();
                const writable = !sessionReadOnly
                    && !window.__SEO_EDITOR_READ_ONLY__
                    && Boolean(sessionState?.writable);
                const status = writable
                    ? 'active'
                    : String(sessionState?.status || (sessionReadOnly ? 'locked' : 'read_only'));
                return {
                    id: sessionState?.session_id ?? null,
                    writable,
                    read_only: !writable,
                    status,
                    conflict: Boolean(window.__SEO_EDITOR_DOCUMENT_CONFLICT__)
                        || status === 'conflict',
                };
            })(),
            policy: {
                analysis: editorSettings?.analysis_policy || null,
            },
            document: {
                editorRegistry: blockEditorsRef.current,
                commandExecutor: null,
            },
            snapshots: {
                media: null,
                faq: null,
                analysis: null,
            },
        });
        setRuntimeContextRevision(runtime.getCreateGeneration());
        if (perfDebug && typeof window !== 'undefined') {
            window.__SEO_EDITOR_RUNTIME_DIAGNOSTICS__ = runtime.getDiagnostics();
        }
    }, [
        articleId,
        documentVersion,
        sessionReadOnly,
        initialPostType,
        initialEditorDocumentHash,
        editorSettings?.analysis_policy,
        perfDebug,
    ]);

    return { commitActiveBlock, registerBlockEditor, registerBlockFlush, updateBlockContent };
}
