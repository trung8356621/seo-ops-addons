/**
 * Phase 4 — insertion commands (CTA / contact / text / link / HTML compat).
 */

import {
    insertContactCtaAtBookmark,
    insertContactValueAtBookmark,
    insertHtmlInEditor,
    insertLinkInEditor,
    insertTextInEditor,
} from '../editorSelectionUtils';
import { EDITOR_COMMAND_CODES, failCommand } from './editorCommandResult';
import { runEditorTransaction } from './runEditorTransaction';
import { resolveTargetEditor } from './resolveTargetEditor';

function resolveBookmark(context, payload) {
    return payload.bookmark
        ?? payload.selectionBookmark
        ?? context.insertionContext?.selection
        ?? null;
}

function withResolvedEditor(context, payload, commandName, run) {
    const resolved = resolveTargetEditor(context, payload, commandName);
    if (resolved.error) {
        return resolved.error;
    }
    return run(resolved.editor, resolved.editorId);
}

export function insertContactValueCommand(context, payload = {}) {
    return withResolvedEditor(context, payload, 'insert_contact_value', (editor, editorId) => {
        const label = String(payload.label ?? payload.text ?? '').trim();
        const href = String(payload.href ?? '').trim();
        const type = String(payload.type ?? payload.contactType ?? '').trim();
        const bookmark = resolveBookmark(context, payload);
        if (!label) {
            return failCommand('insert_contact_value', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_contact_value',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: () => insertContactValueAtBookmark(editor, label, href, type, bookmark),
        });
    });
}

export function insertContactCtaCommand(context, payload = {}) {
    return withResolvedEditor(context, payload, 'insert_contact_cta', (editor, editorId) => {
        const bookmark = resolveBookmark(context, payload);
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_contact_cta',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: () => insertContactCtaAtBookmark(editor, {
                contactType: payload.contactType ?? payload.type,
                type: payload.type ?? payload.contactType,
                label: payload.label ?? payload.text,
                href: payload.href,
                sentence: payload.sentence,
                valueLabel: payload.value_label ?? payload.valueLabel,
                templateKey: payload.templateKey ?? null,
                bookmark,
            }),
        });
    });
}

export function insertTextCommand(context, payload = {}) {
    return withResolvedEditor(context, payload, 'insert_text', (editor, editorId) => {
        const text = String(payload.text ?? '').trim();
        if (!text) {
            return failCommand('insert_text', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        const bookmark = resolveBookmark(context, payload);
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_text',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: () => insertTextInEditor(editor, text, bookmark),
        });
    });
}

export function insertLinkCommand(context, payload = {}) {
    return withResolvedEditor(context, payload, 'insert_link', (editor, editorId) => {
        const label = String(payload.label ?? payload.text ?? '').trim();
        const href = String(payload.href ?? '').trim();
        if (!label || !href) {
            return failCommand('insert_link', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        const bookmark = resolveBookmark(context, payload);
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_link',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: () => insertLinkInEditor(editor, label, href, bookmark),
        });
    });
}

export function insertHtmlCompatCommand(context, payload = {}) {
    return withResolvedEditor(context, payload, 'insert_html_compat', (editor, editorId) => {
        const html = String(payload.html ?? '').trim();
        if (!html) {
            return failCommand('insert_html_compat', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        const bookmark = resolveBookmark(context, payload);
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_html_compat',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: () => insertHtmlInEditor(editor, html, bookmark),
        });
    });
}

export function insertContentFragmentCommand(context, payload = {}) {
    return withResolvedEditor(context, payload, 'insert_content_fragment', (editor, editorId) => {
        const content = payload.content;
        if (content == null) {
            return failCommand('insert_content_fragment', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_content_fragment',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: (ed) => ed.chain().focus().insertContent(content).run(),
        });
    });
}

export default {
    insertContactValueCommand,
    insertContactCtaCommand,
    insertTextCommand,
    insertLinkCommand,
    insertHtmlCompatCommand,
    insertContentFragmentCommand,
};
