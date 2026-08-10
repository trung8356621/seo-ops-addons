/**
 * Phase 4 — command host context (injected; no window/DOM/Livewire reads inside commands).
 */

import { canMutateEditor, getArticleEditorSessionState } from '../editorSessionState';
import { getInsertionContextForCommand } from '../editorInsertionContext';
import { EDITOR_COMMAND_CODES, failCommand } from './editorCommandResult';

/** @type {null | {
 *   articleId: number|string|null,
 *   getEditorRegistry: () => Map<string, import('@tiptap/core').Editor>|null,
 *   getActiveEditorId: () => string|null,
 *   getGlobalEditor: () => import('@tiptap/core').Editor|null,
 *   getDocumentModel: () => object|null,
 *   getMediaSnapshot: () => object|null,
 *   getAnalysisPolicy: () => object|null,
 *   getDocumentVersion: () => number|string|null,
 *   getLocalRevision: () => number,
 *   isArchived: () => boolean,
 *   hasConflict: () => boolean,
 *   dispatchDocumentChanged: (payload: object) => void,
 *   notify: (detail: object) => void,
 *   scheduleAutosave: () => void,
 *   requestAnalyze: () => void,
 *   commitActiveBlock: () => void,
 *   onStructureMutation?: (name: string, payload: object) => boolean|object,
 * }} */
let host = null;

/**
 * Bind once from SeoArticleEditor (or test harness).
 * @param {typeof host} next
 */
export function bindEditorCommandHost(next) {
    host = next && typeof next === 'object' ? next : null;
}

export function unbindEditorCommandHost() {
    host = null;
}

export function getEditorCommandHost() {
    return host;
}

/**
 * Build immutable context snapshot for one command execution.
 * @param {object} [overrides]
 */
export function buildEditorCommandContext(overrides = {}) {
    const session = getArticleEditorSessionState();
    const insertionContext = getInsertionContextForCommand();
    const registry = host?.getEditorRegistry?.() ?? null;

    return {
        articleId: host?.articleId ?? null,
        editorSessionId: session?.sessionId ?? null,
        writable: Boolean(canMutateEditor()),
        sessionStatus: session?.status ?? null,
        documentVersion: host?.getDocumentVersion?.() ?? null,
        localRevision: host?.getLocalRevision?.() ?? 0,
        activeEditorId: String(host?.getActiveEditorId?.() ?? insertionContext?.activeBlockId ?? '').trim() || null,
        editorRegistry: registry,
        globalEditor: host?.getGlobalEditor?.() ?? null,
        insertionContext,
        documentModel: host?.getDocumentModel?.() ?? null,
        mediaSnapshot: host?.getMediaSnapshot?.() ?? null,
        analysisPolicy: host?.getAnalysisPolicy?.() ?? null,
        isArchived: Boolean(host?.isArchived?.()),
        hasConflict: Boolean(host?.hasConflict?.()),
        dispatchDocumentChanged: host?.dispatchDocumentChanged ?? (() => {}),
        notify: host?.notify ?? (() => {}),
        scheduleAutosave: host?.scheduleAutosave ?? (() => {}),
        requestAnalyze: host?.requestAnalyze ?? (() => {}),
        commitActiveBlock: host?.commitActiveBlock ?? (() => {}),
        onStructureMutation: host?.onStructureMutation ?? null,
        ...overrides,
    };
}

/**
 * Application-level writable guard (single place). TipTap editable=false is UI-only.
 *
 * @param {ReturnType<typeof buildEditorCommandContext>} context
 * @param {{ name: string, requiresWritable?: boolean, allowedInReadOnly?: boolean }} meta
 * @returns {import('./editorCommandResult').EditorCommandResult|null} fail result or null if ok
 */
export function assertWritableCommandContext(context, meta) {
    if (meta.allowedInReadOnly || meta.requiresWritable === false) {
        return null;
    }

    if (context.isArchived) {
        return failCommand(meta.name, EDITOR_COMMAND_CODES.PERMISSION_DENIED, {
            message_key: 'editor_command.article_archived',
        });
    }

    if (context.hasConflict) {
        return failCommand(meta.name, EDITOR_COMMAND_CODES.CONTENT_REPLACE_CONFLICT, {
            message_key: 'editor_command.content_replace_conflict',
        });
    }

    if (!context.writable) {
        const status = String(context.sessionStatus ?? '');
        const code = status === 'conflict'
            ? EDITOR_COMMAND_CODES.CONTENT_REPLACE_CONFLICT
            : (status === 'locked' || status === 'taken_over' || status === 'revoked' || status === 'expired')
                ? EDITOR_COMMAND_CODES.SESSION_NOT_OWNED
                : EDITOR_COMMAND_CODES.READ_ONLY;

        return failCommand(meta.name, code, {
            message_key: `editor_command.${code}`,
        });
    }

    return null;
}

export default {
    bindEditorCommandHost,
    unbindEditorCommandHost,
    getEditorCommandHost,
    buildEditorCommandContext,
    assertWritableCommandContext,
};
