/**
 * Built-in core module — document extensions, base toolbar, undo/redo shortcuts.
 */

import { articleEditorExtensions } from '../../../utils/editorExtensions';

import { isRuntimeContextWritable } from '../../runtime/editorRuntimeContext';

const mutationEnabled = (context) => isRuntimeContextWritable(context);

const CORE_TOOLBAR = [
    { id: 'core.toolbar.undo', group: 'history', order: 10, command: 'undo', labelKey: 'toolbar_undo', iconKey: 'undo', canKey: 'undo', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.redo', group: 'history', order: 11, command: 'redo', labelKey: 'toolbar_redo', iconKey: 'redo', canKey: 'redo', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.bold', group: 'inline', order: 20, command: 'toggle_bold', labelKey: 'toolbar_bold', iconKey: 'bold', activeMark: 'bold', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.italic', group: 'inline', order: 21, command: 'toggle_italic', labelKey: 'toolbar_italic', iconKey: 'italic', activeMark: 'italic', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.underline', group: 'inline', order: 22, command: 'toggle_underline', labelKey: 'toolbar_underline', iconKey: 'underline', activeMark: 'underline', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.strike', group: 'inline', order: 23, command: 'toggle_strike', labelKey: 'toolbar_strikethrough', iconKey: 'strike', activeMark: 'strike', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.bullet', group: 'lists', order: 30, command: 'toggle_bullet_list', labelKey: 'toolbar_bullet_list', iconKey: 'bullet', activeMark: 'bulletList', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.ordered', group: 'lists', order: 31, command: 'toggle_ordered_list', labelKey: 'toolbar_ordered_list', iconKey: 'ordered', activeMark: 'orderedList', mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.align_left', group: 'align', order: 40, command: 'set_text_align', payload: { align: 'left' }, labelKey: 'toolbar_align_left', iconKey: 'alignLeft', activeAttrs: { textAlign: 'left' }, mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.align_center', group: 'align', order: 41, command: 'set_text_align', payload: { align: 'center' }, labelKey: 'toolbar_align_center', iconKey: 'alignCenter', activeAttrs: { textAlign: 'center' }, mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.align_right', group: 'align', order: 42, command: 'set_text_align', payload: { align: 'right' }, labelKey: 'toolbar_align_right', iconKey: 'alignRight', activeAttrs: { textAlign: 'right' }, mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.align_justify', group: 'align', order: 43, command: 'set_text_align', payload: { align: 'justify' }, labelKey: 'toolbar_align_justify', iconKey: 'alignJustify', activeAttrs: { textAlign: 'justify' }, mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.quote', group: 'insert', order: 50, command: 'toggle_blockquote', labelKey: 'toolbar_insert_quote_short', iconKey: 'quote', activeMark: 'blockquote', insertStyle: true, mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.hr', group: 'insert', order: 51, command: 'set_horizontal_rule', labelKey: 'toolbar_insert_divider_short', iconKey: 'hr', insertStyle: true, mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
    { id: 'core.toolbar.table', group: 'insert', order: 52, command: 'insert_table', labelKey: 'toolbar_insert_table_short', iconKey: 'table', insertStyle: true, mutation: true, requiresWritable: true, isEnabled: mutationEnabled },
];

const CORE_SHORTCUTS = [
    { id: 'core.shortcut.undo', order: 10, keys: 'Mod-z', command: 'undo' },
    { id: 'core.shortcut.redo', order: 11, keys: 'Mod-Shift-z', command: 'redo' },
];

export const coreModule = {
    id: 'article-editor.core',
    version: 1,
    order: 10,
    dependsOn: [],
    isEnabled: () => true,
    documentExtensions: articleEditorExtensions.map((extension, index) => ({
        id: `article-editor.core.ext.${extension?.name || index}`,
        name: String(extension?.name || `core-ext-${index}`),
        schemaVersion: 1,
        order: index,
        nodeTypes: [],
        markTypes: [],
        factory: () => extension,
    })),
    toolbar: CORE_TOOLBAR,
    shortcuts: CORE_SHORTCUTS,
    commands: [
        { id: 'undo', name: 'undo', order: 1 },
        { id: 'redo', name: 'redo', order: 2 },
        { id: 'toggle_bold', name: 'toggle_bold', order: 3 },
        { id: 'toggle_italic', name: 'toggle_italic', order: 4 },
        { id: 'toggle_underline', name: 'toggle_underline', order: 5 },
        { id: 'toggle_strike', name: 'toggle_strike', order: 6 },
        { id: 'toggle_bullet_list', name: 'toggle_bullet_list', order: 7 },
        { id: 'toggle_ordered_list', name: 'toggle_ordered_list', order: 8 },
        { id: 'toggle_blockquote', name: 'toggle_blockquote', order: 9 },
        { id: 'set_horizontal_rule', name: 'set_horizontal_rule', order: 10 },
        { id: 'insert_table', name: 'insert_table', order: 11 },
        { id: 'set_text_align', name: 'set_text_align', order: 12 },
    ],
    lifecycle: {},
};
