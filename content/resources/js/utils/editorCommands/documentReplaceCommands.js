/**
 * Phase 4 — full-document replace (AI apply / revision). One history unit when TipTap setContent emits.
 */

import { EDITOR_COMMAND_CODES, failCommand } from './editorCommandResult';
import { resolveTargetEditor } from './resolveTargetEditor';
import { emitDocumentChanged, runEditorTransaction } from './runEditorTransaction';

/**
 * Replace active TipTap block HTML/JSON. Host may also replace blocks[] via onStructureMutation.
 */
export function replaceArticleDocumentCommand(context, payload = {}) {
    if (payload.expectedDocumentVersion != null
        && context.documentVersion != null
        && String(payload.expectedDocumentVersion) !== String(context.documentVersion)
    ) {
        return failCommand('replace_article_document', EDITOR_COMMAND_CODES.CONTENT_REPLACE_CONFLICT);
    }

    // Prefer host-level block tree replace (multi-editor).
    if (payload.blocks != null && typeof context.onStructureMutation === 'function') {
        const result = context.onStructureMutation('replace_article_document', payload);
        if (result === false) {
            return failCommand('replace_article_document', EDITOR_COMMAND_CODES.TRANSACTION_FAILED);
        }
        emitDocumentChanged(context, { command: 'replace_article_document', editor_id: null });
        return {
            ok: true,
            code: EDITOR_COMMAND_CODES.REPLACED,
            command: 'replace_article_document',
            editor_id: null,
            transaction_applied: true,
            document_changed: true,
            selection_changed: true,
            new_selection: null,
            history_step: Boolean(payload.addToHistory !== false),
            error: null,
            meta: {
                history_note: payload.historyNote
                    ?? 'Large document replace may exceed per-editor TipTap undo; server revision remains recovery SoT.',
                ...(typeof result === 'object' ? result : {}),
            },
        };
    }

    const html = payload.html != null ? String(payload.html) : null;
    const json = payload.document ?? payload.json ?? null;
    if (html == null && json == null) {
        return failCommand('replace_article_document', EDITOR_COMMAND_CODES.NO_CHANGE);
    }

    const resolved = resolveTargetEditor(context, payload, 'replace_article_document');
    if (resolved.error) {
        return resolved.error;
    }

    return runEditorTransaction({
        editor: resolved.editor,
        editorId: resolved.editorId,
        command: 'replace_article_document',
        context,
        historyPolicy: payload.addToHistory === false ? 'skip' : 'add',
        successCode: EDITOR_COMMAND_CODES.REPLACED,
        build: (ed) => {
            if (json && typeof json === 'object') {
                ed.commands.setContent(json, { emitUpdate: true });
                return true;
            }
            ed.commands.setContent(html || '<p></p>', { emitUpdate: true });
            return true;
        },
    });
}

export function applyDocumentFragmentCommand(context, payload = {}) {
    const resolved = resolveTargetEditor(context, payload, 'apply_document_fragment');
    if (resolved.error) {
        return resolved.error;
    }
    const content = payload.content ?? payload.html ?? null;
    if (content == null || content === '') {
        return failCommand('apply_document_fragment', EDITOR_COMMAND_CODES.NO_CHANGE, {
            editor_id: resolved.editorId,
        });
    }
    return runEditorTransaction({
        editor: resolved.editor,
        editorId: resolved.editorId,
        command: 'apply_document_fragment',
        context,
        historyPolicy: 'add',
        successCode: EDITOR_COMMAND_CODES.INSERTED,
        build: (ed) => ed.chain().focus().insertContent(content).run(),
    });
}

export default {
    replaceArticleDocumentCommand,
    applyDocumentFragmentCommand,
};
