/**
 * Phase 4 — link mutation commands (shared by toolbar + bubble + shortcuts).
 */

import { exitLinkAtBoundary, removeLinkKeepText } from '../editorLinkCommands';
import { EDITOR_COMMAND_CODES, failCommand } from './editorCommandResult';
import { resolveTargetEditor } from './resolveTargetEditor';
import { runEditorTransaction } from './runEditorTransaction';

function withEditor(context, payload, name, run) {
    const resolved = resolveTargetEditor(context, payload, name);
    if (resolved.error) {
        return resolved.error;
    }
    return run(resolved.editor, resolved.editorId);
}

export function createLinkCommand(context, payload = {}) {
    return withEditor(context, payload, 'create_link', (editor, editorId) => {
        const href = String(payload.href ?? '').trim();
        if (!href) {
            return failCommand('create_link', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        const attrs = {
            href,
            target: payload.target ?? '_blank',
            rel: payload.rel ?? 'noopener noreferrer',
            class: payload.className ?? payload.class ?? undefined,
        };
        return runEditorTransaction({
            editor,
            editorId,
            command: 'create_link',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.UPDATED,
            build: (ed) => {
                const { from, to, empty } = ed.state.selection;
                const chain = ed.chain().focus();
                if (empty) {
                    if (payload.extendMarkRange !== false && ed.isActive('link')) {
                        chain.extendMarkRange('link');
                    }
                    return chain.setLink(attrs).run();
                }
                return chain.setTextSelection({ from, to }).unsetLink().setLink(attrs).run();
            },
        });
    });
}

export function updateLinkCommand(context, payload = {}) {
    return createLinkCommand(context, { ...payload, extendMarkRange: true });
}

export function removeLinkKeepTextCommand(context, payload = {}) {
    return withEditor(context, payload, 'remove_link_keep_text', (editor, editorId) => {
        return runEditorTransaction({
            editor,
            editorId,
            command: 'remove_link_keep_text',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.REMOVED,
            build: () => removeLinkKeepText(editor),
        });
    });
}

export function exitLinkAtBoundaryCommand(context, payload = {}) {
    return withEditor(context, payload, 'exit_link_at_boundary', (editor, editorId) => {
        return runEditorTransaction({
            editor,
            editorId,
            command: 'exit_link_at_boundary',
            context,
            historyPolicy: 'skip',
            successCode: EDITOR_COMMAND_CODES.NO_CHANGE,
            build: () => exitLinkAtBoundary(editor),
        });
    });
}

export default {
    createLinkCommand,
    updateLinkCommand,
    removeLinkKeepTextCommand,
    exitLinkAtBoundaryCommand,
};
