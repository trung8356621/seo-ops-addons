import React, { useEffect, useRef, useState } from 'react';
import { executeEditorCommand } from '../utils/editorCommands';
import { canMutateEditor } from '../utils/editorSessionState';
import {
    Link2,
    Unlink,
    Code2,
    Code,
    RemoveFormatting,
    Highlighter,
    Subscript,
    Superscript,
    Trash2,
    ListTree,
    Smile,
    ChevronDown,
} from 'lucide-react';
import ParagraphStyleDropdown from './ParagraphStyleDropdown';
import EmojiPickerModal from './EmojiPickerModal';
import ContextHelpButton from './ContextHelpButton';
import { RuntimeToolbarCommandButtons } from '../editor/runtime/RuntimeToolbarCommandButtons';
import { runFaqExtractFromToolbar } from '../editor/modules/faq/faqExtractToolbarAction';
import { t } from '../utils/i18n';

const ICON_SIZE = 16;

function ToolbarButton({ onClick, onMouseDown, isActive = false, disabled = false, children, title }) {
    return (
        <button
            type="button"
            onClick={onClick}
            onMouseDown={onMouseDown}
            disabled={disabled}
            title={title}
            className={`seo-toolbar-btn${isActive ? ' is-active' : ''}${disabled ? ' is-disabled' : ''}`}
        >
            {children}
        </button>
    );
}

function ToolbarGroup({ children, className = '' }) {
    return <div className={`seo-toolbar-group${className ? ` ${className}` : ''}`}>{children}</div>;
}

function InsertActionButton({ onClick, onMouseDown, title, children, label, disabled = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            onMouseDown={onMouseDown}
            disabled={disabled}
            title={title}
            className={`seo-insert-toolbar-btn${disabled ? ' is-disabled' : ''}`}
        >
            {children}
            {label ? <span className="seo-insert-toolbar-btn__label">{label}</span> : null}
        </button>
    );
}

/**
 * Phase 6B — history/inline/lists/align/insert from runtime toolbar registry.
 * Special UI (paragraph style, link bubble open, overflow, FAQ extract, emoji, delete) stays host-local.
 */
