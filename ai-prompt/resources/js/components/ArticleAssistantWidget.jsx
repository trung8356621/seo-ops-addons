import React, { useEffect, useRef, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';

/**
 * @param {{
 *   widgetId?: string,
 *   title: string,
 *   icon?: React.ComponentType<{ size?: number, className?: string, 'aria-hidden'?: boolean }>,
 *   badge?: string|number|null,
 *   defaultCollapsed?: boolean,
 *   collapsible?: boolean,
 *   className?: string,
 *   children: React.ReactNode,
 * }} props
 */
export default function ArticleAssistantWidget({
    widgetId = '',
    title,
    icon: Icon,
    badge = null,
    defaultCollapsed = false,
    collapsible = true,
    className = '',
    children,
}) {
    const [collapsed, setCollapsed] = useState(() => (
        collapsible ? Boolean(defaultCollapsed) : false
    ));
    const [highlighted, setHighlighted] = useState(false);
    const savedExpandedRef = useRef(null);
    const collapsedRef = useRef(collapsed);
    const bodyCollapsed = collapsible && collapsed;

    useEffect(() => {
        collapsedRef.current = collapsed;
    }, [collapsed]);

    useEffect(() => {
        if (!collapsible && collapsed) {
            setCollapsed(false);
        }
    }, [collapsible, collapsed]);

    useEffect(() => {
        if (!widgetId) {
            return undefined;
        }

        const onControl = (event) => {
            const detail = event?.detail ?? {};
            if (detail.widgetId !== widgetId) {
                return;
            }

            if (detail.action === 'navigate') {
                setCollapsed(false);
                setHighlighted(true);
                window.setTimeout(() => setHighlighted(false), 1500);
                return;
            }

            if (detail.action !== 'set-collapsed') {
                return;
            }

            if (!collapsible) {
                setCollapsed(false);
                return;
            }

            if (detail.auto && detail.collapsed) {
                if (savedExpandedRef.current === null) {
                    savedExpandedRef.current = !collapsedRef.current;
                }
                setCollapsed(true);
                return;
            }

            if (detail.auto && !detail.collapsed) {
                if (savedExpandedRef.current !== null) {
                    setCollapsed(!savedExpandedRef.current);
                    savedExpandedRef.current = null;
                }
                return;
            }

            setCollapsed(Boolean(detail.collapsed));
        };

        window.addEventListener('seo-assistant-widget-control', onControl);

        return () => window.removeEventListener('seo-assistant-widget-control', onControl);
    }, [widgetId, collapsible]);

    return (
        <section
            className={`seo-assistant-widget ${className}${highlighted ? ' seo-assistant-widget--highlight' : ''}${bodyCollapsed ? ' seo-assistant-widget--collapsed' : ''}`.trim()}
            data-assistant-widget-id={widgetId || undefined}
            data-assistant-collapsed={bodyCollapsed ? '1' : '0'}
        >
            <header className="seo-assistant-widget__header">
                <button
                    type="button"
                    className="seo-assistant-widget__toggle"
                    aria-expanded={!bodyCollapsed}
                    disabled={!collapsible}
                    onClick={() => {
                        if (!collapsible) {
                            return;
                        }
                        savedExpandedRef.current = null;
                        setCollapsed((value) => !value);
                    }}
                >
                    {bodyCollapsed ? (
                        <ChevronRight size={16} aria-hidden />
                    ) : (
                        <ChevronDown size={16} aria-hidden />
                    )}
                    {Icon ? <Icon size={18} className="seo-assistant-widget__icon" aria-hidden /> : null}
                    <span className="seo-assistant-widget__title">{title}</span>
                    {badge != null && badge !== '' ? (
                        <span className="seo-assistant-widget__badge">{badge}</span>
                    ) : null}
                </button>
            </header>
            {/*
              Keep body mounted. Do NOT use the HTML `hidden` attribute — UA
              `[hidden]{display:none!important}` races panel-filter CSS and blanked Reviews.
            */}
            <div
                className="seo-assistant-widget__body"
                aria-hidden={bodyCollapsed ? 'true' : undefined}
            >
                {children}
            </div>
        </section>
    );
}
