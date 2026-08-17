import React, { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    ChevronRight,
    Eye,
    EyeOff,
    Heading2,
    Heading3,
    Heading4,
    Link2,
    List,
    ListOrdered,
    Pencil,
    Plus,
    RemoveFormatting,
    Scissors,
    Trash2,
    Type,
    Unlink,
} from 'lucide-react';
import { executeEditorCommand } from '../utils/editorCommands';
import { searchInternalLinkArticlesCached } from '../utils/internalLinkArticleSearch';
import {
    applyContextMenuSelection,
    clampMenuPosition,
    CONTEXT_MENU_COMMANDS as CMD,
    CONTEXT_MENU_ROOT_ATTR,
    eventPathContainsMenu,
} from '../utils/editorContextMenuController';
import { t } from '../utils/i18n';

const SEARCH_DEBOUNCE_MS = 280;
const ICON = 15;
const MENU_WIDTH = 252;

function trace(event, data) {
    if (typeof window === 'undefined' || !window.__SEO_DEBUG_CTX_MENU__) {
        return;
    }
    // eslint-disable-next-line no-console
    console.debug('[ctx-menu]', event, data);
}

function MenuItem({
    icon: Icon,
    label,
    shortcut = null,
    disabled = false,
    danger = false,
    submenu = false,
    onSelect,
}) {
    return (
        <button
            type="button"
            role="menuitem"
            tabIndex={-1}
            disabled={disabled}
            className={`seo-editor-context-menu__item${danger ? ' is-danger' : ''}${disabled ? ' is-disabled' : ''}`}
            onMouseDown={(event) => {
                event.preventDefault();
                event.stopPropagation();
                if (event.button !== 0 || disabled) {
                    return;
                }
                onSelect?.();
            }}
        >
            <span className="seo-editor-context-menu__icon" aria-hidden="true">
                {Icon ? <Icon size={ICON} strokeWidth={1.75} /> : null}
            </span>
            <span className="seo-editor-context-menu__label">{label}</span>
            {shortcut ? <kbd className="seo-editor-context-menu__shortcut">{shortcut}</kbd> : null}
            {submenu ? <ChevronRight size={14} className="seo-editor-context-menu__chevron" aria-hidden="true" /> : null}
        </button>
    );
}

function Submenu({ icon, label, flipLeft, children }) {
    const [open, setOpen] = useState(false);

    return (
        <div
            className={`seo-editor-context-menu__submenu${open ? ' is-open' : ''}`}
            onMouseEnter={() => setOpen(true)}
            onMouseLeave={() => setOpen(false)}
        >
            <MenuItem
                icon={icon}
                label={label}
                submenu
                onSelect={() => setOpen((current) => !current)}
            />
            {open ? (
                <div className={`seo-editor-context-menu__flyout${flipLeft ? ' is-left' : ''}`} role="menu">
                    {children}
                </div>
            ) : null}
        </div>
    );
}

function Separator() {
    return <div className="seo-editor-context-menu__sep" role="separator" />;
}