export default function BlockFormatToolbar({
    editor,
    onDelete,
    canDelete = true,
    onEditLink,
    onViewHtml,
    runtime = null,
    showFaqExtract = true,
}) {
    const [emojiPickerOpen, setEmojiPickerOpen] = useState(false);
    const [overflowOpen, setOverflowOpen] = useState(false);
    const savedSelectionRef = useRef(null);
    const overflowRef = useRef(null);

    useEffect(() => {
        if (!overflowOpen) {
            return undefined;
        }

        const onDocMouseDown = (event) => {
            if (overflowRef.current?.contains(event.target)) {
                return;
            }
            setOverflowOpen(false);
        };

        document.addEventListener('mousedown', onDocMouseDown);
        return () => document.removeEventListener('mousedown', onDocMouseDown);
    }, [overflowOpen]);

    if (!editor) return null;

    const mutationLocked = !editor.isEditable || !canMutateEditor();
    const lockTitle = t('editor_locked_mutation_tooltip');
    const run = (name, payload = {}) => {
        if (mutationLocked) {
            return;
        }
        return executeEditorCommand(name, { editor, ...payload }, { notifyOnFailure: true });
    };

    const openLinkEditor = () => {
        if (mutationLocked) {
            return;
        }
        const { from, to } = editor.state.selection;
        if (onEditLink) {
            onEditLink({ from, to });
        }
    };

    const openEmojiPicker = () => {
        if (mutationLocked) {
            return;
        }
        const { from, to } = editor.state.selection;
        savedSelectionRef.current = { from, to };
        setEmojiPickerOpen(true);
    };

    const closeEmojiPicker = () => {
        setEmojiPickerOpen(false);
        savedSelectionRef.current = null;
    };

    const insertEmoji = (emoji) => {
        const saved = savedSelectionRef.current;
        run('insert_emoji', {
            emoji,
            from: saved?.from,
            to: saved?.to,
        });
        savedSelectionRef.current = null;
        setEmojiPickerOpen(false);
    };

    return (
        <div className="seo-block-toolbar seo-block-toolbar-rich" onMouseDown={(e) => e.preventDefault()}>
            <div className="seo-toolbar-row seo-toolbar-row--format" role="toolbar" aria-label={t('toolbar_format_aria')}>
                <ContextHelpButton contextKey="article_editor.widget.editor_toolbar" title="Editor toolbar Help" />
                <RuntimeToolbarCommandButtons
                    editor={editor}
                    groups={['history']}
                    variant="format"
                    runtime={runtime}
                />

                <ToolbarGroup>
                    <ParagraphStyleDropdown editor={editor} />
                </ToolbarGroup>

                <RuntimeToolbarCommandButtons
                    editor={editor}
                    groups={['inline', 'lists', 'align']}
                    variant="format"
                    runtime={runtime}
                />

                <ToolbarGroup>
                    <ToolbarButton
                        onClick={openLinkEditor}
                        isActive={editor.isActive('link')}
                        disabled={mutationLocked}
                        title={mutationLocked ? lockTitle : t('toolbar_insert_edit_link')}
                    >
                        <Link2 size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => run('remove_link_keep_text')}
                        disabled={mutationLocked || !editor.isActive('link')}
                        title={mutationLocked ? lockTitle : t('toolbar_unlink')}
                    >
                        <Unlink size={ICON_SIZE} />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => onViewHtml?.()}
                        disabled={!editor || !onViewHtml}
                        title={t('toolbar_view_html')}
                    >
                        <Code2 size={ICON_SIZE} />
                        <span className="seo-toolbar-btn__label">HTML</span>
                    </ToolbarButton>
                    <ContextHelpButton contextKey="article_editor.widget.html_mode" title="HTML mode Help" />
                </ToolbarGroup>

                <ToolbarGroup className="seo-toolbar-group--overflow">
                    <div className="seo-toolbar-overflow" ref={overflowRef}>
                        <ToolbarButton
                            onClick={() => setOverflowOpen((v) => !v)}
                            isActive={overflowOpen}
                            title={t('toolbar_more_format')}
                        >
                            <ChevronDown size={ICON_SIZE} />
                        </ToolbarButton>
                        {overflowOpen ? (
                            <div
                                className="seo-toolbar-overflow__menu"
                                onMouseDown={(e) => e.stopPropagation()}
                            >
                                <ToolbarButton
                                    onClick={() => {
                                        run('toggle_highlight');
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('highlight')}
                                    disabled={mutationLocked}
                                    title={mutationLocked ? lockTitle : t('toolbar_highlight')}
                                >
                                    <Highlighter size={ICON_SIZE} />
                                </ToolbarButton>
                                <ToolbarButton
                                    onClick={() => {
                                        run('toggle_subscript');
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('subscript')}
                                    disabled={mutationLocked}
                                    title={mutationLocked ? lockTitle : t('toolbar_subscript')}
                                >
                                    <Subscript size={ICON_SIZE} />
                                </ToolbarButton>
                                <ToolbarButton
                                    onClick={() => {
                                        run('toggle_superscript');
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('superscript')}
                                    disabled={mutationLocked}
                                    title={mutationLocked ? lockTitle : t('toolbar_superscript')}
                                >
                                    <Superscript size={ICON_SIZE} />
                                </ToolbarButton>
                                <label className="seo-toolbar-color-wrap" title={mutationLocked ? lockTitle : t('toolbar_text_color')}>
                                    <input
                                        type="color"
                                        className="seo-toolbar-color"
                                        disabled={mutationLocked}
                                        onChange={(e) => {
                                            run('set_color', { color: e.target.value });
                                            setOverflowOpen(false);
                                        }}
                                    />
                                </label>
                                <ToolbarButton
                                    onClick={() => {
                                        run('toggle_code');
                                        setOverflowOpen(false);
                                    }}
                                    isActive={editor.isActive('code')}
                                    disabled={mutationLocked}
                                    title={mutationLocked ? lockTitle : t('toolbar_inline_code')}
                                >
                                    <Code size={ICON_SIZE} />
                                </ToolbarButton>
                                <ToolbarButton
                                    onClick={() => {
                                        run('clear_formatting');
                                        setOverflowOpen(false);
                                    }}
                                    disabled={mutationLocked}
                                    title={mutationLocked ? lockTitle : t('toolbar_clear_format')}
                                >
                                    <RemoveFormatting size={ICON_SIZE} />
                                </ToolbarButton>
                            </div>
                        ) : null}
                    </div>
                </ToolbarGroup>

                <span className="seo-toolbar-end-actions">
                    {showFaqExtract ? (
                        <ToolbarButton
                            onClick={() => {
                                if (mutationLocked) {
                                    return;
                                }
                                void runFaqExtractFromToolbar();
                            }}
                            disabled={mutationLocked}
                            title={mutationLocked ? lockTitle : t('toolbar_extract_faq')}
                            data-runtime-toolbar="faq.toolbar.extract"
                        >
                            <ListTree size={ICON_SIZE} />
                        </ToolbarButton>
                    ) : null}

                    <button
                        type="button"
                        onMouseDown={(e) => e.preventDefault()}
                        onClick={() => {
                            if (mutationLocked || !canDelete) {
                                return;
                            }
                            onDelete?.();
                        }}
                        disabled={mutationLocked || !canDelete}
                        title={mutationLocked
                            ? lockTitle
                            : (canDelete ? t('toolbar_delete_paragraph') : t('toolbar_cannot_delete_last'))}
                        className={`seo-toolbar-btn seo-toolbar-delete${(mutationLocked || !canDelete) ? ' is-disabled' : ''}`}
                    >
                        <Trash2 size={ICON_SIZE} />
                    </button>
                </span>
            </div>

            <div className="seo-toolbar-row seo-toolbar-row--insert" role="toolbar" aria-label={t('toolbar_insert_aria')}>
                <RuntimeToolbarCommandButtons
                    editor={editor}
                    groups={['insert']}
                    variant="insert"
                    runtime={runtime}
                />
                <InsertActionButton
                    onMouseDown={(e) => {
                        e.preventDefault();
                        openEmojiPicker();
                    }}
                    disabled={mutationLocked}
                    title={mutationLocked ? lockTitle : t('toolbar_insert_emoji')}
                    label={t('toolbar_insert_emoji_short')}
                >
                    <Smile size={ICON_SIZE} />
                </InsertActionButton>
            </div>

            <EmojiPickerModal
                open={emojiPickerOpen}
                onClose={closeEmojiPicker}
                onSelect={insertEmoji}
            />
        </div>
    );
}
