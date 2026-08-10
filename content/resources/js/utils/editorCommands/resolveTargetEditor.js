/**
 * Phase 4 — single editor target resolver.
 * Never pick first Map entry / iterator head when multiple editors exist.
 */

import { EDITOR_COMMAND_CODES, failCommand } from './editorCommandResult';

/**
 * @param {object} context
 * @param {{ editorId?: string|null, editor?: import('@tiptap/core').Editor|null, allowAmbiguous?: boolean }} payload
 * @param {string} commandName
 * @returns {{ editor: import('@tiptap/core').Editor|null, editorId: string|null, error: object|null }}
 */
export function resolveTargetEditor(context, payload = {}, commandName = '') {
    // 1) Explicit editor instance (toolbar already bound to one TipTap).
    if (payload.editor && !payload.editor.isDestroyed) {
        const editorId = String(payload.editorId ?? context.activeEditorId ?? '').trim() || null;
        return { editor: payload.editor, editorId, error: null };
    }

    const registry = context.editorRegistry;
    const map = registry instanceof Map ? registry : null;

    // 2) Explicit editorId
    const explicitId = String(payload.editorId ?? '').trim();
    if (explicitId) {
        const editor = map?.get?.(explicitId) ?? null;
        if (editor && !editor.isDestroyed) {
            return { editor, editorId: explicitId, error: null };
        }
        return {
            editor: null,
            editorId: explicitId,
            error: failCommand(commandName, EDITOR_COMMAND_CODES.TARGET_MISSING, {
                editor_id: explicitId,
                message_key: 'editor_command.editor_target_missing',
            }),
        };
    }

    // 3) Insertion context active block
    const ctxId = String(context.insertionContext?.activeBlockId ?? '').trim();
    if (ctxId) {
        const editor = map?.get?.(ctxId) ?? null;
        if (editor && !editor.isDestroyed) {
            return { editor, editorId: ctxId, error: null };
        }
    }

    // 4) Active section/block mapping (host activeEditorId)
    const activeId = String(context.activeEditorId ?? '').trim();
    if (activeId && activeId !== ctxId) {
        const editor = map?.get?.(activeId) ?? null;
        if (editor && !editor.isDestroyed) {
            return { editor, editorId: activeId, error: null };
        }
    }

    // 5) Single-editor fallback only
    if (map && map.size === 1) {
        const [onlyId, onlyEditor] = [...map.entries()][0];
        if (onlyEditor && !onlyEditor.isDestroyed) {
            return { editor: onlyEditor, editorId: String(onlyId), error: null };
        }
    }

    if (map && map.size > 1) {
        return {
            editor: null,
            editorId: null,
            error: failCommand(commandName, EDITOR_COMMAND_CODES.TARGET_AMBIGUOUS, {
                message_key: 'editor_command.editor_target_ambiguous',
                meta: { editor_count: map.size },
            }),
        };
    }

    // Optional globalEditor only when map empty / single path already failed
    if (context.globalEditor && !context.globalEditor.isDestroyed && (!map || map.size === 0)) {
        return { editor: context.globalEditor, editorId: activeId || null, error: null };
    }

    return {
        editor: null,
        editorId: null,
        error: failCommand(commandName, EDITOR_COMMAND_CODES.NOT_READY, {
            message_key: 'editor_command.editor_not_ready',
        }),
    };
}

export default { resolveTargetEditor };
