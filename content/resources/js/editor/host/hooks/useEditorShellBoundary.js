import { useMemo } from 'react';
import { SHELL_BOUNDARY_NAV_ITEMS } from '../../runtime/editorShellNavItems';
import { openPanel } from '../../runtime/editorRuntimeNavigation';

/**
 * Phase 6C.4 — publishing/article shell boundary (no publish commands).
 */
export function useEditorShellBoundary() {
    return useMemo(() => ({
        items: SHELL_BOUNDARY_NAV_ITEMS,
        openPublishing: () => openPanel('publishing', { source: 'shell_boundary_hook' }),
        openArticleInfo: () => openPanel('article', { source: 'shell_boundary_hook' }),
    }), []);
}
