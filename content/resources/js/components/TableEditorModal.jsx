import React, { useEffect, useMemo } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Italic,
    Link as LinkIcon,
    Plus,
    Minus,
    Rows3,
    Columns3,
    Heading,
} from 'lucide-react';
import { t } from '../utils/i18n';
import { tableEditorExtensions } from '../utils/tableEditorExtensions';
import { extractSingleTableHtml, withPrimaryTableAttr, isPrimaryTableHtml } from '../utils/articleTableManager';
import { TIPTAP_HTML_PARSE_OPTIONS } from '../utils/inlineWhitespaceGuard';

const FALLBACK_TABLE_HTML = '<table><thead><tr><th></th><th></th><th></th></tr></thead><tbody><tr><td></td><td></td><td></td></tr><tr><td></td><td></td><td></td></tr></tbody></table>';

function ToolbarButton({ title, active = false, disabled = false, onClick, children }) {
    return (
        <button
            type="button"
            className={`seo-table-editor-toolbar__btn${active ? ' is-active' : ''}`}
            title={title}
            disabled={disabled}
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
        >
            {children}
        </button>
    );
}

function normalizeTableEditorHtml(html) {
    const extracted = extractSingleTableHtml(html) || String(html ?? '').trim();
    return extracted || FALLBACK_TABLE_HTML;
}

function safeSetTableContent(editor, html) {
    if (!editor || editor.isDestroyed) {
        return false;
    }
    const next = normalizeTableEditorHtml(html);
    try {
        editor.commands.setContent(next, {
            emitUpdate: false,
            parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
        });
        return true;
    } catch {
        try {
            editor.commands.setContent(FALLBACK_TABLE_HTML, {
                emitUpdate: false,
                parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
            });
        } catch {
            // ignore — UI still shows empty editor rather than crashing the page
        }
        return false;
    }
}

/**
 * Dedicated TipTap Table Editor — Save/Cancel only; does not mutate parent until Save.
 */
