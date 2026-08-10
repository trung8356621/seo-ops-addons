/**
 * Phase 4 — command registry metadata + handlers.
 */

import {
    insertContactCtaCommand,
    insertContactValueCommand,
    insertContentFragmentCommand,
    insertHtmlCompatCommand,
    insertLinkCommand,
    insertTextCommand,
} from './insertionCommands';
import {
    createLinkCommand,
    exitLinkAtBoundaryCommand,
    removeLinkKeepTextCommand,
    updateLinkCommand,
} from './linkCommands';
import {
    clearFormattingCommand,
    insertEmojiCommand,
    insertTableCommand,
    redoCommand,
    setColorCommand,
    setHorizontalRuleCommand,
    setParagraphStyleCommand,
    setTextAlignCommand,
    toggleBlockquoteCommand,
    toggleBoldCommand,
    toggleBulletListCommand,
    toggleCodeCommand,
    toggleHighlightCommand,
    toggleItalicCommand,
    toggleOrderedListCommand,
    toggleStrikeCommand,
    toggleSubscriptCommand,
    toggleSuperscriptCommand,
    toggleUnderlineCommand,
    undoCommand,
} from './formattingCommands';
import {
    deleteImageCommand,
    insertImageCommand,
    replaceImageCommand,
    updateImageAttributesCommand,
} from './mediaCommands';
import {
    applyFaqFragmentCommand,
    insertFaqPlaceholderCommand,
    removeFaqPlaceholderCommand,
} from './faqCommands';
import {
    deleteBlockCommand,
    duplicateBlockCommand,
    moveBlockCommand,
    moveBlockToAdjacentSectionCommand,
    moveBlockWithinSectionCommand,
    outlineJumpCommand,
    setTextSelectionCommand,
    splitBlockCommand,
} from './structureCommands';
import {
    applyDocumentFragmentCommand,
    replaceArticleDocumentCommand,
} from './documentReplaceCommands';

/**
 * @typedef {{
 *   name: string,
 *   mutatesDocument: boolean,
 *   requiresWritable: boolean,
 *   requiresInsertionContext: boolean,
 *   allowedInReadOnly: boolean,
 *   historyPolicy: 'add'|'skip',
 *   permissionKey: string|null,
 *   execute: (context: object, payload: object) => object,
 * }} EditorCommandMeta
 */

/** @type {Map<string, EditorCommandMeta>} */
const REGISTRY = new Map();

/**
 * @param {Omit<EditorCommandMeta, 'name'> & { name?: string }} meta
 * @param {string} name
 */
function register(name, meta) {
    REGISTRY.set(name, {
        name,
        mutatesDocument: meta.mutatesDocument !== false,
        requiresWritable: meta.requiresWritable !== false,
        requiresInsertionContext: Boolean(meta.requiresInsertionContext),
        allowedInReadOnly: Boolean(meta.allowedInReadOnly),
        historyPolicy: meta.historyPolicy === 'skip' ? 'skip' : 'add',
        permissionKey: meta.permissionKey ?? null,
        execute: meta.execute,
    });
}

function mut(name, execute, extra = {}) {
    register(name, {
        mutatesDocument: true,
        requiresWritable: true,
        allowedInReadOnly: false,
        historyPolicy: 'add',
        execute,
        ...extra,
    });
}

function nav(name, execute, extra = {}) {
    register(name, {
        mutatesDocument: false,
        requiresWritable: false,
        allowedInReadOnly: true,
        historyPolicy: 'skip',
        execute,
        ...extra,
    });
}

// Insertion
mut('insert_contact_value', insertContactValueCommand, { requiresInsertionContext: true });
mut('insert_contact_cta', insertContactCtaCommand, { requiresInsertionContext: true });
mut('insert_text', insertTextCommand, { requiresInsertionContext: true });
mut('insert_link', insertLinkCommand, { requiresInsertionContext: true });
mut('insert_html_compat', insertHtmlCompatCommand, { requiresInsertionContext: true });
mut('insert_content_fragment', insertContentFragmentCommand, { requiresInsertionContext: true });
mut('insert_faq_placeholder', insertFaqPlaceholderCommand, { requiresInsertionContext: true });

// Links
mut('create_link', createLinkCommand);
mut('update_link', updateLinkCommand);
mut('remove_link_keep_text', removeLinkKeepTextCommand);
nav('exit_link_at_boundary', exitLinkAtBoundaryCommand);

// Formatting
mut('toggle_bold', toggleBoldCommand);
mut('toggle_italic', toggleItalicCommand);
mut('toggle_underline', toggleUnderlineCommand);
mut('toggle_strike', toggleStrikeCommand);
mut('toggle_bullet_list', toggleBulletListCommand);
mut('toggle_ordered_list', toggleOrderedListCommand);
mut('toggle_blockquote', toggleBlockquoteCommand);
mut('toggle_highlight', toggleHighlightCommand);
mut('toggle_subscript', toggleSubscriptCommand);
mut('toggle_superscript', toggleSuperscriptCommand);
mut('toggle_code', toggleCodeCommand);
mut('set_horizontal_rule', setHorizontalRuleCommand);
mut('set_text_align', setTextAlignCommand);
mut('set_paragraph_style', setParagraphStyleCommand);
mut('clear_formatting', clearFormattingCommand);
mut('set_color', setColorCommand);
mut('insert_table', insertTableCommand);
mut('insert_emoji', insertEmojiCommand);
mut('undo', undoCommand);
mut('redo', redoCommand);

// Media (document only)
mut('insert_image', insertImageCommand);
mut('update_image_attributes', updateImageAttributesCommand);
mut('replace_image', replaceImageCommand);
mut('delete_image', deleteImageCommand);

// FAQ
mut('apply_faq_fragment', applyFaqFragmentCommand, { requiresInsertionContext: true });
mut('remove_faq_placeholder', removeFaqPlaceholderCommand);

// Structure
mut('delete_block', deleteBlockCommand);
mut('duplicate_block', duplicateBlockCommand);
mut('move_block', moveBlockCommand);
mut('move_block_within_section', moveBlockWithinSectionCommand);
mut('move_block_to_adjacent_section', moveBlockToAdjacentSectionCommand);
mut('split_block', splitBlockCommand);
nav('outline_jump', outlineJumpCommand);
nav('set_text_selection', setTextSelectionCommand);

// AI / revision
mut('replace_article_document', replaceArticleDocumentCommand);
mut('apply_document_fragment', applyDocumentFragmentCommand);

export function getEditorCommandMeta(name) {
    return REGISTRY.get(String(name ?? '')) ?? null;
}

export function listEditorCommands() {
    return [...REGISTRY.values()].map(({ execute, ...meta }) => meta);
}

export function getEditorCommandRegistry() {
    return REGISTRY;
}

export default {
    getEditorCommandMeta,
    listEditorCommands,
    getEditorCommandRegistry,
};
