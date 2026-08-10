import { useMemo } from 'react';
import { canMutateEditor, getArticleEditorSessionState } from '../../../utils/editorSessionState';
import { getEditorCommandHost } from '../../../utils/editorCommands';

/**
 * Phase 6C.2 — session/archive read model for mutation UI.
 */
export function useEditorSession() {
    return useMemo(() => ({
        getState: () => getArticleEditorSessionState(),
        canMutate: () => canMutateEditor(),
        isArchived: () => Boolean(getEditorCommandHost()?.isArchived?.()),
        hasConflict: () => Boolean(getEditorCommandHost()?.hasConflict?.()),
        articleId: () => getEditorCommandHost()?.articleId ?? null,
    }), []);
}
