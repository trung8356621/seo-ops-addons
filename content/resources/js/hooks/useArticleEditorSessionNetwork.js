import { cancelPendingServerAutosave } from '../utils/articleEditorSaveQueue';
import { getEditorConflictTokens } from '../utils/articleEditorApi';
import { hashContent } from '../utils/articleEditorStorage';
import { plainTextFromHtmlLoose } from '../utils/inlineWhitespaceGuard';
import { useArticleEditorNetworkConnectivity } from '../hooks/useArticleEditorNetworkConnectivity';
import { useCallback, useEffect, useRef } from 'react';

/**
 * useArticleEditorSessionNetwork - extracted from SeoArticleEditor.jsx (Task 7 mechanical
 * extraction). Mechanical move - no behavior change.
 */
export default function useArticleEditorSessionNetwork({ articleId, getExportHtml, initialHtml, saveStatus, sessionReadOnly, setSaveStatus }) {
    const serverAutosaveInFlightRef = useRef(false);
    const serverAutosaveDirtyRef = useRef(false);
    const serverAutosaveSeqRef = useRef(0);
    const serverAutosaveNeedsRetryRef = useRef(false);
    const lastAutosaveHashRef = useRef('');
    const scheduleServerAutosaveRef = useRef(() => {});
    const networkUnavailableRef = useRef(false);
    const saveStatusRef = useRef(saveStatus);
    saveStatusRef.current = saveStatus;
    /** Canonical body plain text from bootstrap — used to block hydrate-origin space corruption saves. */
    const bootstrapBodyPlainRef = useRef(plainTextFromHtmlLoose(initialHtml));
    const whitespaceCorruptionLockedRef = useRef(false);

    const getNetworkDirtyRevisionKey = useCallback(() => {
        const html = getExportHtml();
        const currentBodyHash = hashContent(html);
        const tokens = getEditorConflictTokens();
        const ackBodyHash = String(tokens.expected_content_hash || '').trim();
        const status = saveStatusRef.current;
        const dirtyUi = status === 'pending' || status === 'saving' || status === 'blocked' || status === 'conflict' || status === 'failed';
        const dirtyFlags = serverAutosaveDirtyRef.current || serverAutosaveNeedsRetryRef.current;
        const contentDirty = currentBodyHash !== '' && ackBodyHash !== '' && currentBodyHash !== ackBodyHash;
        if (!dirtyUi && !dirtyFlags && !contentDirty) {
            return null;
        }
        return currentBodyHash || `dirty:${serverAutosaveSeqRef.current}`;
    }, [getExportHtml]);

    const onNetworkRecoveringAutosave = useCallback((revisionKey) => {
        if (sessionReadOnly || window.__SEO_EDITOR_READ_ONLY__ || window.__SEO_EDITOR_EXITING__) {
            return;
        }
        // Recovering phase — allow one flush even before React re-render clears unavailable.
        networkUnavailableRef.current = false;
        serverAutosaveDirtyRef.current = true;
        serverAutosaveNeedsRetryRef.current = true;
        cancelPendingServerAutosave();
        scheduleServerAutosaveRef.current({ immediate: true, reconnectRevision: revisionKey });
    }, [sessionReadOnly]);

    const {
        networkUnavailable,
        networkRecovering,
        noteLocalRevisionChanged,
        markRecoveringClear,
    } = useArticleEditorNetworkConnectivity({
        articleId,
        getDirtyRevisionKey: getNetworkDirtyRevisionKey,
        onRecoveringAutosave: onNetworkRecoveringAutosave,
    });
    networkUnavailableRef.current = networkUnavailable;

    useEffect(() => {
        if (!networkUnavailable) {
            return;
        }
        cancelPendingServerAutosave();
        serverAutosaveDirtyRef.current = true;
        setSaveStatus((prev) => (prev === 'saved' ? 'pending' : prev));
    }, [networkUnavailable]);

    return { bootstrapBodyPlainRef, lastAutosaveHashRef, markRecoveringClear, networkRecovering, networkUnavailable, networkUnavailableRef, noteLocalRevisionChanged, scheduleServerAutosaveRef, serverAutosaveDirtyRef, serverAutosaveInFlightRef, serverAutosaveNeedsRetryRef, serverAutosaveSeqRef, whitespaceCorruptionLockedRef };
}
