import { useMemo } from 'react';
import { getEditorCommandHost } from '../../../utils/editorCommands';
import { canMutateEditor } from '../../../utils/editorSessionState';

/**
 * Phase 6C.4 — mutation permission snapshot.
 */
export function useEditorPermissions() {
    return useMemo(() => ({
        canMutate: () => {
            if (!canMutateEditor()) return false;
            if (getEditorCommandHost()?.isArchived?.()) return false;
            return true;
        },
        isArchived: () => Boolean(getEditorCommandHost()?.isArchived?.()),
        isReadOnly: () => !canMutateEditor() || Boolean(getEditorCommandHost()?.isArchived?.()),
    }), []);
}
