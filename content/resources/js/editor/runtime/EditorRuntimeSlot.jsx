import React, { useMemo } from 'react';
import { EditorModuleErrorBoundary } from './EditorModuleErrorBoundary';

/**
 * Render registered slot items. Modules supply `component` or `render`.
 * Core keeps TipTap outside this slot.
 */
export function EditorRuntimeSlot({
    runtime,
    name,
    context = null,
    className = '',
    empty = null,
}) {
    const items = useMemo(() => {
        if (!runtime || typeof runtime.getSlotItems !== 'function') return [];
        if (context && typeof runtime.setContext === 'function') {
            // Caller should pass already-applied context; do not setContext here (avoids rebuild loops).
        }
        return runtime.getSlotItems(name) || [];
    }, [runtime, name, context]);

    if (!items.length) {
        return empty;
    }

    return (
        <div className={className} data-editor-runtime-slot={name}>
            {items.map((item) => {
                const Comp = item.component;
                const key = item.id;
                const body = typeof item.render === 'function'
                    ? item.render({ runtime, context: context || runtime.getContext(), item })
                    : Comp
                        ? <Comp runtime={runtime} context={context || runtime.getContext()} item={item} />
                        : null;
                if (body == null) return null;
                return (
                    <EditorModuleErrorBoundary
                        key={key}
                        moduleId={item.moduleId}
                        slotName={name}
                    >
                        {body}
                    </EditorModuleErrorBoundary>
                );
            })}
        </div>
    );
}
