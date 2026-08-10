import { useEffect, useMemo, useState } from 'react';
import {
    closePanel,
    getActivePanel,
    openPanel,
    subscribeEditorNavigation,
} from '../../runtime/editorRuntimeNavigation';

/**
 * Phase 6C.4 — active panel navigation (runtime SoT).
 */
export function useEditorNavigation() {
    const [activePanelId, setActivePanelId] = useState(() => getActivePanel());

    useEffect(() => subscribeEditorNavigation((panelId) => {
        setActivePanelId(panelId || null);
    }), []);

    return useMemo(() => ({
        activePanelId,
        openPanel,
        closePanel,
        getActivePanel,
    }), [activePanelId]);
}
