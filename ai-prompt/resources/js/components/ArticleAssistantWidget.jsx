import React, { useEffect, useRef, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';

/**
 * @param {{
 *   widgetId?: string,
 *   title: string,
 *   icon?: React.ComponentType<{ size?: number, className?: string, 'aria-hidden'?: boolean }>,
 *   badge?: string|number|null,
 *   defaultCollapsed?: boolean,
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
    className = '',
    children,
}) {
    const [collapsed, setCollapsed] = useState(defaultCollapsed);
    const [highlighted, setHighlighted] = useState(false);
    const savedExpandedRef = useRef(null);
    const collapsedRef = useRef(collapsed);

    useEffect(() => {
        collapsedRef.current = collapsed;
    }, [collapsed]);

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
    }, [widgetId]);

    return (
        <section
            className={`seo-assistant-widget ${className}${highlighted ? ' seo-assistant-widget--highlight' : ''}${collapsed ? ' seo-assistant-widget--collapsed' : ''}`.trim()}
            data-assistant-widget-id={widgetId || undefined}
            data-assistant-collapsed={collapsed ? '1' : '0'}
        >
            <header className="seo-assistant-widget__header">
                <button
                    type="button"
                    className="seo-assistant-widget__toggle"
                    aria-expanded={!collapsed}
                    onClick={() => {
                        savedExpandedRef.current = null;
                        setCollapsed((value) => !value);
                    }}
                >
                    {collapsed ? (
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
            {!collapsed ? <div className="seo-assistant-widget__body">{children}</div> : null}
        </section>
    );
}
