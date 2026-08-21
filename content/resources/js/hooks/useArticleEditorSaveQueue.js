import { INLINE_WHITESPACE_CORRUPTION_CODE } from '../utils/inlineWhitespaceGuard';
import {
    applyEditorDocumentAck,
    buildArticleEditorApiPayload,
    getEditorConflictTokens,
    logArticleEditorVersionDebug,
} from '../utils/articleEditorApi';
import { buildEditorDocumentEnvelope } from '../utils/articleEditorDocument';
import { cancelPendingServerAutosave, saveArticleViaApiSingleFlight, shouldSuppressServerAutosave } from '../utils/articleEditorSaveQueue';
import {
    GUARD_REASON,
    SAVE_FAILURE,
    classifyArticleSaveError,
    inspectWritableDocument,
    isUnstableHollowExport,
    logArticleEditorSaveGuard,
    resolveLocalRecoveryDebounceMs,
    shouldClearLocalRecoveryAfterSave,
} from '../utils/articleEditorSaveGuard';
import {
    hashContent,
    isDraftPersistenceEnabled,
    loadDraft,
    saveDraft,
    setDraftPersistenceEnabled,
    writeSyncedLocalSnapshot,
} from '../utils/articleEditorStorage';
import { isArticleAutosaveLocked } from '../utils/articleAutosaveLock';
import { t } from '../utils/i18n';
import { useArticleEditorHistory } from '../hooks/useArticleEditorHistory';
import { useCallback, useEffect, useRef } from 'react';
import { useDebouncedCallback } from '../hooks/useDebouncedCallback';

/**
 * useArticleEditorSaveQueue - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Recovery draft is written even when the server whitespace guard blocks.
 */
