/**
 * Phase 4 — standardized editor command result.
 */

/**
 * @typedef {{
 *   ok: boolean,
 *   code: string,
 *   command: string,
 *   editor_id: string|null,
 *   transaction_applied: boolean,
 *   document_changed: boolean,
 *   selection_changed: boolean,
 *   new_selection: { from: number, to: number }|null,
 *   history_step: boolean,
 *   error: { code: string, message_key: string, message?: string }|null,
 *   meta?: Record<string, unknown>,
 * }} EditorCommandResult
 */

export const EDITOR_COMMAND_CODES = Object.freeze({
    INSERTED: 'inserted',
    UPDATED: 'updated',
    REMOVED: 'removed',
    MOVED: 'moved',
    NO_CHANGE: 'no_change',
    NAVIGATED: 'navigated',
    REPLACED: 'replaced',
    UNKNOWN: 'editor_command_unknown',
    NOT_READY: 'editor_not_ready',
    TARGET_AMBIGUOUS: 'editor_target_ambiguous',
    TARGET_MISSING: 'editor_target_missing',
    READ_ONLY: 'editor_read_only',
    SESSION_NOT_OWNED: 'editor_session_not_owned',
    INSERTION_CONTEXT_MISSING: 'insertion_context_missing',
    INSERTION_CONTEXT_STALE: 'insertion_context_stale',
    SELECTION_INVALID: 'selection_invalid',
    TRANSACTION_FAILED: 'transaction_failed',
    MEDIA_INSERT_FAILED: 'media_insert_failed',
    FAQ_INSERT_FAILED: 'faq_insert_failed',
    CONTENT_REPLACE_CONFLICT: 'content_replace_conflict',
    PERMISSION_DENIED: 'permission_denied',
    BLOCK_ALREADY_FIRST: 'block_already_first',
    BLOCK_ALREADY_LAST: 'block_already_last',
    SECTION_MISMATCH: 'section_mismatch',
    HOST_COMMAND_MISSING: 'host_command_missing',
    UNSUPPORTED_SELECTION: 'unsupported_selection',
});

/**
 * @param {Partial<EditorCommandResult> & { command: string }} partial
 * @returns {EditorCommandResult}
 */
export function createCommandResult(partial) {
    return {
        ok: Boolean(partial.ok),
        code: String(partial.code ?? (partial.ok ? EDITOR_COMMAND_CODES.NO_CHANGE : EDITOR_COMMAND_CODES.TRANSACTION_FAILED)),
        command: String(partial.command ?? ''),
        editor_id: partial.editor_id ?? null,
        transaction_applied: Boolean(partial.transaction_applied),
        document_changed: Boolean(partial.document_changed),
        selection_changed: Boolean(partial.selection_changed),
        new_selection: partial.new_selection ?? null,
        history_step: Boolean(partial.history_step),
        error: partial.error ?? null,
        meta: partial.meta ?? {},
    };
}

/**
 * @param {string} command
 * @param {string} code
 * @param {{ message_key?: string, message?: string, editor_id?: string|null, meta?: object }} [opts]
 * @returns {EditorCommandResult}
 */
export function failCommand(command, code, opts = {}) {
    return createCommandResult({
        ok: false,
        code,
        command,
        editor_id: opts.editor_id ?? null,
        transaction_applied: false,
        document_changed: false,
        selection_changed: false,
        new_selection: null,
        history_step: false,
        error: {
            code,
            message_key: opts.message_key ?? `editor_command.${code}`,
            message: opts.message,
        },
        meta: opts.meta ?? {},
    });
}

/**
 * @param {string} command
 * @param {string} code
 * @param {{ editor_id?: string|null, transaction_applied?: boolean, document_changed?: boolean, selection_changed?: boolean, new_selection?: object|null, history_step?: boolean, meta?: object }} [opts]
 * @returns {EditorCommandResult}
 */
export function okCommand(command, code, opts = {}) {
    return createCommandResult({
        ok: true,
        code,
        command,
        editor_id: opts.editor_id ?? null,
        transaction_applied: opts.transaction_applied ?? true,
        document_changed: opts.document_changed ?? true,
        selection_changed: opts.selection_changed ?? false,
        new_selection: opts.new_selection ?? null,
        history_step: opts.history_step ?? true,
        error: null,
        meta: opts.meta ?? {},
    });
}

export default {
    EDITOR_COMMAND_CODES,
    createCommandResult,
    failCommand,
    okCommand,
};
