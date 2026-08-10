import React, { useEffect, useCallback, useRef } from 'react';
import { t } from '../utils/i18n';

/**
 * Outline-locked heading block preview extracted from SeoArticleEditor.jsx
 * (Task 7 frontend extraction). Mechanical move - no behavior change.
 */
export default
function OutlineLockedHeadingBlock({ block, isSectionHeading = false, onActivate, onOutlineHeadingCommand }) {
    const lockedClickTimerRef = useRef(null);

    useEffect(
        () => () => {
            if (lockedClickTimerRef.current) {
                window.clearTimeout(lockedClickTimerRef.current);
            }
        },
        [],
    );

    const dispatchOutlineCommand = useCallback(
        (action, event) => {
            event.preventDefault();
            event.stopPropagation();
            onOutlineHeadingCommand?.(action, block);
        },
        [block, onOutlineHeadingCommand],
    );

    const sharedProps = {
        onClick: (event) => {
            if (lockedClickTimerRef.current) {
                window.clearTimeout(lockedClickTimerRef.current);
            }
            lockedClickTimerRef.current = window.setTimeout(() => {
                lockedClickTimerRef.current = null;
                onActivate?.();
                dispatchOutlineCommand('focus', event);
            }, 220);
        },
        onDoubleClick: (event) => {
            if (lockedClickTimerRef.current) {
                window.clearTimeout(lockedClickTimerRef.current);
                lockedClickTimerRef.current = null;
            }
            onActivate?.();
            dispatchOutlineCommand('edit', event);
        },
        onKeyDown: (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                dispatchOutlineCommand('focus', e);
            }
        },
        role: 'button',
        tabIndex: 0,
        title: 'Click: focus Outline · Double-click: sửa trong Outline',
    };

    if (isSectionHeading) {
        return (
            <div
                className="seo-block-preview seo-block-preview--outline-locked seo-block-preview--section-heading-only rounded border p-3 -mx-1"
                {...sharedProps}
            >
                <p className="seo-section-heading-locked-hint">{t('editor_section_heading_outline_hint')}</p>
            </div>
        );
    }

    return (
        <div
            className="seo-block-preview seo-block-preview--outline-locked seo-wp-content p-3 -mx-1 rounded border prose prose-slate max-w-none dark:prose-invert"
            dangerouslySetInnerHTML={{
                __html: block.content || `<p class="text-gray-400 italic">${t('editor_click_to_edit')}</p>`,
            }}
            {...sharedProps}
        />
    );
}

