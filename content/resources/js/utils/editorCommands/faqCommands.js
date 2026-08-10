/**
 * Phase 4 — FAQ document commands (placeholder only; snapshot persist stays Phase 2C).
 */

import { FAQ_SHORTCODE_HTML, isFaqPlaceholderHtml } from '../editorHtmlUtils';
import { insertHtmlInEditor } from '../editorSelectionUtils';
import { EDITOR_COMMAND_CODES, failCommand } from './editorCommandResult';
import { resolveTargetEditor } from './resolveTargetEditor';
import { runEditorTransaction } from './runEditorTransaction';

export function insertFaqPlaceholderCommand(context, payload = {}) {
    const resolved = resolveTargetEditor(context, payload, 'insert_faq_placeholder');
    if (resolved.error) {
        return resolved.error;
    }
    const html = String(payload.html ?? FAQ_SHORTCODE_HTML).trim() || FAQ_SHORTCODE_HTML;
    const bookmark = payload.bookmark
        ?? payload.selectionBookmark
        ?? context.insertionContext?.selection
        ?? null;

    return runEditorTransaction({
        editor: resolved.editor,
        editorId: resolved.editorId,
        command: 'insert_faq_placeholder',
        context,
        historyPolicy: 'add',
        successCode: EDITOR_COMMAND_CODES.INSERTED,
        build: () => insertHtmlInEditor(resolved.editor, html, bookmark),
    });
}

/**
 * Apply FAQ fragment HTML into body (one history step). Domain FAQ rows already saved.
 */
export function applyFaqFragmentCommand(context, payload = {}) {
    const resolved = resolveTargetEditor(context, payload, 'apply_faq_fragment');
    if (resolved.error) {
        return resolved.error;
    }
    const html = String(payload.html ?? '').trim();
    if (!html) {
        return failCommand('apply_faq_fragment', EDITOR_COMMAND_CODES.FAQ_INSERT_FAILED, {
            editor_id: resolved.editorId,
        });
    }
    const bookmark = payload.bookmark
        ?? payload.selectionBookmark
        ?? context.insertionContext?.selection
        ?? null;

    return runEditorTransaction({
        editor: resolved.editor,
        editorId: resolved.editorId,
        command: 'apply_faq_fragment',
        context,
        historyPolicy: 'add',
        successCode: EDITOR_COMMAND_CODES.INSERTED,
        build: () => insertHtmlInEditor(resolved.editor, html, bookmark),
    });
}

export function removeFaqPlaceholderCommand(context, payload = {}) {
    const resolved = resolveTargetEditor(context, payload, 'remove_faq_placeholder');
    if (resolved.error) {
        return resolved.error;
    }
    return runEditorTransaction({
        editor: resolved.editor,
        editorId: resolved.editorId,
        command: 'remove_faq_placeholder',
        context,
        historyPolicy: 'add',
        successCode: EDITOR_COMMAND_CODES.REMOVED,
        build: (ed) => {
            const html = ed.getHTML();
            if (!isFaqPlaceholderHtml(html)) {
                return false;
            }
            // Replace whole doc FAQ shortcode only when payload says replaceHtml.
            if (payload.replaceHtml != null) {
                ed.commands.setContent(String(payload.replaceHtml || '<p></p>'), { emitUpdate: true });
                return true;
            }
            return ed.chain().focus().deleteSelection().run();
        },
    });
}

export default {
    insertFaqPlaceholderCommand,
    applyFaqFragmentCommand,
    removeFaqPlaceholderCommand,
};
