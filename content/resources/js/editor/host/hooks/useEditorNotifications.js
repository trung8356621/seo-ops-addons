import { useMemo } from 'react';
import { getEditorCommandHost } from '../../../utils/editorCommands';

function emitNotify(detail) {
    const host = getEditorCommandHost();
    if (typeof host?.notify === 'function') {
        host.notify(detail);
        return;
    }
    // Shell toast adapter only when host unbound / shell boundary.
    if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('seo-article-editor-notify', { detail }));
    }
}

/**
 * Phase 6C.4 — notification service (modules must not dispatch raw toast events).
 */
export function useEditorNotifications() {
    return useMemo(() => {
        const notify = (detail) => emitNotify(detail && typeof detail === 'object' ? detail : { body: String(detail ?? '') });
        return {
            notify,
            success: (title, body = '') => notify({ title, body, status: 'success' }),
            error: (title, body = '') => notify({ title, body, status: 'danger' }),
            warning: (title, body = '') => notify({ title, body, status: 'warning' }),
            info: (title, body = '') => notify({ title, body, status: 'info' }),
        };
    }, []);
}
