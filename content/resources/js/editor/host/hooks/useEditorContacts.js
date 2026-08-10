import { useMemo } from 'react';
import { getEditorCommandHost } from '../../../utils/editorCommands';
import { executeEditorCommand } from '../../../utils/editorCommands';
import {
    clearFrozenEditorInsertionContext,
    getInsertionContextForCommand,
} from '../../../utils/editorInsertionContext';

/**
 * Phase 6C.2 — CTA/contact insert via command layer + frozen bookmark.
 */
export function useEditorContacts() {
    return useMemo(() => ({
        insertValue: (detail) => {
            const actions = getEditorCommandHost()?.actions;
            if (typeof actions?.insertCtaLink === 'function') {
                return actions.insertCtaLink({ ...detail, is_contact_value: true, is_sentence: false });
            }
            const ctx = getInsertionContextForCommand();
            return executeEditorCommand('insert_contact_value', {
                ...detail,
                editorId: detail?.target?.blockId || ctx.activeBlockId,
                bookmark: detail?.target?.selectionBookmark ?? ctx.selection,
            }, { notifyOnFailure: true });
        },
        insertSentence: (detail) => {
            const actions = getEditorCommandHost()?.actions;
            if (typeof actions?.insertCtaLink === 'function') {
                return actions.insertCtaLink({
                    ...detail,
                    is_sentence: true,
                    is_cta_sentence: true,
                    is_cta_block: true,
                });
            }
            const ctx = getInsertionContextForCommand();
            const result = executeEditorCommand('insert_contact_cta', {
                ...detail,
                editorId: detail?.target?.blockId || ctx.activeBlockId,
                bookmark: detail?.target?.selectionBookmark ?? ctx.selection,
            }, { notifyOnFailure: true });
            if (result?.ok && result.transaction_applied) {
                clearFrozenEditorInsertionContext();
            }
            return result;
        },
    }), []);
}
