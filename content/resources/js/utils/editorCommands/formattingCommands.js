/**
 * Phase 4 — formatting commands (toolbar + shortcuts share this boundary).
 */

import { EDITOR_COMMAND_CODES, failCommand, okCommand } from './editorCommandResult';
import { resolveTargetEditor } from './resolveTargetEditor';
import { runEditorTransaction } from './runEditorTransaction';
import { canApplyParagraphStyle } from '../paragraphStyleCompatibility';

function withEditor(context, payload, name, run) {
    const resolved = resolveTargetEditor(context, payload, name);
    if (resolved.error) {
        return resolved.error;
    }
    return run(resolved.editor, resolved.editorId);
}

function toggleCommand(name, chainFn) {
    return (context, payload = {}) => withEditor(context, payload, name, (editor, editorId) => (
        runEditorTransaction({
            editor,
            editorId,
            command: name,
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.UPDATED,
            build: (ed) => chainFn(ed.chain().focus()).run(),
        })
    ));
}

export const toggleBoldCommand = toggleCommand('toggle_bold', (c) => c.toggleBold());
export const toggleItalicCommand = toggleCommand('toggle_italic', (c) => c.toggleItalic());
export const toggleUnderlineCommand = toggleCommand('toggle_underline', (c) => c.toggleUnderline());
export const toggleStrikeCommand = toggleCommand('toggle_strike', (c) => c.toggleStrike());
export const toggleBulletListCommand = toggleCommand('toggle_bullet_list', (c) => c.toggleBulletList());
export const toggleOrderedListCommand = toggleCommand('toggle_ordered_list', (c) => c.toggleOrderedList());
export const toggleBlockquoteCommand = toggleCommand('toggle_blockquote', (c) => c.toggleBlockquote());
export const toggleHighlightCommand = toggleCommand('toggle_highlight', (c) => c.toggleHighlight());
export const toggleSubscriptCommand = toggleCommand('toggle_subscript', (c) => c.toggleSubscript());
export const toggleSuperscriptCommand = toggleCommand('toggle_superscript', (c) => c.toggleSuperscript());
export const toggleCodeCommand = toggleCommand('toggle_code', (c) => c.toggleCode());
export const setHorizontalRuleCommand = toggleCommand('set_horizontal_rule', (c) => c.setHorizontalRule());

export function setTextAlignCommand(context, payload = {}) {
    return withEditor(context, payload, 'set_text_align', (editor, editorId) => {
        const align = String(payload.align ?? '').trim();
        if (!align) {
            return failCommand('set_text_align', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        return runEditorTransaction({
            editor,
            editorId,
            command: 'set_text_align',
            context,
            historyPolicy: 'add',
            build: (ed) => ed.chain().focus().setTextAlign(align).run(),
        });
    });
}

export function setParagraphStyleCommand(context, payload = {}) {
    return withEditor(context, payload, 'set_paragraph_style', (editor, editorId) => {
        const value = String(payload.value ?? payload.style ?? 'p').trim();
        if (!canApplyParagraphStyle(editor, value)) {
            return failCommand('set_paragraph_style', EDITOR_COMMAND_CODES.NO_CHANGE, {
                editor_id: editorId,
                message_key: 'editor_command.selection_invalid',
                meta: { reason: 'heading_incompatible_structured_block', value },
            });
        }

        return runEditorTransaction({
            editor,
            editorId,
            command: 'set_paragraph_style',
            context,
            historyPolicy: 'add',
            build: (ed) => {
                const chain = ed.chain().focus();
                if (value === 'p') {
                    return chain.setParagraph().run();
                }
                if (value === 'pre') {
                    return chain.toggleCodeBlock().run();
                }
                const level = Number.parseInt(value.replace('h', ''), 10);
                if (!Number.isFinite(level) || level < 1 || level > 6) {
                    return false;
                }
                return chain.setHeading({ level }).run();
            },
        });
    });
}

export function clearFormattingCommand(context, payload = {}) {
    return withEditor(context, payload, 'clear_formatting', (editor, editorId) => (
        runEditorTransaction({
            editor,
            editorId,
            command: 'clear_formatting',
            context,
            historyPolicy: 'add',
            build: (ed) => ed.chain().focus().unsetAllMarks().run(),
        })
    ));
}

export function setColorCommand(context, payload = {}) {
    return withEditor(context, payload, 'set_color', (editor, editorId) => {
        const color = String(payload.color ?? '').trim();
        if (!color) {
            return failCommand('set_color', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        return runEditorTransaction({
            editor,
            editorId,
            command: 'set_color',
            context,
            historyPolicy: 'add',
            build: (ed) => ed.chain().focus().setColor(color).run(),
        });
    });
}

export function insertTableCommand(context, payload = {}) {
    return withEditor(context, payload, 'insert_table', (editor, editorId) => (
        runEditorTransaction({
            editor,
            editorId,
            command: 'insert_table',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: (ed) => ed.chain().focus().insertTable({
                rows: Number(payload.rows) || 3,
                cols: Number(payload.cols) || 3,
                withHeaderRow: payload.withHeaderRow !== false,
            }).run(),
        })
    ));
}

export function insertEmojiCommand(context, payload = {}) {
    return withEditor(context, payload, 'insert_emoji', (editor, editorId) => {
        const emoji = String(payload.emoji ?? payload.text ?? '');
        if (!emoji) {
            return failCommand('insert_emoji', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        const from = Number(payload.from);
        const to = Number(payload.to);
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_emoji',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: (ed) => {
                let chain = ed.chain().focus();
                if (Number.isFinite(from) && Number.isFinite(to) && to >= from) {
                    chain = chain.setTextSelection({ from, to });
                }
                return chain.insertContent(emoji).run();
            },
        });
    });
}

export function undoCommand(context, payload = {}) {
    return withEditor(context, payload, 'undo', (editor, editorId) => {
        const ok = editor.chain().focus().undo().run();
        return okCommand('undo', ok ? EDITOR_COMMAND_CODES.UPDATED : EDITOR_COMMAND_CODES.NO_CHANGE, {
            editor_id: editorId,
            transaction_applied: ok,
            document_changed: ok,
            history_step: false,
        });
    });
}

export function redoCommand(context, payload = {}) {
    return withEditor(context, payload, 'redo', (editor, editorId) => {
        const ok = editor.chain().focus().redo().run();
        return okCommand('redo', ok ? EDITOR_COMMAND_CODES.UPDATED : EDITOR_COMMAND_CODES.NO_CHANGE, {
            editor_id: editorId,
            transaction_applied: ok,
            document_changed: ok,
            history_step: false,
        });
    });
}

export default {
    toggleBoldCommand,
    toggleItalicCommand,
    toggleUnderlineCommand,
    toggleStrikeCommand,
    toggleBulletListCommand,
    toggleOrderedListCommand,
    toggleBlockquoteCommand,
    toggleHighlightCommand,
    toggleSubscriptCommand,
    toggleSuperscriptCommand,
    toggleCodeCommand,
    setHorizontalRuleCommand,
    setTextAlignCommand,
    setParagraphStyleCommand,
    clearFormattingCommand,
    setColorCommand,
    insertTableCommand,
    insertEmojiCommand,
    undoCommand,
    redoCommand,
};
