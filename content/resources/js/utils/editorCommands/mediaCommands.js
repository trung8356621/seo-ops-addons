/**
 * Phase 4 — document media occurrence commands (not HTTP media API / rename).
 */

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

export function insertImageCommand(context, payload = {}) {
    return withEditor(context, payload, 'insert_image', (editor, editorId) => {
        const src = String(payload.src ?? payload.url ?? '').trim();
        if (!src) {
            return failCommand('insert_image', EDITOR_COMMAND_CODES.MEDIA_INSERT_FAILED, { editor_id: editorId });
        }
        const attrs = {
            src,
            alt: String(payload.alt ?? '').trim(),
            title: String(payload.title ?? '').trim(),
            caption: String(payload.caption ?? '').trim(),
            align: payload.align ?? 'none',
            ...(payload.attrs && typeof payload.attrs === 'object' ? payload.attrs : {}),
        };
        return runEditorTransaction({
            editor,
            editorId,
            command: 'insert_image',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.INSERTED,
            build: (ed) => ed.chain().focus().insertContent({
                type: 'articleImage',
                attrs,
            }).run(),
        });
    });
}

export function updateImageAttributesCommand(context, payload = {}) {
    return withEditor(context, payload, 'update_image_attributes', (editor, editorId) => {
        const attrs = payload.attrs && typeof payload.attrs === 'object' ? payload.attrs : null;
        if (!attrs || Object.keys(attrs).length === 0) {
            return failCommand('update_image_attributes', EDITOR_COMMAND_CODES.NO_CHANGE, { editor_id: editorId });
        }
        return runEditorTransaction({
            editor,
            editorId,
            command: 'update_image_attributes',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.UPDATED,
            build: (ed) => ed.chain().focus().updateAttributes('articleImage', attrs).run(),
        });
    });
}

export function replaceImageCommand(context, payload = {}) {
    return updateImageAttributesCommand(context, {
        ...payload,
        attrs: {
            ...(payload.attrs || {}),
            ...(payload.src ? { src: payload.src } : {}),
            ...(payload.alt != null ? { alt: payload.alt } : {}),
        },
    });
}

export function deleteImageCommand(context, payload = {}) {
    return withEditor(context, payload, 'delete_image', (editor, editorId) => (
        runEditorTransaction({
            editor,
            editorId,
            command: 'delete_image',
            context,
            historyPolicy: 'add',
            successCode: EDITOR_COMMAND_CODES.REMOVED,
            build: (ed) => ed.chain().focus().deleteSelection().run(),
        })
    ));
}

export default {
    insertImageCommand,
    updateImageAttributesCommand,
    replaceImageCommand,
    deleteImageCommand,
};
