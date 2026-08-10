import { useMemo } from 'react';
import { executeEditorCommand, getEditorCommandHost } from '../../../utils/editorCommands';

/**
 * Phase 6C.4 — document read/command surface (no raw TipTap).
 */
export function useEditorDocument() {
    return useMemo(() => ({
        getExportHtml: () => getEditorCommandHost()?.actions?.getExportHtml?.() ?? '',
        getSelectionHtml: () => getEditorCommandHost()?.actions?.getSelectionHtml?.() ?? '',
        execute: (name, payload, options) => executeEditorCommand(name, payload, options),
        replaceArticleDocument: (payload, options) => (
            executeEditorCommand('replace_article_document', payload, options)
        ),
        insertImage: (payload, options) => executeEditorCommand('insert_image', payload, options),
    }), []);
}