export default function EditorContextMenu({
    editor,
    snapshot = null,
    siteId = 0,
    articleId = 0,
    open,
    x,
    y,
    onClose,
}) {
    const rootRef = useRef(null);
    const ranRef = useRef(false);
    const [coords, setCoords] = useState({ left: x, top: y, flipSubmenuLeft: false });
    const [renameOpen, setRenameOpen] = useState(false);
    const [renameDraft, setRenameDraft] = useState('');
    const [linkOpen, setLinkOpen] = useState(false);
    const [linkQuery, setLinkQuery] = useState('');
    const [linkResults, setLinkResults] = useState([]);
    const [linkLoading, setLinkLoading] = useState(false);
    const searchTimerRef = useRef(null);

    const hasRange = Boolean(snapshot && snapshot.empty === false);
    const isHeading = snapshot?.nodeName === 'heading';
    const inLink = Boolean(snapshot?.inLink);

    useEffect(() => {
        ranRef.current = false;
        setRenameOpen(false);
        setLinkOpen(false);
        setLinkQuery('');
        setLinkResults([]);
        setCoords({ left: x, top: y, flipSubmenuLeft: false });
    }, [open, x, y, snapshot]);

    useLayoutEffect(() => {
        if (!open || !rootRef.current) {
            return;
        }
        const rect = rootRef.current.getBoundingClientRect();
        setCoords(clampMenuPosition(x, y, rect.width || MENU_WIDTH, rect.height, {
            vw: window.innerWidth,
            vh: window.innerHeight,
            pad: 8,
        }));
    }, [open, x, y, renameOpen, linkOpen]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose?.();
            }
        };
        const onPointer = (event) => {
            if (eventPathContainsMenu(event, rootRef.current)) {
                return;
            }
            onClose?.();
        };

        const timer = window.setTimeout(() => {
            document.addEventListener('keydown', onKey);
            document.addEventListener('pointerdown', onPointer, true);
        }, 0);

        return () => {
            window.clearTimeout(timer);
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('pointerdown', onPointer, true);
        };
    }, [open, onClose]);

    const runMapped = useCallback((mapping, extra) => {
        if (!editor || editor.isDestroyed || !snapshot || ranRef.current || !mapping) {
            return;
        }
        ranRef.current = true;
        applyContextMenuSelection(editor, snapshot);
        const args = mapping.args(snapshot, extra) ?? {};
        trace('run', { command: mapping.name, args, blockId: snapshot.blockId });
        const result = executeEditorCommand(mapping.name, {
            editor,
            editorId: snapshot.blockId,
            ...args,
        }, { notifyOnFailure: true });
        trace('result', {
            ok: result?.ok,
            code: result?.code,
            document_changed: result?.document_changed,
            transaction_applied: result?.transaction_applied,
        });
        if (!result?.ok || result.document_changed !== true) {
            ranRef.current = false;
            return;
        }
        onClose?.();
    }, [editor, onClose, snapshot]);

    const searchLinks = useCallback((phrase) => {
        if (searchTimerRef.current) {
            window.clearTimeout(searchTimerRef.current);
        }
        const trimmed = String(phrase ?? '').trim();
        if (trimmed.length < 2) {
            setLinkResults([]);
            setLinkLoading(false);
            return;
        }
        setLinkLoading(true);
        searchTimerRef.current = window.setTimeout(() => {
            searchInternalLinkArticlesCached(trimmed, { siteId, articleId, limit: 8 })
                .then((rows) => setLinkResults(Array.isArray(rows) ? rows : []))
                .catch(() => setLinkResults([]))
                .finally(() => setLinkLoading(false));
        }, SEARCH_DEBOUNCE_MS);
    }, [articleId, siteId]);

    const applyHref = useCallback((href) => {
        const next = String(href ?? '').trim();
        if (next === '') {
            return;
        }
        runMapped(inLink ? CMD.updateLink : CMD.createLink, next);
    }, [inLink, runMapped]);

    const commitRename = useCallback(() => {
        const text = String(renameDraft ?? '').replace(/\s+/g, ' ').trim();
        if (text === '') {
            return;
        }
        runMapped(CMD.renameHeading, text);
    }, [renameDraft, runMapped]);

    if (!open || !editor || !snapshot) {
        return null;
    }

    const changeHeading = (level) => {
        if (isHeading && snapshot.headingIndex != null) {
            runMapped(CMD.changeHeadingLevel, level);
            return;
        }
        const fallback = level === 2 ? CMD.changeH2 : (level === 4 ? CMD.changeH4 : CMD.changeH3);
        runMapped(fallback);
    };

    const menu = (
        <div
            ref={rootRef}
            className="seo-editor-context-menu"
            style={{ top: coords.top, left: coords.left, width: MENU_WIDTH }}
            role="menu"
            {...{ [CONTEXT_MENU_ROOT_ATTR]: '1' }}
            onContextMenu={(event) => event.preventDefault()}
            onMouseDown={(event) => event.stopPropagation()}
        >
            {hasRange ? (
                <>
                    <MenuItem
                        icon={Scissors}
                        label={t('ctx_split_paragraph')}
                        disabled={!snapshot.canSplitParagraph}
                        onSelect={() => runMapped(CMD.splitParagraph)}
                    />
                    <MenuItem icon={Heading3} label={t('ctx_split_h3')} shortcut="Alt+3" onSelect={() => runMapped(CMD.splitH3)} />
                    <MenuItem icon={Heading4} label={t('ctx_split_h4')} shortcut="Alt+4" onSelect={() => runMapped(CMD.splitH4)} />
                </>
            ) : isHeading ? (
                <>
                    <MenuItem
                        icon={Pencil}
                        label={t('outline_edit_manual')}
                        onSelect={() => {
                            setRenameOpen(true);
                            setRenameDraft('');
                        }}
                    />
                    {renameOpen ? (
                        <input
                            type="text"
                            className="seo-editor-context-menu__input"
                            autoFocus
                            placeholder={t('outline_html_placeholder')}
                            value={renameDraft}
                            onChange={(event) => setRenameDraft(event.target.value)}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    commitRename();
                                }
                            }}
                            onMouseDown={(event) => event.stopPropagation()}
                        />
                    ) : null}
                    <Submenu icon={Type} label={t('ctx_change_type')} flipLeft={coords.flipSubmenuLeft}>
                        {snapshot.headingLevel !== 2 ? (
                            <MenuItem icon={Heading2} label={t('outline_change_h2')} onSelect={() => changeHeading(2)} />
                        ) : null}
                        {snapshot.headingLevel !== 3 ? (
                            <MenuItem icon={Heading3} label={t('outline_change_h3')} onSelect={() => changeHeading(3)} />
                        ) : null}
                        {snapshot.headingLevel !== 4 ? (
                            <MenuItem icon={Heading4} label={t('outline_change_h4')} onSelect={() => changeHeading(4)} />
                        ) : null}
                    </Submenu>
                    <MenuItem
                        icon={Plus}
                        label={t('outline_add_h3_child')}
                        onSelect={() => runMapped(CMD.insertHeadingAfter, { level: 3, text: t('editor_new_section_heading') })}
                    />
                </>
            ) : (
                <>
                    <MenuItem
                        icon={Scissors}
                        label={t('ctx_split_at_cursor')}
                        disabled={!snapshot.canSplitCursor}
                        onSelect={() => runMapped(CMD.splitAtCursor)}
                    />
                    <MenuItem icon={Heading3} label={t('ctx_change_h3')} onSelect={() => runMapped(CMD.changeH3)} />
                    <MenuItem icon={Heading4} label={t('ctx_change_h4')} onSelect={() => runMapped(CMD.changeH4)} />
                </>
            )}

            <Separator />

            <Submenu icon={List} label={t('ctx_list')} flipLeft={coords.flipSubmenuLeft}>
                <MenuItem icon={List} label={t('ctx_list_bullet')} onSelect={() => runMapped(CMD.bulletList)} />
                <MenuItem icon={ListOrdered} label={t('ctx_list_ordered')} onSelect={() => runMapped(CMD.orderedList)} />
            </Submenu>

            <MenuItem
                icon={Link2}
                label={inLink ? t('ctx_edit_link') : t('ctx_add_link')}
                onSelect={() => {
                    setLinkOpen(true);
                    setLinkQuery('');
                }}
            />
            {inLink ? (
                <MenuItem icon={Unlink} label={t('ctx_remove_link')} onSelect={() => runMapped(CMD.removeLink)} />
            ) : null}
            <MenuItem icon={RemoveFormatting} label={t('ctx_clear_formatting')} onSelect={() => runMapped(CMD.clearFormatting)} />

            {linkOpen ? (
                <div className="seo-editor-context-menu__link">
                    <input
                        type="text"
                        className="seo-editor-context-menu__input"
                        autoFocus
                        value={linkQuery}
                        placeholder={t('ctx_link_placeholder')}
                        onChange={(event) => {
                            setLinkQuery(event.target.value);
                            searchLinks(event.target.value);
                        }}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                applyHref(linkQuery);
                            }
                        }}
                        onMouseDown={(event) => event.stopPropagation()}
                    />
                    {linkLoading ? (
                        <div className="seo-editor-context-menu__hint">{t('ctx_link_searching')}</div>
                    ) : null}
                    {linkResults.map((row) => (
                        <button
                            key={`${row.id}-${row.url}`}
                            type="button"
                            className="seo-editor-context-menu__result"
                            onMouseDown={(event) => {
                                event.preventDefault();
                                event.stopPropagation();
                                applyHref(row.url);
                            }}
                        >
                            <span>{row.label || row.title}</span>
                            <small>{row.url}</small>
                        </button>
                    ))}
                    <MenuItem icon={Link2} label={t('ctx_link_use_url')} onSelect={() => applyHref(linkQuery)} />
                </div>
            ) : null}

            {isHeading ? (
                <>
                    <Separator />
                    <MenuItem
                        icon={snapshot.outlineVisible ? EyeOff : Eye}
                        label={snapshot.outlineVisible ? t('outline_hide_from_outline') : t('ctx_show_in_outline')}
                        onSelect={() => runMapped(CMD.hideOutline, !snapshot.outlineVisible)}
                    />
                    <MenuItem
                        icon={Trash2}
                        label={t('outline_delete_keep_content')}
                        onSelect={() => runMapped(CMD.deleteKeep)}
                    />
                    <MenuItem
                        icon={Trash2}
                        danger
                        label={t('outline_delete_with_content')}
                        onSelect={() => {
                            if (!window.confirm(t('outline_delete_with_content_confirm', { heading: '' }))) {
                                return;
                            }
                            runMapped(CMD.deleteWith);
                        }}
                    />
                </>
            ) : null}
        </div>
    );

    return createPortal(menu, document.body);
}
