import { useEffect, useMemo, useState } from 'react';
import {
    closeMediaPicker,
    confirmMediaPicker,
    getMediaPickerState,
    openMediaPicker,
    patchMediaPickerSelection,
    subscribeMediaPicker,
} from '@content-addon/editor/runtime/editorMediaPickerStore.js';

/**
 * Phase 6C.3 — shared media picker service hook.
 */
export function useEditorMediaPicker() {
    const [state, setState] = useState(() => getMediaPickerState());

    useEffect(() => subscribeMediaPicker(setState), []);

    return useMemo(() => ({
        state,
        open: openMediaPicker,
        close: closeMediaPicker,
        confirm: confirmMediaPicker,
        patchSelection: patchMediaPickerSelection,
        activeMode: state?.mode ?? null,
        isOpen: Boolean(state?.open),
        selectedItems: state?.selectedKeys?.map((key) => state.selectedItems[key]).filter(Boolean) ?? [],
    }), [state]);
}
