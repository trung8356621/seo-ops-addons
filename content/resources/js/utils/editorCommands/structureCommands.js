/**
 * Phase 4 — structure commands (block-level via host callback; TipTap navigation separate).
 */

import { EDITOR_COMMAND_CODES, failCommand, okCommand } from './editorCommandResult';
import { emitDocumentChanged } from './runEditorTransaction';
import { resolveTargetEditor } from './resolveTargetEditor';

export function runHostStructure(context, name, payload) {
    if (typeof context.onStructureMutation !== 'function') {
        return failCommand(name, EDITOR_COMMAND_CODES.NOT_READY, {
            message_key: 'editor_command.editor_not_ready',
        });
    }
    const result = context.onStructureMutation(name, payload);
    if (result === false || result == null) {
        return failCommand(name, EDITOR_COMMAND_CODES.NO_CHANGE, {
            message_key: 'editor_command.host_command_missing',
            meta: { host: name },
        });
    }
    if (result && typeof result === 'object' && Object.prototype.hasOwnProperty.call(result, 'ok')) {
        if (result.ok && result.document_changed) {
            emitDocumentChanged(context, {
                command: name,
                editor_id: payload.sourceBlockId ?? payload.blockId ?? null,
                skipCommit: payload.skipCommit === true || name === 'replace_blocks_at',
                skipAnalyze: payload.skipAnalyze === true || name === 'replace_blocks_at',
            });
        }
        return result;
    }
    emitDocumentChanged(context, { command: name, editor_id: payload.blockId ?? null });
    return okCommand(name, EDITOR_COMMAND_CODES.UPDATED, {
        editor_id: payload.blockId ?? null,
        document_changed: true,
        transaction_applied: true,
        history_step: true,
        meta: typeof result === 'object' ? result : {},
    });
}

export function deleteBlockCommand(context, payload = {}) {
    const blockId = String(payload.blockId ?? payload.id ?? '').trim();
    if (!blockId) {
        return failCommand('delete_block', EDITOR_COMMAND_CODES.TARGET_MISSING);
    }
    return runHostStructure(context, 'delete_block', { ...payload, blockId });
}

export function duplicateBlockCommand(context, payload = {}) {
    const blockId = String(payload.blockId ?? payload.id ?? '').trim();
    if (!blockId) {
        return failCommand('duplicate_block', EDITOR_COMMAND_CODES.TARGET_MISSING);
    }
    return runHostStructure(context, 'duplicate_block', { ...payload, blockId });
}

export function moveBlockCommand(context, payload = {}) {
    const blockId = String(payload.blockId ?? payload.id ?? '').trim();
    if (!blockId) {
        return failCommand('move_block', EDITOR_COMMAND_CODES.TARGET_MISSING);
    }
    return runHostStructure(context, 'move_block', { ...payload, blockId });
}

/**
 * Reorder one section-level block inside the same section.
 * Never crosses section boundaries (unlike move_block_to_adjacent_section).
 */
export function moveBlockWithinSectionCommand(context, payload = {}) {
    const blockId = String(payload.blockId ?? payload.id ?? '').trim();
    const sectionId = String(payload.sectionId ?? payload.section_id ?? '').trim();
    const direction = String(payload.direction ?? '').trim().toLowerCase();

    if (!blockId) {
        return failCommand('move_block_within_section', EDITOR_COMMAND_CODES.TARGET_MISSING);
    }
    if (direction !== 'up' && direction !== 'down') {
        return failCommand('move_block_within_section', EDITOR_COMMAND_CODES.SELECTION_INVALID, {
            message_key: 'editor_command.selection_invalid',
            meta: { direction },
        });
    }

    return runHostStructure(context, 'move_block_within_section', {
        blockId,
        sectionId: sectionId || null,
        direction,
    });
}

/**
 * Move a section-level block into the previous/next section.
 * Separate from within-section reorder — no shared boundary fallback.
 */
export function moveBlockToAdjacentSectionCommand(context, payload = {}) {
    const blockId = String(payload.blockId ?? payload.id ?? '').trim();
    const direction = String(payload.direction ?? '').trim().toLowerCase();
    if (!blockId) {
        return failCommand('move_block_to_adjacent_section', EDITOR_COMMAND_CODES.TARGET_MISSING);
    }
    if (direction !== 'prev' && direction !== 'next' && direction !== 'up' && direction !== 'down') {
        return failCommand('move_block_to_adjacent_section', EDITOR_COMMAND_CODES.SELECTION_INVALID);
    }
    const normalized = direction === 'up' ? 'prev' : direction === 'down' ? 'next' : direction;

    return runHostStructure(context, 'move_block_to_adjacent_section', {
        ...payload,
        blockId,
        direction: normalized,
    });
}

export function splitBlockCommand(context, payload = {}) {
    return runHostStructure(context, 'split_block', payload);
}

export function insertBlockRelativeCommand(context, payload = {}) {
    const blockId = String(payload.blockId ?? payload.id ?? '').trim();
    const type = String(payload.type ?? 'text').trim() || 'text';
    const position = String(payload.position ?? 'after').trim() === 'before' ? 'before' : 'after';
    if (!blockId) {
        return failCommand('insert_block_relative', EDITOR_COMMAND_CODES.TARGET_MISSING);
    }

    return runHostStructure(context, 'insert_block_relative', { ...payload, blockId, type, position });
}

/** Navigation only — no document mutation. */
export function outlineJumpCommand(context, payload = {}) {
    const headingId = String(payload.headingId ?? payload.id ?? '').trim();
    if (!headingId) {
        return failCommand('outline_jump', EDITOR_COMMAND_CODES.TARGET_MISSING);
    }
    if (typeof context.onStructureMutation === 'function') {
        context.onStructureMutation('outline_jump', { ...payload, headingId });
    }
    return okCommand('outline_jump', EDITOR_COMMAND_CODES.NAVIGATED, {
        transaction_applied: false,
        document_changed: false,
        history_step: false,
        meta: { headingId },
    });
}

export function setTextSelectionCommand(context, payload = {}) {
    const resolved = resolveTargetEditor(context, payload, 'set_text_selection');
    if (resolved.error) {
        return resolved.error;
    }
    const from = Number(payload.from);
    const to = Number(payload.to ?? payload.from);
    if (!Number.isFinite(from) || !Number.isFinite(to)) {
        return failCommand('set_text_selection', EDITOR_COMMAND_CODES.SELECTION_INVALID, {
            editor_id: resolved.editorId,
        });
    }
    const ok = resolved.editor.chain().setTextSelection({ from, to }).focus().run();
    return okCommand('set_text_selection', ok ? EDITOR_COMMAND_CODES.NAVIGATED : EDITOR_COMMAND_CODES.NO_CHANGE, {
        editor_id: resolved.editorId,
        transaction_applied: ok,
        document_changed: false,
        selection_changed: ok,
        new_selection: { from, to },
        history_step: false,
    });
}

export default {
    deleteBlockCommand,
    duplicateBlockCommand,
    moveBlockCommand,
    moveBlockWithinSectionCommand,
    moveBlockToAdjacentSectionCommand,
    splitBlockCommand,
    insertBlockRelativeCommand,
    outlineJumpCommand,
    setTextSelectionCommand,
};
