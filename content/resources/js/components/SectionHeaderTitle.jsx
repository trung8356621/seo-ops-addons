import React, { useState, useEffect, useCallback, useRef } from 'react';
import { t } from '../utils/i18n';

/**
 * Editable H3 section header title extracted from SeoArticleEditor.jsx
 * (Task 7 frontend extraction). Mechanical move - no behavior change.
 */
export default
function SectionHeaderTitle({ sectionNumber, title, onSave, onFocusOutline, autoEditToken = 0 }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(title);
    const inputRef = useRef(null);
    const clickTimerRef = useRef(null);

    useEffect(
        () => () => {
            if (clickTimerRef.current) {
                window.clearTimeout(clickTimerRef.current);
            }
        },
        [],
    );

    useEffect(() => {
        if (!autoEditToken) {
            return;
        }

        setEditing(true);
    }, [autoEditToken]);

    useEffect(() => {
        if (!editing) {
            setDraft(title);
        }
    }, [title, editing]);

    useEffect(() => {
        if (!editing || !inputRef.current) {
            return;
        }

        inputRef.current.focus();
        inputRef.current.select();
    }, [editing]);

    const commit = useCallback(() => {
        const next = draft.replace(/\s+/g, ' ').trim();
        setEditing(false);

        if (next === '' || next === title) {
            setDraft(title);
            return;
        }

        onSave?.(next);
    }, [draft, onSave, title]);

    const handleTitleClick = useCallback(
        (event) => {
            event.stopPropagation();
            if (editing) {
                return;
            }

            if (clickTimerRef.current) {
                window.clearTimeout(clickTimerRef.current);
            }

            clickTimerRef.current = window.setTimeout(() => {
                clickTimerRef.current = null;
                onFocusOutline?.();
            }, 220);
        },
        [editing, onFocusOutline],
    );

    const handleTitleDoubleClick = useCallback((event) => {
        event.stopPropagation();
        if (clickTimerRef.current) {
            window.clearTimeout(clickTimerRef.current);
            clickTimerRef.current = null;
        }
        setEditing(true);
    }, []);

    return (
        <h3 className="seo-section-header-title min-w-0 truncate text-sm font-semibold text-gray-700 dark:text-gray-200">
            <span
                className="seo-section-header-title__prefix cursor-pointer"
                onClick={handleTitleClick}
            >
                {`Section ${sectionNumber}: `}
            </span>
            {editing ? (
                <input
                    ref={inputRef}
                    type="text"
                    className="seo-section-header-title__input"
                    value={draft}
                    maxLength={255}
                    onChange={(event) => setDraft(event.target.value)}
                    onBlur={commit}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            commit();
                        }
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            setDraft(title);
                            setEditing(false);
                        }
                    }}
                    onClick={(event) => event.stopPropagation()}
                />
            ) : (
                <span
                    className="seo-section-header-title__text"
                    onClick={handleTitleClick}
                    onDoubleClick={handleTitleDoubleClick}
                    title={t('editor_section_title_edit_hint')}
                >
                    {title}
                </span>
            )}
        </h3>
    );
}

