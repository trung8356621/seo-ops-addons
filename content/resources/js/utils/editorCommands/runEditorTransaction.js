/**
 * Phase 4 — single transaction wrapper + document-changed signal.
 */

import { EDITOR_COMMAND_CODES, failCommand, okCommand } from './editorCommandResult';

export const DOCUMENT_CHANGED_EVENT = 'article-editor-document-changed';

/**
 * @param {{
 *   editor: import('@tiptap/core').Editor,
 *   command: string,
 *   editorId?: string|null,
 *   context?: object,
 *   historyPolicy?: 'add'|'skip',
 *   selectionPolicy?: 'preserve'|'focus'|'none',
 *   build: (editor: import('@tiptap/core').Editor) => boolean,
 *   successCode?: string,
 * }} opts
 */
export function runEditorTransaction(opts) {
    const {
        editor,
        command,
        editorId = null,
        context = null,
        historyPolicy = 'add',
        selectionPolicy = 'preserve',
        build,
        successCode = EDITOR_COMMAND_CODES.UPDATED,
    } = opts;

    if (!editor?.state || editor.isDestroyed) {
        return failCommand(command, EDITOR_COMMAND_CODES.NOT_READY, { editor_id: editorId });
    }

    const beforeDoc = editor.state.doc;
    const beforeSel = editor.state.selection;
    let applied = false;

    try {
        if (historyPolicy === 'skip' && typeof editor.view?.dispatch === 'function') {
            // Caller build() may setMeta addToHistory false itself.
        }
        applied = Boolean(build(editor));
    } catch {
        return failCommand(command, EDITOR_COMMAND_CODES.TRANSACTION_FAILED, { editor_id: editorId });
    }

    if (!applied) {
        return okCommand(command, EDITOR_COMMAND_CODES.NO_CHANGE, {
            editor_id: editorId,
            transaction_applied: false,
            document_changed: false,
            selection_changed: false,
            history_step: false,
        });
    }

    const afterDoc = editor.state.doc;
    const afterSel = editor.state.selection;
    const documentChanged = afterDoc !== beforeDoc;
    const selectionChanged = afterSel.from !== beforeSel.from || afterSel.to !== beforeSel.to;

    if (selectionPolicy === 'focus' && typeof editor.commands?.focus === 'function') {
        // focus without remount — TipTap command may no-op if already focused
        try {
            editor.commands.focus();
        } catch {
            // ignore
        }
    }

    if (documentChanged && context) {
        emitDocumentChanged(context, {
            editor_id: editorId,
            command,
        });
    }

    return okCommand(command, documentChanged ? successCode : EDITOR_COMMAND_CODES.NO_CHANGE, {
        editor_id: editorId,
        transaction_applied: true,
        document_changed: documentChanged,
        selection_changed: selectionChanged,
        new_selection: { from: afterSel.from, to: afterSel.to },
        history_step: historyPolicy === 'add' && documentChanged,
    });
}

/**
 * One signal per successful doc mutation.
 * @param {object} context
 * @param {{ editor_id?: string|null, command: string }} detail
 */
export function emitDocumentChanged(context, detail) {
    const payload = {
        article_id: context.articleId ?? null,
        editor_id: detail.editor_id ?? null,
        command: detail.command,
        document_version: context.documentVersion ?? null,
        local_revision: (Number(context.localRevision) || 0) + 1,
        changed_at: Date.now(),
    };

    if (typeof context.dispatchDocumentChanged === 'function') {
        context.dispatchDocumentChanged(payload);
    }

    if (typeof window !== 'undefined' && typeof window.dispatchEvent === 'function') {
        window.dispatchEvent(new CustomEvent(DOCUMENT_CHANGED_EVENT, { detail: payload }));
    }

    // Single fan-out: dirty / draft / analysis / autosave — host callbacks once.
    if (typeof context.commitActiveBlock === 'function') {
        context.commitActiveBlock();
    }
    if (typeof context.requestAnalyze === 'function') {
        context.requestAnalyze();
    }
    if (typeof context.scheduleAutosave === 'function') {
        context.scheduleAutosave();
    }
}

export default {
    DOCUMENT_CHANGED_EVENT,
    runEditorTransaction,
    emitDocumentChanged,
};
