/**
 * Phase 4 — executeEditorCommand entry point + host bind exports.
 */

import {
    assertWritableCommandContext,
    bindEditorCommandHost,
    buildEditorCommandContext,
    getEditorCommandHost,
    unbindEditorCommandHost,
} from './editorCommandContext';
import { getEditorCommandMeta, listEditorCommands } from './editorCommandRegistry';
import { EDITOR_COMMAND_CODES, failCommand } from './editorCommandResult';
import { t } from '../i18n';

/**
 * @param {string} commandName
 * @param {object} [payload]
 * @param {{ contextOverrides?: object, notifyOnFailure?: boolean, notifyOnSuccess?: boolean, successNotify?: object }} [options]
 * @returns {import('./editorCommandResult').EditorCommandResult}
 */
export function executeEditorCommand(commandName, payload = {}, options = {}) {
    const name = String(commandName ?? '').trim();
    const meta = getEditorCommandMeta(name);
    if (!meta) {
        return failCommand(name || 'unknown', EDITOR_COMMAND_CODES.UNKNOWN, {
            message_key: 'editor_command.editor_command_unknown',
        });
    }

    const context = buildEditorCommandContext(options.contextOverrides ?? {});
    const guardFail = assertWritableCommandContext(context, meta);
    if (guardFail) {
        if (options.notifyOnFailure !== false) {
            notifyFailure(context, guardFail);
        }
        return guardFail;
    }

    if (meta.requiresInsertionContext) {
        const hasCtx = Boolean(
            payload.editor
            || payload.editorId
            || context.insertionContext?.activeBlockId
            || context.activeEditorId
            || (context.editorRegistry instanceof Map && context.editorRegistry.size === 1),
        );
        if (!hasCtx) {
            const fail = failCommand(name, EDITOR_COMMAND_CODES.INSERTION_CONTEXT_MISSING, {
                message_key: 'editor_command.insertion_context_missing',
            });
            if (options.notifyOnFailure !== false) {
                notifyFailure(context, fail);
            }
            return fail;
        }
    }

    let result;
    try {
        result = meta.execute(context, payload ?? {});
    } catch {
        result = failCommand(name, EDITOR_COMMAND_CODES.TRANSACTION_FAILED, {
            message_key: 'editor_command.transaction_failed',
        });
    }

    if (!result || typeof result !== 'object') {
        result = failCommand(name, EDITOR_COMMAND_CODES.TRANSACTION_FAILED);
    }

    if (!result.ok && options.notifyOnFailure !== false) {
        notifyFailure(context, result);
    }

    if (
        result.ok
        && result.transaction_applied
        && options.notifyOnSuccess === true
        && options.successNotify
    ) {
        context.notify?.(options.successNotify);
    }

    return result;
}

function notifyFailure(context, result) {
    const key = result?.error?.message_key ?? `editor_command.${result?.code}`;
    let body = result?.error?.message;
    try {
        const translated = t(key);
        body = body || (translated !== key ? translated : key);
    } catch {
        body = body || key;
    }
    context.notify?.({
        title: (() => {
            try {
                const title = t('editor_command.failed_title');
                return title !== 'editor_command.failed_title' ? title : 'Editor';
            } catch {
                return 'Editor';
            }
        })(),
        body,
        status: 'warning',
        reason_code: result?.code,
    });
}

export function cmdInsertContactCta(payload, options) {
    return executeEditorCommand('insert_contact_cta', payload, options);
}

export function cmdInsertContactValue(payload, options) {
    return executeEditorCommand('insert_contact_value', payload, options);
}

export function cmdRemoveLinkKeepText(payload, options) {
    return executeEditorCommand('remove_link_keep_text', payload, options);
}

export function cmdToggleBold(payload, options) {
    return executeEditorCommand('toggle_bold', payload, options);
}

export {
    bindEditorCommandHost,
    unbindEditorCommandHost,
    getEditorCommandHost,
    buildEditorCommandContext,
    assertWritableCommandContext,
    getEditorCommandMeta,
    listEditorCommands,
    EDITOR_COMMAND_CODES,
};

export default {
    executeEditorCommand,
    bindEditorCommandHost,
    unbindEditorCommandHost,
    cmdInsertContactCta,
    cmdInsertContactValue,
    cmdRemoveLinkKeepText,
    cmdToggleBold,
};
