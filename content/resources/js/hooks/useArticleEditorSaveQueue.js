import { INLINE_WHITESPACE_CORRUPTION_CODE, hasInlineWhitespaceCorruption, plainTextFromHtmlLoose } from '../utils/inlineWhitespaceGuard';
import {
    applyEditorDocumentAck,
    buildArticleEditorApiPayload,
    getEditorConflictTokens,
    logArticleEditorVersionDebug,
} from '../utils/articleEditorApi';
import { buildEditorDocumentEnvelope } from '../utils/articleEditorDocument';
import { cancelPendingServerAutosave, saveArticleViaApiSingleFlight, shouldSuppressServerAutosave } from '../utils/articleEditorSaveQueue';
import {
    hashContent,
    isDraftPersistenceEnabled,
    saveDraft,
    setDraftPersistenceEnabled,
} from '../utils/articleEditorStorage';
import { isArticleAutosaveLocked } from '../utils/articleAutosaveLock';
import { isArticleEditorNetworkError } from '../utils/articleEditorNetwork';
import { t } from '../utils/i18n';
import { useArticleEditorHistory } from '../hooks/useArticleEditorHistory';
import { useCallback, useEffect, useRef } from 'react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';

/**
 * useArticleEditorSaveQueue - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorSaveQueue({ articleId, blockEditorsRef, blocks, blocksRef, bootstrapBodyPlainRef, connectionHashRef, documentVersion, draftSaveDelayMs, draftSaveDisabled, getExportHtml, historyStep, lastAutosaveHashRef, markRecoveringClear, networkUnavailableRef, noteLocalRevisionChanged, scheduleAutosaveRef, scheduleServerAutosaveRef, serverAutosaveDebounceMs, serverAutosaveDirtyRef, serverAutosaveInFlightRef, serverAutosaveNeedsRetryRef, serverAutosaveSeqRef, sessionReadOnly, setActiveBlockId, setBlocks, setSaveStatus, setTempMerge, whitespaceCorruptionLockedRef, withDraftSite }) {
    const assertWritableDocumentNotWhitespaceCorrupted = useCallback((html) => {
        const base = String(bootstrapBodyPlainRef.current ?? '').trim();
        if (base === '') {
            return true;
        }
        const candidate = plainTextFromHtmlLoose(html);
        if (!hasInlineWhitespaceCorruption(base, candidate)) {
            whitespaceCorruptionLockedRef.current = false;
            return true;
        }
        if (!whitespaceCorruptionLockedRef.current) {
            whitespaceCorruptionLockedRef.current = true;
            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('editor_inline_whitespace_corruption_title'),
                    body: t('editor_inline_whitespace_corruption_body'),
                    status: 'danger',
                    code: INLINE_WHITESPACE_CORRUPTION_CODE,
                },
            }));
        }
        return false;
    }, []);

    const scheduleServerAutosave = useCallback((options = {}) => {
        const immediate = Boolean(options?.immediate);
        if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) {
            return;
        }
        if (networkUnavailableRef.current && !immediate) {
            // Offline: keep dirty flags, do not spam PUT.
            serverAutosaveDirtyRef.current = true;
            return;
        }
        if (!assertWritableDocumentNotWhitespaceCorrupted(getExportHtml())) {
            return;
        }
        const client = window.__seoEditorSessionClient;
        if (!client || client.readOnly || !client.sessionId) {
            return;
        }
        serverAutosaveDirtyRef.current = true;
        if (serverAutosaveInFlightRef.current) {
            return;
        }

        cancelPendingServerAutosave();
        const delayMs = immediate ? 0 : serverAutosaveDebounceMs;
        window.__seoServerAutosaveTimer = window.setTimeout(async () => {
            if (!serverAutosaveDirtyRef.current) {
                return;
            }
            if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || window.__SEO_EDITOR_EXITING__) {
                return;
            }
            if (networkUnavailableRef.current) {
                // Still unreachable — wait for reconnect monitor.
                return;
            }
            if (shouldSuppressServerAutosave()) {
                // Explicit Save owns write queue — re-check after suppress window.
                serverAutosaveDirtyRef.current = true;
                window.__seoServerAutosaveTimer = window.setTimeout(() => {
                    if (serverAutosaveDirtyRef.current) {
                        scheduleServerAutosave();
                    }
                }, 1000);
                return;
            }
            const activeClient = window.__seoEditorSessionClient;
            if (!activeClient || activeClient.readOnly || !activeClient.sessionId) {
                return;
            }

            const htmlAtSend = getExportHtml();
            const currentBodyHash = hashContent(htmlAtSend);
            const tokens = getEditorConflictTokens();
            const ackBodyHash = String(tokens.expected_content_hash || '').trim();
            const ackDocHash = String(window.__SEO_EDITOR_DOCUMENT_HASH__ || '').trim();
            // Client unchanged-skip: same ACK body hash, no failed write pending retry.
            // Do not clear editor dirty UI — only skip network PUT.
            if (
                !serverAutosaveNeedsRetryRef.current
                && currentBodyHash !== ''
                && ackBodyHash !== ''
                && currentBodyHash === ackBodyHash
                && lastAutosaveHashRef.current === currentBodyHash
            ) {
                serverAutosaveDirtyRef.current = false;
                markRecoveringClear();
                return;
            }
            // First ACK after load: lastAutosaveHash empty — still skip if body matches ACK
            // and we already have document hash from bootstrap (idle open).
            if (
                !serverAutosaveNeedsRetryRef.current
                && currentBodyHash !== ''
                && ackBodyHash !== ''
                && currentBodyHash === ackBodyHash
                && ackDocHash !== ''
                && lastAutosaveHashRef.current === ''
            ) {
                lastAutosaveHashRef.current = currentBodyHash;
                serverAutosaveDirtyRef.current = false;
                markRecoveringClear();
                return;
            }

            serverAutosaveDirtyRef.current = false;
            serverAutosaveInFlightRef.current = true;
            const seq = ++serverAutosaveSeqRef.current;
            try {
                const result = await saveArticleViaApiSingleFlight(articleId, async () => {
                    const editorDocument = buildEditorDocumentEnvelope(blocksRef.current, blockEditorsRef.current);
                    const payload = buildArticleEditorApiPayload({
                        articleId,
                        html: getExportHtml() || htmlAtSend,
                        editor_document: editorDocument,
                        expected_editor_document_hash: window.__SEO_EDITOR_DOCUMENT_HASH__ || null,
                        client_document_hash: currentBodyHash,
                        seoAnalysis: null,
                    }, null);
                    payload.save_mode = 'autosave';
                    payload.client_document_hash = currentBodyHash;
                    return payload;
                }, { priority: 'autosave' });
                if (result?.suppressed_autosave) {
                    serverAutosaveDirtyRef.current = true;
                    return;
                }
                // Stale ACK must not overwrite newer local edits.
                if (seq !== serverAutosaveSeqRef.current) {
                    return;
                }
                logArticleEditorVersionDebug('autosave_ack', {
                    noop: Boolean(result?.noop),
                    reconciled: Boolean(result?.reconciled),
                    document_version: result?.document_version ?? null,
                    content_hash: String(result?.content_hash || '').slice(0, 12) || null,
                });
                applyEditorDocumentAck(result);
                if (result?.content_hash) {
                    lastAutosaveHashRef.current = String(result.content_hash);
                } else if (currentBodyHash) {
                    lastAutosaveHashRef.current = currentBodyHash;
                }
                serverAutosaveNeedsRetryRef.current = false;
                markRecoveringClear();
            } catch (error) {
                serverAutosaveDirtyRef.current = true;
                serverAutosaveNeedsRetryRef.current = true;
                if (isArticleEditorNetworkError(error)) {
                    // Persistent banner via network monitor — no toast spam.
                    networkUnavailableRef.current = true;
                } else {
                    // App/HTTP error: backend reachable; clear recovering banner only.
                    markRecoveringClear();
                }
            } finally {
                serverAutosaveInFlightRef.current = false;
                if (serverAutosaveDirtyRef.current && !networkUnavailableRef.current) {
                    scheduleServerAutosave();
                }
            }
        }, delayMs);
    }, [articleId, assertWritableDocumentNotWhitespaceCorrupted, getExportHtml, markRecoveringClear, serverAutosaveDebounceMs, sessionReadOnly]);

    scheduleServerAutosaveRef.current = scheduleServerAutosave;

    const { debounced: debouncedLocalSave, cancel: cancelLocalDraftSave } = useDebouncedCallback(() => {
        if (!articleId || draftSaveDisabled) return;
        if (!isDraftPersistenceEnabled() || window.__SEO_EDITOR_EXITING__) return;
        if (isArticleAutosaveLocked()) return;
        const html = getExportHtml();
        if (!assertWritableDocumentNotWhitespaceCorrupted(html)) return;
        setSaveStatus('saving');
        const tokens = getEditorConflictTokens();
        saveDraft(articleId, connectionHashRef.current, withDraftSite({
            content: html,
            editor_document: buildEditorDocumentEnvelope(blocksRef.current, blockEditorsRef.current),
            editor_document_schema_version: 1,
            base_editor_document_hash: window.__SEO_EDITOR_DOCUMENT_HASH__ || null,
            base_updated_at: tokens.expected_updated_at || null,
            base_content_hash: tokens.expected_content_hash || null,
            base_document_version: window.__SEO_EDITOR_DOCUMENT_VERSION__ || documentVersion || null,
            user_id: window.__SEO_EDITOR_CURRENT_USER_ID__ || null,
        }));
        setSaveStatus('saved');
        scheduleServerAutosave();
    }, draftSaveDelayMs);

    // scheduleAutosave chỉ lo lưu nháp local — KHÔNG còn gọi SEO analyze (đó là nguồn lag khi gõ).
    // Analyze giờ chỉ chạy khi requestAnalyze() được gọi rõ ràng (nút Phân tích / sau hành động cụ thể).
    const scheduleAutosave = useCallback(() => {
        if (draftSaveDisabled || window.__SEO_EDITOR_EXITING__) {
            return;
        }
        if (!isDraftPersistenceEnabled() || isArticleAutosaveLocked()) {
            return;
        }
        if (!assertWritableDocumentNotWhitespaceCorrupted(getExportHtml())) {
            return;
        }
        noteLocalRevisionChanged();
        if (networkUnavailableRef.current) {
            setSaveStatus('pending');
            serverAutosaveDirtyRef.current = true;
            // Keep dirty in runtime; skip local→server chain spam while offline.
            return;
        }
        setSaveStatus('pending');
        debouncedLocalSave();
    }, [assertWritableDocumentNotWhitespaceCorrupted, debouncedLocalSave, draftSaveDisabled, getExportHtml, noteLocalRevisionChanged]);

    scheduleAutosaveRef.current = scheduleAutosave;

    useEffect(() => {
        window.__SEO_EDITOR_EXITING__ = false;
        setDraftPersistenceEnabled(true);
        window.__seoCancelArticleDraftAutosave = cancelLocalDraftSave;
        window.__seoDisableArticleDraftPersistence = () => {
            setDraftPersistenceEnabled(false);
            cancelLocalDraftSave();
        };

        return () => {
            if (window.__seoCancelArticleDraftAutosave === cancelLocalDraftSave) {
                delete window.__seoCancelArticleDraftAutosave;
            }
            delete window.__seoDisableArticleDraftPersistence;
        };
    }, [cancelLocalDraftSave]);

    const skipNextAutosave = useRef(true);
    const loadedArticleIdRef = useRef(null);

    const clearTempMerge = useCallback(() => {
        setTempMerge(null);
    }, []);

    const {
        undo,
        redo,
        canUndo,
        canRedo,
        historySteps,
        updateBlocksWithoutHistory,
    } = useArticleEditorHistory({
        articleId,
        historyStep,
        blocks,
        setBlocks,
        setActiveBlockId: (id) => {
            clearTempMerge();
            setActiveBlockId(id);
        },
        getExportHtml,
    });

    return { assertWritableDocumentNotWhitespaceCorrupted, canRedo, canUndo, cancelLocalDraftSave, clearTempMerge, historySteps, loadedArticleIdRef, redo, scheduleAutosave, skipNextAutosave, undo, updateBlocksWithoutHistory };
}
