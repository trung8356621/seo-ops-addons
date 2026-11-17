import React, { useRef, useState } from 'react';
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
    Scissors,
} from 'lucide-react';
import ParagraphStyleDropdown from './ParagraphStyleDropdown';
import EmojiPickerModal from './EmojiPickerModal';
import ContextHelpButton from './ContextHelpButton';
import { RuntimeToolbarCommandButtons } from '../editor/runtime/RuntimeToolbarCommandButtons';
import { runFaqExtractFromToolbar } from '../editor/modules/faq/faqExtractToolbarAction';
import { canSplitParagraphBySentences } from '../utils/editorCommands/sentenceParagraphSplit.js';
import { t } from '../utils/i18n';

const ICON_SIZE = 18;

function ToolbarButton({
    onClick,
    onMouseDown,
    isActive = false,
    disabled = false,
    children,
    title,
    className = '',
    ...rest
}) {
    const classes = [
        'seo-toolbar-btn',
        isActive ? 'is-active' : '',
        disabled ? 'is-disabled' : '',
        className,
    ].filter(Boolean).join(' ');

    return (
        <button
            type="button"
            onClick={onClick}
            onMouseDown={onMouseDown}
            disabled={disabled}
            title={title}
            aria-label={title}
            className={classes}
            {...rest}
        >
            {children}
        </button>
    );
}

function ToolbarGroup({ children, className = '' }) {
    return <div className={`seo-toolbar-group${className ? ` ${className}` : ''}`}>{children}</div>;
}

/**
 * Unified TipTap toolbar — every action uses icon-only seo-toolbar-btn.
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
    const savedSelectionRef = useRef(null);

    if (!editor) return null;

    const mutationLocked = !editor.isEditable || !canMutateEditor();
    const lockTitle = t('editor_locked_mutation_tooltip');
    const sentenceSplit = canSplitParagraphBySentences(editor.state);
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
                        <Link2 size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => run('remove_link_keep_text')}
                        disabled={mutationLocked || !editor.isActive('link')}
                        title={mutationLocked ? lockTitle : t('toolbar_unlink')}
                    >
                        <Unlink size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => onViewHtml?.()}
                        disabled={!editor || !onViewHtml}
                        title={t('toolbar_view_html')}
                    >
                        <Code2 size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <ContextHelpButton contextKey="article_editor.widget.html_mode" title="HTML mode Help" />
                </ToolbarGroup>

                <ToolbarGroup className="seo-toolbar-group--extra-format">
                    <ToolbarButton
                        onClick={() => run('toggle_highlight')}
                        isActive={editor.isActive('highlight')}
                        disabled={mutationLocked}
                        title={mutationLocked ? lockTitle : t('toolbar_highlight')}
                    >
                        <Highlighter size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => run('toggle_subscript')}
                        isActive={editor.isActive('subscript')}
                        disabled={mutationLocked}
                        title={mutationLocked ? lockTitle : t('toolbar_subscript')}
                    >
                        <Subscript size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => run('toggle_superscript')}
                        isActive={editor.isActive('superscript')}
                        disabled={mutationLocked}
                        title={mutationLocked ? lockTitle : t('toolbar_superscript')}
                    >
                        <Superscript size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <label className="seo-toolbar-color-wrap" title={mutationLocked ? lockTitle : t('toolbar_text_color')}>
                        <input
                            type="color"
                            className="seo-toolbar-color"
                            disabled={mutationLocked}
                            onChange={(e) => run('set_color', { color: e.target.value })}
                        />
                    </label>
                    <ToolbarButton
                        onClick={() => run('toggle_code')}
                        isActive={editor.isActive('code')}
                        disabled={mutationLocked}
                        title={mutationLocked ? lockTitle : t('toolbar_inline_code')}
                    >
                        <Code size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => run('clear_formatting')}
                        disabled={mutationLocked}
                        title={mutationLocked ? lockTitle : t('toolbar_clear_format')}
                    >
                        <RemoveFormatting size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                    <ToolbarButton
                        onClick={() => run('split_paragraph_by_sentences')}
                        disabled={mutationLocked || !sentenceSplit.canSplit}
                        title={
                            mutationLocked
                                ? lockTitle
                                : (sentenceSplit.canSplit
                                    ? t('ctx_split_by_sentences')
                                    : t('ctx_split_by_sentences_unavailable'))
                        }
                    >
                        <Scissors size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
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
                            title={mutationLocked ? lockTitle : t('faq_extract')}
                            data-runtime-toolbar="faq.toolbar.extract"
                        >
                            <ListTree size={ICON_SIZE} aria-hidden />
                        </ToolbarButton>
                    ) : null}

                    <ToolbarButton
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
                        className="seo-toolbar-delete"
                    >
                        <Trash2 size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                </span>
            </div>

            <div className="seo-toolbar-row seo-toolbar-row--insert" role="toolbar" aria-label={t('toolbar_insert_aria')}>
                <RuntimeToolbarCommandButtons
                    editor={editor}
                    groups={['insert']}
                    variant="format"
                    runtime={runtime}
                />
                <ToolbarGroup>
                    <ToolbarButton
                        onMouseDown={(e) => {
                            e.preventDefault();
                            openEmojiPicker();
                        }}
                        disabled={mutationLocked}
                        title={mutationLocked ? lockTitle : t('toolbar_insert_emoji')}
                    >
                        <Smile size={ICON_SIZE} aria-hidden />
                    </ToolbarButton>
                </ToolbarGroup>
            </div>

            <EmojiPickerModal
                open={emojiPickerOpen}
                onClose={closeEmojiPicker}
                onSelect={insertEmoji}
            />
        </div>
    );
}
