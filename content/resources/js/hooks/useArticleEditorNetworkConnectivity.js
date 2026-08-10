import { useCallback, useEffect, useRef, useState } from 'react';
import { seoArticleApiFetch } from '@seo-addon/utils/seoArticleApi.js';
import {
    createArticleEditorNetworkMonitor,
    verifyArticleEditorBackendReachable,
} from '../utils/articleEditorNetwork';

/**
 * Single network monitor for Article Editor shell.
 * Listeners live here — child components must not add online/offline listeners.
 *
 * @param {{
 *   articleId: number,
 *   getDirtyRevisionKey: () => string | null,
 *   onRecoveringAutosave: (revisionKey: string) => void,
 * }} options
 */
export function useArticleEditorNetworkConnectivity({
    articleId,
    getDirtyRevisionKey,
    onRecoveringAutosave,
}) {
    const [networkStatus, setNetworkStatus] = useState(
        () => window.__SEO_EDITOR_NETWORK_STATUS__?.status ?? 'available',
    );
    const monitorRef = useRef(null);
    const getDirtyRevisionKeyRef = useRef(getDirtyRevisionKey);
    const onRecoveringAutosaveRef = useRef(onRecoveringAutosave);

    useEffect(() => {
        getDirtyRevisionKeyRef.current = getDirtyRevisionKey;
    }, [getDirtyRevisionKey]);

    useEffect(() => {
        onRecoveringAutosaveRef.current = onRecoveringAutosave;
    }, [onRecoveringAutosave]);

    useEffect(() => {
        const id = Number(articleId) || 0;
        if (id <= 0) {
            return undefined;
        }

        const monitor = createArticleEditorNetworkMonitor({
            articleId: id,
            verifyReachability: () => verifyArticleEditorBackendReachable(id, seoArticleApiFetch),
            getDirtyRevisionKey: () => getDirtyRevisionKeyRef.current?.() ?? null,
            onRecoveringAutosave: (revisionKey) => {
                onRecoveringAutosaveRef.current?.(revisionKey);
            },
            onStatusChange: (detail) => {
                setNetworkStatus(String(detail?.status || 'available'));
            },
        });

        monitorRef.current = monitor;
        window.__seoEditorNetworkMonitor = monitor;

        return () => {
            monitor.destroy();
            if (window.__seoEditorNetworkMonitor === monitor) {
                delete window.__seoEditorNetworkMonitor;
            }
            monitorRef.current = null;
        };
    }, [articleId]);

    const noteLocalRevisionChanged = useCallback(() => {
        monitorRef.current?.noteLocalRevisionChanged?.();
    }, []);

    const markRecoveringClear = useCallback(() => {
        monitorRef.current?.markRecoveringClear?.();
    }, []);

    const reportAutosaveNetworkFailure = useCallback((error) => {
        monitorRef.current?.markUnavailable?.('autosave_network_failure');
        // Event already emitted from seoArticleApiFetch for classification; keep status sticky.
        void error;
    }, []);

    return {
        networkStatus,
        networkUnavailable: networkStatus === 'unavailable',
        networkRecovering: networkStatus === 'recovering',
        noteLocalRevisionChanged,
        markRecoveringClear,
        reportAutosaveNetworkFailure,
    };
}
