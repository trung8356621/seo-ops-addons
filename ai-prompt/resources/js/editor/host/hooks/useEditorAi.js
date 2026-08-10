import { useCallback, useMemo } from 'react';
import { getEditorCommandHost } from '@content-addon/utils/editorCommands/index.js';
import { canMutateEditor } from '@content-addon/utils/editorSessionState.js';
import { closePanel, openPanel } from '@content-addon/editor/runtime/editorRuntimeNavigation.js';
import { getEditorInsertionContext } from '@content-addon/utils/editorInsertionContext.js';

/**
 * Phase 6C.4 — editor-owned AI chat (image/video generate + selection context).
 * Generation/audit/prompts remain Laravel; Apply/insert goes through host actions / commands.
 */
export function useEditorAi() {
    const host = getEditorCommandHost();

    const canApply = useCallback(() => {
        if (!canMutateEditor()) return false;
        if (host?.isArchived?.()) return false;
        return true;
    }, [host]);

    const collectContext = useCallback(() => {
        const insertion = typeof getEditorInsertionContext === 'function'
            ? getEditorInsertionContext()
            : null;
        return {
            article_id: Number(host?.articleId ?? 0) || 0,
            editor_session_id: host?.sessionId ?? null,
            active_editor_id: insertion?.editorId ?? null,
            block_id: insertion?.blockId ?? host?.actions?.getActiveBlockId?.() ?? null,
            selection: {
                text: insertion?.text ?? '',
                html: insertion?.html ?? '',
            },
            requested_action: null,
        };
    }, [host]);

    return useMemo(() => ({
        open: (detail = {}) => openPanel('ai-chat', { source: 'use_editor_ai', detail }),
        close: () => {
            closePanel({ source: 'use_editor_ai' });
        },
        canApply,
        collectContext,
        generateImage: (detail) => {
            if (!canApply()) {
                return Promise.reject(new Error('ai_read_only'));
            }
            return host?.actions?.generateArticleImage?.(detail);
        },
        generateVideo: (detail) => {
            if (!canApply()) {
                return Promise.reject(new Error('ai_read_only'));
            }
            return host?.actions?.generateArticleVideo?.(detail);
        },
        applyPrefill: (detail) => host?.actions?.applyAiChatPrefill?.(detail),
    }), [host, canApply, collectContext]);
}
