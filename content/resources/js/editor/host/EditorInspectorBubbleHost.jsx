import React, { Suspense, useMemo } from 'react';
import { EditorModuleErrorBoundary } from '../runtime/EditorModuleErrorBoundary';

/**
 * Phase 6C.4 — mount inspector/bubble components from runtime registry (no host hard-import).
 */
export function EditorInspectorBubbleHost({
    runtime,
    slot = 'bubble.link',
    editor = null,
    anchorRect = null,
    containerRef = null,
    onClose = null,
    articleId = null,
    siteId = null,
}) {
    const entry = useMemo(() => {
        if (typeof runtime?.getSlotItems === 'function') {
            const items = runtime.getSlotItems(slot);
            return Array.isArray(items) && items.length > 0 ? items[0] : null;
        }
        return null;
    }, [runtime, slot]);

    if (!entry?.component || !editor || !anchorRect) {
        return null;
    }

    const Comp = entry.component;

    return (
        <EditorModuleErrorBoundary moduleId={entry.moduleId || 'inspector'} slotName={slot}>
            <Suspense fallback={null}>
                <Comp
                    editor={editor}
                    anchorRect={anchorRect}
                    containerRef={containerRef}
                    onClose={onClose}
                    articleId={articleId}
                    siteId={siteId}
                />
            </Suspense>
        </EditorModuleErrorBoundary>
    );
}
