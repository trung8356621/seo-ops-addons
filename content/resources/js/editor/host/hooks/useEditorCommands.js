import { useMemo } from 'react';
import { executeEditorCommand, listEditorCommands } from '../../../utils/editorCommands';

/**
 * Phase 6C.2 — scoped command contract (no giant host bag).
 */
export function useEditorCommands() {
    return useMemo(() => ({
        execute: (name, payload = {}, options = {}) => executeEditorCommand(name, payload, options),
        list: () => listEditorCommands(),
    }), []);
}
