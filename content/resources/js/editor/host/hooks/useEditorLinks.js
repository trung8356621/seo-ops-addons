import { useMemo } from 'react';
import { getEditorCommandHost } from '../../../utils/editorCommands';
import { executeEditorCommand } from '../../../utils/editorCommands';

/**
 * Phase 6C.2 — Links module actions (host-bound document ops + commands).
 */
export function useEditorLinks() {
    return useMemo(() => ({
        insertSuggested: (detail) => {
            const actions = getEditorCommandHost()?.actions;
            if (typeof actions?.insertSuggestedLink === 'function') {
                return actions.insertSuggestedLink(detail);
            }
            return executeEditorCommand('insert_link', {
                label: detail?.text,
                text: detail?.text,
                href: detail?.href,
                bookmark: detail?.target?.selectionBookmark,
                editorId: detail?.target?.blockId,
            }, { notifyOnFailure: true });
        },
        removeInternal: (detail) => {
            const actions = getEditorCommandHost()?.actions;
            if (typeof actions?.removeInternalLink === 'function') {
                return actions.removeInternalLink(detail);
            }
            return null;
        },
        unlinkKeepText: (payload = {}, options = {}) =>
            executeEditorCommand('remove_link_keep_text', payload, options),
        createOrUpdate: (payload = {}, options = {}) =>
            executeEditorCommand(
                payload?.href && payload?.update ? 'update_link' : 'create_link',
                payload,
                options,
            ),
        scrollTo: (detail) => {
            const actions = getEditorCommandHost()?.actions;
            if (typeof actions?.scrollToLink === 'function') {
                return actions.scrollToLink(detail);
            }
            return null;
        },
    }), []);
}
