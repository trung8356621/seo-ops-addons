import React from 'react';
import { CircleHelp } from 'lucide-react';

export const GLOBAL_HELP_OPEN_EVENT = 'seo-global-help:open';

/**
 * Open Global Help modal on a context key (Markdown topic).
 * Never throws — Help failure must not break Article Editor.
 *
 * @param {string} contextKey
 * @param {Element|null} [trigger]
 */
export function openContextualHelp(contextKey, trigger = null) {
    const key = String(contextKey || '').trim();
    if (key === '') {
        return;
    }

    try {
        const mapped = window.__SEO_HELP_PAYLOAD__?.topic_by_key?.[key];
        if (!mapped) {
            console.warn('[Help] Missing context:', key);
        }
        window.dispatchEvent(
            new CustomEvent(GLOBAL_HELP_OPEN_EVENT, {
                detail: {
                    contextKey: key,
                    trigger: trigger instanceof Element ? trigger : undefined,
                },
            }),
        );
    } catch (error) {
        console.warn('[Help] Failed to open context:', key, error);
    }
}

/**
 * Compact contextual Help affordance for Article Editor widgets/panels.
 *
 * @param {{
 *   contextKey: string,
 *   className?: string,
 *   title?: string,
 * }} props
 */
export default function ContextHelpButton({
    contextKey,
    className = '',
    title = 'Help',
}) {
    const key = String(contextKey || '').trim();
    if (key === '') {
        return null;
    }

    return (
        <button
            type="button"
            className={`seo-context-help-btn${className ? ` ${className}` : ''}`}
            title={title}
            aria-label={title}
            data-help-context={key}
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                openContextualHelp(key, event.currentTarget);
            }}
            onMouseDown={(event) => {
                // Keep toolbar / header focus behavior intact.
                event.preventDefault();
                event.stopPropagation();
            }}
        >
            <CircleHelp size={16} strokeWidth={1.75} aria-hidden />
        </button>
    );
}