export default function useArticleEditorSaveQueue({ articleId, blockEditorsRef, blocks, blocksRef, bootstrapBodyPlainRef, connectionHashRef, documentVersion, draftSaveDelayMs, draftSaveDisabled, getExportHtml, historyStep, lastAutosaveHashRef, markRecoveringClear, networkUnavailableRef, noteLocalRevisionChanged, scheduleAutosaveRef, scheduleServerAutosaveRef, serverAutosaveDebounceMs, serverAutosaveDirtyRef, serverAutosaveInFlightRef, serverAutosaveNeedsRetryRef, serverAutosaveSeqRef, sessionReadOnly, setActiveBlockId, setBlocks, setSaveStatus, setTempMerge, whitespaceCorruptionLockedRef, withDraftSite }) {
    const localRecoveryDelayMs = resolveLocalRecoveryDebounceMs(draftSaveDelayMs);

    const persistLocalRecoverySnapshot = useCallback((options = {}) => {
        if (!articleId || sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) {
            return false;
        }
        const lifecycleState = String(window.__SEO_EDITOR_CONTENT_LIFECYCLE__?.state || '');
        if (
            lifecycleState === 'SYNC_REQUIRED'
            || lifecycleState === 'CONTENT_LOADING'
            || lifecycleState === 'ERROR'
        ) {
            return false;
        }
        if (!isDraftPersistenceEnabled() && options.force !== true) {
            return false;
        }
        const html = typeof options.html === 'string' ? options.html : getExportHtml();
        const scoped = withDraftSite({});
        const existing = loadDraft(articleId, connectionHashRef.current, {
            siteId: Number(scoped.site_id ?? 0) || 0,
        });
        const baselineHtml = String(existing?.content ?? '');
        if (isUnstableHollowExport(html, baselineHtml, { mutationInProgress: isArticleAutosaveLocked() })) {
            return false;
        }
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
            dirty_fields: ['content'],
            synced: false,
            autosave_error: options.autosaveError ?? null,
        }));

        return true;
    }, [articleId, documentVersion, getExportHtml, sessionReadOnly, withDraftSite]);

    const notifyProtectedBlock = useCallback((reason, html, saveMode) => {
        logArticleEditorSaveGuard({
            article_id: articleId,
            guard_reason: reason,
            save_mode: saveMode,
            request_sequence: serverAutosaveSeqRef.current,
            autosave: saveMode === 'autosave',
            content_length: String(html ?? '').length,
        });
        if (reason === GUARD_REASON.INLINE_WHITESPACE && !whitespaceCorruptionLockedRef.current) {
            whitespaceCorruptionLockedRef.current = true;
            window.dispatchEvent(new CustomEvent('seo-article-editor-notify', {
                detail: {
                    title: t('editor_inline_whitespace_corruption_title'),
                    body: t('editor_inline_whitespace_corruption_body'),
                    status: 'warning',
                    code: INLINE_WHITESPACE_CORRUPTION_CODE,
                },
            }));
        }
        persistLocalRecoverySnapshot({
            html,
            force: true,
            autosaveError: SAVE_FAILURE.PROTECTED_BLOCK,
        });
        setSaveStatus('blocked');
        serverAutosaveDirtyRef.current = true;
        serverAutosaveNeedsRetryRef.current = true;
    }, [persistLocalRecoverySnapshot, setSaveStatus]);

    const assertWritableDocumentNotWhitespaceCorrupted = useCallback((html) => {
        const inspection = inspectWritableDocument(html, bootstrapBodyPlainRef.current);
        if (inspection.ok) {
            whitespaceCorruptionLockedRef.current = false;

            return true;
        }
        notifyProtectedBlock(inspection.reason, html, 'assert');

        return false;
    }, [notifyProtectedBlock]);

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
        const htmlNow = getExportHtml();
        persistLocalRecoverySnapshot({ html: htmlNow });
        if (!assertWritableDocumentNotWhitespaceCorrupted(htmlNow)) {
            return;
        }
        const client = window.__seoEditorSessionClient;
        if (!client || client.readOnly || !client.sessionId) {
            return;
        }
        if (draftSaveDisabled) {
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
            if (isArticleAutosaveLocked()) {
                logArticleEditorSaveGuard({
                    guard_reason: GUARD_REASON.MUTATION_IN_PROGRESS,
                    save_mode: 'autosave',
                    request_sequence: serverAutosaveSeqRef.current,
                    autosave: true,
                });
                serverAutosaveDirtyRef.current = true;
                window.__seoServerAutosaveTimer = window.setTimeout(() => {
                    if (serverAutosaveDirtyRef.current) {
                        scheduleServerAutosave();
                    }
                }, 400);
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
            persistLocalRecoverySnapshot({ html: htmlAtSend });
            if (isUnstableHollowExport(htmlAtSend, String(bootstrapBodyPlainRef.current ?? ''), {
                mutationInProgress: true,
            })) {
                logArticleEditorSaveGuard({
                    guard_reason: GUARD_REASON.CONTENT_TRUNCATED,
                    save_mode: 'autosave',
                    request_sequence: serverAutosaveSeqRef.current,
                    autosave: true,
                });
                serverAutosaveDirtyRef.current = true;
                return;
            }
            if (!assertWritableDocumentNotWhitespaceCorrupted(htmlAtSend)) {
                return;
            }
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
            setSaveStatus('saving');
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
                    serverAutosaveDirtyRef.current = true;
                    return;
                }
                logArticleEditorVersionDebug('autosave_ack', {
                    noop: Boolean(result?.noop),
                    reconciled: Boolean(result?.reconciled),
                    document_version: result?.document_version ?? null,
                    content_hash: String(result?.content_hash || '').slice(0, 12) || null,
                });
                applyEditorDocumentAck(result);
                const htmlAfterAck = getExportHtml();
                const savedHash = String(result?.content_hash || currentBodyHash);
                const currentMatchesSaved = shouldClearLocalRecoveryAfterSave({
                    currentHtml: htmlAfterAck,
                    savedHtml: htmlAtSend,
                    savedContentHash: savedHash,
                });
                if (!currentMatchesSaved) {
                    serverAutosaveDirtyRef.current = true;
                    persistLocalRecoverySnapshot({ html: htmlAfterAck, force: true });
                    setSaveStatus('pending');
                    return;
                }
                if (result?.content_hash) {
                    lastAutosaveHashRef.current = String(result.content_hash);
                } else if (currentBodyHash) {
                    lastAutosaveHashRef.current = currentBodyHash;
                }
                writeSyncedLocalSnapshot(articleId, connectionHashRef.current, withDraftSite({
                    content: htmlAfterAck || htmlAtSend,
                    base_updated_at: result?.saved_at ?? getEditorConflictTokens().expected_updated_at,
                    base_content_hash: savedHash,
                    version: savedHash,
                    autosave_error: null,
                }));
                serverAutosaveNeedsRetryRef.current = false;
                setSaveStatus('saved');
                markRecoveringClear();
            } catch (error) {
                serverAutosaveDirtyRef.current = true;
                serverAutosaveNeedsRetryRef.current = true;
                persistLocalRecoverySnapshot({
                    html: getExportHtml(),
                    force: true,
                    autosaveError: classifyArticleSaveError(error),
                });
                const kind = classifyArticleSaveError(error);
                logArticleEditorSaveGuard({
                    article_id: articleId,
                    guard_reason: kind === SAVE_FAILURE.REVISION_CONFLICT
                        ? GUARD_REASON.REVISION_MISMATCH
                        : (kind === SAVE_FAILURE.PROTECTED_BLOCK
                            ? GUARD_REASON.INLINE_WHITESPACE
                            : GUARD_REASON.INVALID_PAYLOAD),
                    save_mode: 'autosave',
                    request_sequence: seq,
                    autosave: true,
                    error_code: String(error?.code ?? error?.sessionError?.code ?? ''),
                });
                if (kind === SAVE_FAILURE.NETWORK) {
                    networkUnavailableRef.current = true;
                    setSaveStatus('pending');
                } else if (kind === SAVE_FAILURE.REVISION_CONFLICT) {
                    setSaveStatus('conflict');
                    markRecoveringClear();
                    window.dispatchEvent(new CustomEvent('seo-article-save-conflict', {
                        detail: { conflict: error?.data?.conflict ?? null, message: error?.message ?? '' },
                    }));
                } else if (kind === SAVE_FAILURE.PROTECTED_BLOCK) {
                    setSaveStatus('blocked');
                    markRecoveringClear();
                } else {
                    setSaveStatus('failed');
                    markRecoveringClear();
                }
            } finally {
                serverAutosaveInFlightRef.current = false;
                if (serverAutosaveDirtyRef.current && !networkUnavailableRef.current) {
                    scheduleServerAutosave();
                }
            }
        }, delayMs);
    }, [articleId, assertWritableDocumentNotWhitespaceCorrupted, draftSaveDisabled, getExportHtml, markRecoveringClear, persistLocalRecoverySnapshot, serverAutosaveDebounceMs, sessionReadOnly, setSaveStatus, withDraftSite]);

    scheduleServerAutosaveRef.current = scheduleServerAutosave;

    const { debounced: debouncedLocalSave, cancel: cancelLocalDraftSave } = useDebouncedCallback(() => {
        if (!articleId) return;
        if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) return;
        if (!isDraftPersistenceEnabled() || window.__SEO_EDITOR_EXITING__) return;
        if (isArticleAutosaveLocked()) return;
        const html = getExportHtml();
        persistLocalRecoverySnapshot({ html });
        setSaveStatus((prev) => (prev === 'blocked' || prev === 'conflict' ? prev : 'pending'));
        if (!assertWritableDocumentNotWhitespaceCorrupted(html)) {
            return;
        }
        scheduleServerAutosave();
    }, localRecoveryDelayMs);

    // scheduleAutosave chỉ lo lưu nháp local — KHÔNG còn gọi SEO analyze (đó là nguồn lag khi gõ).
    // Analyze giờ chỉ chạy khi requestAnalyze() được gọi rõ ràng (nút Phân tích / sau hành động cụ thể).
    const scheduleAutosave = useCallback(() => {
        if (window.__SEO_EDITOR_EXITING__) {
            return;
        }
        if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__) {
            return;
        }
        if (!isDraftPersistenceEnabled()) {
            return;
        }
        noteLocalRevisionChanged();
        if (networkUnavailableRef.current) {
            persistLocalRecoverySnapshot({ force: true });
            setSaveStatus('pending');
            serverAutosaveDirtyRef.current = true;
            // Keep dirty in runtime; skip local→server chain spam while offline.
            return;
        }
        setSaveStatus((prev) => (prev === 'blocked' || prev === 'conflict' ? prev : 'pending'));
        debouncedLocalSave();
    }, [debouncedLocalSave, noteLocalRevisionChanged, persistLocalRecoverySnapshot, sessionReadOnly, setSaveStatus]);

    scheduleAutosaveRef.current = scheduleAutosave;

    useEffect(() => {
        window.__SEO_EDITOR_EXITING__ = false;
        setDraftPersistenceEnabled(true);
        window.__seoCancelArticleDraftAutosave = cancelLocalDraftSave;
        window.__seoFlushArticleRecoveryDraft = () => persistLocalRecoverySnapshot({ force: true });
        window.__seoDisableArticleDraftPersistence = () => {
            setDraftPersistenceEnabled(false);
            cancelLocalDraftSave();
        };

        const onBeforeUnload = () => {
            persistLocalRecoverySnapshot({ force: true });
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            if (window.__seoCancelArticleDraftAutosave === cancelLocalDraftSave) {
                delete window.__seoCancelArticleDraftAutosave;
            }
            delete window.__seoFlushArticleRecoveryDraft;
            delete window.__seoDisableArticleDraftPersistence;
        };
    }, [cancelLocalDraftSave, persistLocalRecoverySnapshot]);

    useEffect(() => {
        const onManualSaveStarted = () => setSaveStatus('saving');
        const onManualSaveFinished = (event) => {
            if (event?.detail?.conflict) {
                setSaveStatus('conflict');
                return;
            }
            if (event?.detail?.failed) {
                setSaveStatus('failed');
                return;
            }
            if (event?.detail?.success) {
                setSaveStatus('saved');
            }
        };
        window.addEventListener('article-editor-save-started', onManualSaveStarted);
        window.addEventListener('article-editor-save-finished', onManualSaveFinished);

        return () => {
            window.removeEventListener('article-editor-save-started', onManualSaveStarted);
            window.removeEventListener('article-editor-save-finished', onManualSaveFinished);
        };
    }, [setSaveStatus]);

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
