import React, { useState, useRef, useEffect, useLayoutEffect } from 'react';
import { createPortal } from 'react-dom';
import { ChevronDown } from 'lucide-react';
import { executeEditorCommand } from '../utils/editorCommands';
import { canMutateEditor } from '../utils/editorSessionState';
import { t } from '../utils/i18n';

const STYLES = [
    { value: 'p', label: t('style_paragraph'), previewClass: 'seo-fmt-preview-p' },
    { value: 'h1', label: t('style_heading_1'), previewClass: 'seo-fmt-preview-h1' },
    { value: 'h2', label: t('style_heading_2'), previewClass: 'seo-fmt-preview-h2' },
    { value: 'h3', label: t('style_heading_3'), previewClass: 'seo-fmt-preview-h3' },
    { value: 'h4', label: t('style_heading_4'), previewClass: 'seo-fmt-preview-h4' },
    { value: 'h5', label: t('style_heading_5'), previewClass: 'seo-fmt-preview-h5' },
    { value: 'h6', label: t('style_heading_6'), previewClass: 'seo-fmt-preview-h6' },
    { value: 'pre', label: t('style_preformatted'), previewClass: 'seo-fmt-preview-pre' },
];

function getActiveStyle(editor) {
    if (editor.isActive('codeBlock')) return 'pre';
    for (let level = 1; level <= 6; level += 1) {
        if (editor.isActive('heading', { level })) return `h${level}`;
    }
    return 'p';
}

function applyStyle(editor, value) {
    if (!canMutateEditor()) {
        return;
    }
    executeEditorCommand('set_paragraph_style', { editor, value }, { notifyOnFailure: true });
}

/**
 * Menu portals to document.body — parent toolbar uses overflow-x:auto which
 * otherwise clips absolute dropdowns into a broken "tab" overflow.
 */
export default function ParagraphStyleDropdown({ editor }) {
    const [open, setOpen] = useState(false);
    const [menuStyle, setMenuStyle] = useState(null);
    const rootRef = useRef(null);
    const menuRef = useRef(null);
    const mutationLocked = !editor?.isEditable || !canMutateEditor();
    const lockTitle = t('editor_locked_mutation_tooltip');

    const activeValue = getActiveStyle(editor);
    const activeLabel = STYLES.find((s) => s.value === activeValue)?.label ?? t('style_paragraph');

    useLayoutEffect(() => {
        if (!open || !rootRef.current) {
            setMenuStyle(null);
            return undefined;
        }

        const place = () => {
            const trigger = rootRef.current?.querySelector('.seo-fmt-dropdown-trigger');
            if (!trigger) {
                return;
            }
            const rect = trigger.getBoundingClientRect();
            const menuHeight = menuRef.current?.offsetHeight || 280;
            const gap = 4;
            const spaceBelow = window.innerHeight - rect.bottom - gap;
            const openUp = spaceBelow < menuHeight && rect.top > spaceBelow;
            const top = openUp
                ? Math.max(8, rect.top - menuHeight - gap)
                : Math.min(window.innerHeight - menuHeight - 8, rect.bottom + gap);
            const width = Math.max(260, rect.width);
            let left = rect.left;
            if (left + width > window.innerWidth - 8) {
                left = Math.max(8, window.innerWidth - width - 8);
            }

            setMenuStyle({
                position: 'fixed',
                top: `${Math.round(top)}px`,
                left: `${Math.round(left)}px`,
                minWidth: `${Math.round(width)}px`,
                zIndex: 10050,
            });
        };

        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);
        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [open]);

    useEffect(() => {
        if (!open) return undefined;
        const onDocClick = (e) => {
            if (rootRef.current?.contains(e.target) || menuRef.current?.contains(e.target)) {
                return;
            }
            setOpen(false);
        };
        document.addEventListener('mousedown', onDocClick);
        return () => document.removeEventListener('mousedown', onDocClick);
    }, [open]);

    const menu = open && typeof document !== 'undefined'
        ? createPortal(
            <div
                ref={menuRef}
                className="seo-fmt-dropdown-menu seo-fmt-dropdown-menu--portal"
                role="listbox"
                style={menuStyle || {
                    position: 'fixed',
                    top: 0,
                    left: 0,
                    visibility: 'hidden',
                    pointerEvents: 'none',
                    zIndex: 10050,
                }}
                onMouseDown={(e) => e.stopPropagation()}
            >
                {STYLES.map((style) => (
                    <button
                        key={style.value}
                        type="button"
                        role="option"
                        aria-selected={activeValue === style.value}
                        className={`seo-fmt-dropdown-item${activeValue === style.value ? ' is-active' : ''}`}
                        onClick={() => {
                            applyStyle(editor, style.value);
                            setOpen(false);
                        }}
                    >
                        <span className={style.previewClass}>{style.label}</span>
                    </button>
                ))}
            </div>,
            document.body,
        )
        : null;

    return (
        <div ref={rootRef} className={`seo-fmt-dropdown${mutationLocked ? ' is-disabled' : ''}`}>
            <button
                type="button"
                className="seo-fmt-dropdown-trigger"
                onClick={() => {
                    if (mutationLocked) {
                        return;
                    }
                    setOpen((v) => !v);
                }}
                disabled={mutationLocked}
                title={mutationLocked ? lockTitle : t('style_block_type')}
                aria-expanded={open}
            >
                <span className="seo-fmt-dropdown-label">{activeLabel}</span>
                <ChevronDown size={14} className={`seo-fmt-dropdown-chevron${open ? ' is-open' : ''}`} />
            </button>
            {menu}
        </div>
    );
}
