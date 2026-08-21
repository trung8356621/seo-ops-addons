/**
 * Phase 4 commands — heading/paragraph structure via headingSplitEngine.
 */

import { EDITOR_COMMAND_CODES, failCommand, okCommand } from './editorCommandResult';
import { resolveTargetEditor } from './resolveTargetEditor';
import { runEditorTransaction } from './runEditorTransaction';
import { runHostStructure } from './structureCommands';
import { planCanonicalArticleBlockSplit } from './canonicalArticleBlockSplit';
import {
    changeCurrentBlockType,
    changeHeadingLevelByIndex,
    convertHeadingByIndex,
    deleteHeadingKeepContent,
    deleteHeadingWithContent,
    insertHeadingAfterSection,
    renameHeadingByIndex,
    setHeadingOutlineVisible,
} from './headingSplitEngine';

function withEditor(context, payload, name, run) {
    const resolved = resolveTargetEditor(context, payload, name);
    if (resolved.error) {
        return resolved.error;
    }

    return run(resolved.editor, resolved.editorId);
}

function runStateCommand(context, payload, name, apply) {
    return withEditor(context, payload, name, (editor, editorId) => (
        runEditorTransaction({
            editor,
            editorId,
            command: name,
            context,
            historyPolicy: 'add',
            build: (ed) => apply(ed.state, (tr) => ed.view.dispatch(tr)),
        })
    ));
}

function publishSplitDebug(entry) {
    if (typeof window === 'undefined') {
        return;
    }
    window.__SEO_LAST_BLOCK_SPLIT__ = entry;
    if (!window.__SEO_DEBUG_CTX_MENU__) {
        return;
    }
    // eslint-disable-next-line no-console
    console.debug('[ctx-menu]', `action=${entry.action}`, entry);
}

function runCanonicalBlockSplit(context, payload, name, mode) {
    return withEditor(context, payload, name, (editor, editorId) => {
        const sourceBlockId = String(payload.blockId ?? payload.sourceBlockId ?? editorId ?? '').trim();
        const plan = planCanonicalArticleBlockSplit(editor.state, {
            mode,
            level: payload.level,
        });
        const sourceHtml = typeof editor.getHTML === 'function' ? editor.getHTML() : '';
        const debugBase = {
            action: name,
            sourceBlock: sourceBlockId,
            selection: { from: plan.meta?.from, to: plan.meta?.to, empty: plan.meta?.empty },
            parentType: plan.meta?.parentType,
            parentOffset: plan.meta?.parentOffset,
            textBefore: plan.meta?.textBefore,
            selectedText: plan.meta?.selectedText,
            textAfter: plan.meta?.textAfter,
            sourceHtml,
            plan: {
                ok: plan.ok,
                reason: plan.reason,
                contents: plan.contents,
                focusIndex: plan.focusIndex,
            },
            host: 'replace_blocks_at',
        };
        if (!plan.ok) {
            const code = plan.reason === 'no_change' || plan.reason === 'boundary'
                ? EDITOR_COMMAND_CODES.NO_CHANGE
                : plan.reason === 'cross_block'
                    ? EDITOR_COMMAND_CODES.UNSUPPORTED_SELECTION
                    : EDITOR_COMMAND_CODES.SELECTION_INVALID;
            publishSplitDebug({ ...debugBase, result: plan.reason || 'unsupported' });
            return failCommand(name, code, {
                editor_id: sourceBlockId,
                meta: plan.meta,
            });
        }

        if (typeof context.onStructureMutation !== 'function') {
            publishSplitDebug({ ...debugBase, result: 'host_command_missing' });
            return failCommand(name, EDITOR_COMMAND_CODES.HOST_COMMAND_MISSING, {
                editor_id: sourceBlockId,
            });
        }

        const result = runHostStructure(context, 'replace_blocks_at', {
            sourceBlockId,
            blockId: sourceBlockId,
            replacements: plan.contents,
            contents: plan.contents,
            focusIndex: plan.focusIndex,
            skipCommit: true,
            skipAnalyze: true,
        });
        const hostOk = Boolean(result?.ok && result?.document_changed);
        publishSplitDebug({
            ...debugBase,
            hostResult: {
                ok: result?.ok,
                code: result?.code,
                reason: result?.meta?.reason,
                createdIds: result?.meta?.createdIds,
                beforeCount: result?.meta?.beforeCount,
                afterCount: result?.meta?.afterCount,
            },
            result: hostOk ? 'changed' : (result?.code || 'no_change'),
        });
        if (!hostOk) {
            return failCommand(name, result?.code || EDITOR_COMMAND_CODES.NO_CHANGE, {
                editor_id: sourceBlockId,
                meta: result?.meta ?? {},
            });
        }

        return okCommand(name, EDITOR_COMMAND_CODES.UPDATED, {
            editor_id: sourceBlockId,
            transaction_applied: true,
            document_changed: true,
            history_step: true,
            meta: result.meta ?? {},
        });
    });
}

export function splitSelectionToHeadingCommand(context, payload = {}) {
    const level = Math.min(4, Math.max(2, Number(payload.level) || 3));

    return runCanonicalBlockSplit(context, { ...payload, level }, 'split_selection_to_heading', 'heading');
}

