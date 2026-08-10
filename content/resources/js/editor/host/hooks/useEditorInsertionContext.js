import { useMemo } from 'react';
import {
    clearFrozenEditorInsertionContext,
    freezeEditorInsertionContext,
    getFrozenEditorInsertionContext,
    getInsertionContextForCommand,
    preserveEditorContextBeforeSidebarAction,
} from '../../../utils/editorInsertionContext';

/**
 * Phase 6C.2 — insertion bookmark contract for Links/CTA modules.
 */
export function useEditorInsertionContext() {
    return useMemo(() => ({
        getForCommand: () => getInsertionContextForCommand(),
        getFrozen: () => getFrozenEditorInsertionContext(),
        freeze: (ctx) => freezeEditorInsertionContext(ctx),
        clearFrozen: () => clearFrozenEditorInsertionContext(),
        preserveBeforeSidebarAction: () => preserveEditorContextBeforeSidebarAction(),
    }), []);
}