export default function TableEditorModal({ open, html, onClose, onSave }) {
    const initialHtml = useMemo(() => normalizeTableEditorHtml(html), [html]);
    const wasPrimary = useMemo(() => isPrimaryTableHtml(initialHtml), [initialHtml]);

    const editorProps = useMemo(() => ({
        attributes: {
            class: 'seo-table-editor-prose seo-wp-content tiptap-editor-content prose prose-sm max-w-none dark:prose-invert',
        },
    }), []);

    const editor = useEditor({
        extensions: tableEditorExtensions,
        content: FALLBACK_TABLE_HTML,
        editable: true,
        immediatelyRender: false,
        parseOptions: TIPTAP_HTML_PARSE_OPTIONS,
        editorProps,
    });

    useEffect(() => {
        if (!open || !editor) {
            return;
        }
        safeSetTableContent(editor, initialHtml);
        requestAnimationFrame(() => {
            try {
                editor.commands.focus('start');
            } catch {
                // ignore
            }
        });
    }, [open, editor, initialHtml]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKeyDown = (e) => {
            if (e.key === 'Escape') {
                onClose?.();
            }
        };
        document.addEventListener('keydown', onKeyDown);
        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    const handleSave = () => {
        if (!editor) {
            return;
        }
        const raw = editor.getHTML();
        const table = extractSingleTableHtml(raw);
        if (!table) {
            return;
        }
        onSave?.(withPrimaryTableAttr(table, wasPrimary));
    };

    const setLink = () => {
        if (!editor) {
            return;
        }
        const previous = editor.getAttributes('link').href || '';
        const url = window.prompt(t('table_editor_link_prompt'), previous);
        if (url === null) {
            return;
        }
        const trimmed = String(url).trim();
        if (trimmed === '') {
            editor.chain().focus().extendMarkRange('link').unsetLink().run();
            return;
        }
        editor.chain().focus().extendMarkRange('link').setLink({ href: trimmed }).run();
    };

    return (
        <div className="seo-table-manager-modal" role="dialog" aria-modal="true">
            <div className="seo-table-manager-modal__backdrop" onClick={onClose} />
            <div className="seo-table-manager-modal__panel seo-table-editor-modal">
                <div className="seo-table-manager-modal__head">
                    <h3>{t('table_manager_edit_title')}</h3>
                    <button type="button" onClick={onClose}>{t('close')}</button>
                </div>

                <div className="seo-table-editor-toolbar" role="toolbar" aria-label={t('table_manager_edit_title')}>
                    <ToolbarButton
                        title={t('toolbar_bold')}
                        active={editor?.isActive('bold')}
                        onClick={() => editor?.chain().focus().toggleBold().run()}
                    >
                        <Bold size={15} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('toolbar_italic')}
                        active={editor?.isActive('italic')}
                        onClick={() => editor?.chain().focus().toggleItalic().run()}
                    >
                        <Italic size={15} />
                    </ToolbarButton>
                    <ToolbarButton title={t('table_editor_link_prompt')} active={editor?.isActive('link')} onClick={setLink}>
                        <LinkIcon size={15} />
                    </ToolbarButton>
                    <span className="seo-table-editor-toolbar__sep" />
                    <ToolbarButton
                        title={t('toolbar_align_left')}
                        active={editor?.isActive({ textAlign: 'left' })}
                        onClick={() => editor?.chain().focus().setTextAlign('left').run()}
                    >
                        <AlignLeft size={15} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('toolbar_align_center')}
                        active={editor?.isActive({ textAlign: 'center' })}
                        onClick={() => editor?.chain().focus().setTextAlign('center').run()}
                    >
                        <AlignCenter size={15} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('toolbar_align_right')}
                        active={editor?.isActive({ textAlign: 'right' })}
                        onClick={() => editor?.chain().focus().setTextAlign('right').run()}
                    >
                        <AlignRight size={15} />
                    </ToolbarButton>
                    <span className="seo-table-editor-toolbar__sep" />
                    <ToolbarButton
                        title={t('table_editor_add_row_before')}
                        onClick={() => editor?.chain().focus().addRowBefore().run()}
                    >
                        <Rows3 size={15} />
                        <Plus size={11} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('table_editor_add_row_after')}
                        onClick={() => editor?.chain().focus().addRowAfter().run()}
                    >
                        <Rows3 size={15} />
                        <Plus size={11} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('table_editor_delete_row')}
                        onClick={() => editor?.chain().focus().deleteRow().run()}
                    >
                        <Rows3 size={15} />
                        <Minus size={11} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('table_editor_add_column_before')}
                        onClick={() => editor?.chain().focus().addColumnBefore().run()}
                    >
                        <Columns3 size={15} />
                        <Plus size={11} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('table_editor_add_column_after')}
                        onClick={() => editor?.chain().focus().addColumnAfter().run()}
                    >
                        <Columns3 size={15} />
                        <Plus size={11} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('table_editor_delete_column')}
                        onClick={() => editor?.chain().focus().deleteColumn().run()}
                    >
                        <Columns3 size={15} />
                        <Minus size={11} />
                    </ToolbarButton>
                    <ToolbarButton
                        title={t('table_editor_toggle_header')}
                        onClick={() => editor?.chain().focus().toggleHeaderRow().run()}
                    >
                        <Heading size={15} />
                    </ToolbarButton>
                </div>

                <div className="seo-table-manager-modal__body seo-table-editor-body">
                    {editor ? <EditorContent editor={editor} /> : null}
                </div>

                <div className="seo-table-manager-modal__actions flex justify-end gap-2">
                    <button type="button" className="rounded px-3 py-1.5 text-sm" onClick={onClose}>
                        {t('cancel')}
                    </button>
                    <button
                        type="button"
                        className="rounded bg-primary-600 px-3 py-1.5 text-sm text-white"
                        onClick={handleSave}
                    >
                        {t('table_manager_save_edit')}
                    </button>
                </div>
            </div>
        </div>
    );
}