export function splitSelectionToParagraphCommand(context, payload = {}) {
    return runCanonicalBlockSplit(context, payload, 'split_selection_to_paragraph', 'paragraph');
}

export function splitParagraphAtCursorCommand(context, payload = {}) {
    return runCanonicalBlockSplit(context, payload, 'split_paragraph_at_cursor', 'cursor');
}

export function changeCurrentBlockHeadingCommand(context, payload = {}) {
    const level = Math.min(6, Math.max(2, Number(payload.level) || 3));

    return runStateCommand(context, payload, 'change_current_block_heading', (state, dispatch) => (
        changeCurrentBlockType(state, dispatch, { nodeType: 'heading', level })
    ));
}

export function renameHeadingCommand(context, payload = {}) {
    const headingIndex = Number(payload.headingIndex ?? payload.heading_index);
    const text = String(payload.text ?? payload.heading_text ?? '').trim();
    if (!Number.isFinite(headingIndex) || headingIndex < 0 || text === '') {
        return failCommand('rename_heading', EDITOR_COMMAND_CODES.SELECTION_INVALID);
    }

    return runStateCommand(context, payload, 'rename_heading', (state, dispatch) => (
        renameHeadingByIndex(state, dispatch, { headingIndex, text })
    ));
}

export function changeHeadingLevelCommand(context, payload = {}) {
    const headingIndex = Number(payload.headingIndex ?? payload.heading_index);
    const level = Math.min(6, Math.max(2, Number(payload.level) || 2));
    if (!Number.isFinite(headingIndex) || headingIndex < 0) {
        return failCommand('change_heading_level', EDITOR_COMMAND_CODES.SELECTION_INVALID);
    }

    return runStateCommand(context, payload, 'change_heading_level', (state, dispatch) => (
        changeHeadingLevelByIndex(state, dispatch, { headingIndex, level })
    ));
}

export function convertHeadingCommand(context, payload = {}) {
    const headingIndex = Number(payload.headingIndex ?? payload.heading_index);
    const kind = String(payload.kind ?? '').trim();
    if (!Number.isFinite(headingIndex) || headingIndex < 0 || kind === '') {
        return failCommand('convert_heading', EDITOR_COMMAND_CODES.SELECTION_INVALID);
    }

    return runStateCommand(context, payload, 'convert_heading', (state, dispatch) => (
        convertHeadingByIndex(state, dispatch, { headingIndex, kind })
    ));
}

export function deleteHeadingKeepContentCommand(context, payload = {}) {
    const headingIndex = Number(payload.headingIndex ?? payload.heading_index);
    if (!Number.isFinite(headingIndex) || headingIndex < 0) {
        return failCommand('delete_heading_keep_content', EDITOR_COMMAND_CODES.SELECTION_INVALID);
    }

    return runStateCommand(context, payload, 'delete_heading_keep_content', (state, dispatch) => (
        deleteHeadingKeepContent(state, dispatch, { headingIndex })
    ));
}

export function deleteHeadingWithContentCommand(context, payload = {}) {
    const headingIndex = Number(payload.headingIndex ?? payload.heading_index);
    if (!Number.isFinite(headingIndex) || headingIndex < 0) {
        return failCommand('delete_heading_with_content', EDITOR_COMMAND_CODES.SELECTION_INVALID);
    }

    return runStateCommand(context, payload, 'delete_heading_with_content', (state, dispatch) => (
        deleteHeadingWithContent(state, dispatch, { headingIndex })
    ));
}

export function setHeadingOutlineVisibleCommand(context, payload = {}) {
    const headingIndex = Number(payload.headingIndex ?? payload.heading_index);
    if (!Number.isFinite(headingIndex) || headingIndex < 0) {
        return failCommand('set_heading_outline_visible', EDITOR_COMMAND_CODES.SELECTION_INVALID);
    }

    return runStateCommand(context, payload, 'set_heading_outline_visible', (state, dispatch) => (
        setHeadingOutlineVisible(state, dispatch, {
            headingIndex,
            visible: payload.visible !== false,
        })
    ));
}

export function insertHeadingAfterCommand(context, payload = {}) {
    const headingIndex = Number(payload.headingIndex ?? payload.heading_index ?? -1);
    const level = Math.min(6, Math.max(2, Number(payload.level) || 3));
    const text = String(payload.text ?? payload.heading_text ?? '').trim();

    return runStateCommand(context, payload, 'insert_heading_after', (state, dispatch) => (
        insertHeadingAfterSection(state, dispatch, {
            headingIndex,
            level,
            text,
            insertParagraph: payload.insertParagraph !== false,
            paragraphOnly: payload.paragraphOnly === true || payload.paragraph === true,
        })
    ));
}

export default {
    splitSelectionToHeadingCommand,
    splitSelectionToParagraphCommand,
    splitParagraphAtCursorCommand,
    changeCurrentBlockHeadingCommand,
    renameHeadingCommand,
    changeHeadingLevelCommand,
    convertHeadingCommand,
    deleteHeadingKeepContentCommand,
    deleteHeadingWithContentCommand,
    setHeadingOutlineVisibleCommand,
    insertHeadingAfterCommand,
};
