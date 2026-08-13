/**
 * Phase 6B — canonical React navigation for editor panels.
 * Shell (Blade/Alpine) talks through compatibility bridge only.
 */

import {
    dispatchActiveModule,
    isEditorHostedModule,
    normalizeHeavyModuleId,
} from '../../utils/articleEditorModules';
import { pushAiMediaLaunchContext } from './editorAiMediaWorkspace';

/** @type {string|null} */
let activePanelId = 'seo';
/** @type {Set<(panelId: string|null, meta: object) => void>} */
const listeners = new Set();
/** @type {object|null} */
let inspectorState = null;

function emit(meta = {}) {
    const snapshot = activePanelId;
    listeners.forEach((listener) => {
        try {
            listener(snapshot, meta);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[editor-runtime-nav] listener failed', error);
        }
    });
}

/**
 * @param {(panelId: string|null, meta: object) => void} listener
 * @returns {() => void}
 */
export function subscribeEditorNavigation(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export function getActivePanel() {
    return activePanelId;
}

export function getOpenInspector() {
    return inspectorState;
}

/**
 * @param {string|null|undefined} rawId
 * @param {object} [meta]
 */
export function openPanel(rawId, meta = {}) {
    if (rawId == null || rawId === '') {
        closePanel(meta);
        return null;
    }

    const panelId = normalizeHeavyModuleId(rawId) || String(rawId).trim().toLowerCase();
    if (!panelId) {
        closePanel(meta);
        return null;
    }

    // Alpine-only slots: clear React heavy mount but keep shell panel id for consumers.
    if (!normalizeHeavyModuleId(panelId) && !isEditorHostedModule(panelId)) {
        activePanelId = panelId;
        dispatchActiveModule(null, { ...meta, shellPanel: panelId });
        emit({ ...meta, reason: 'shell_only' });
        return panelId;
    }

    activePanelId = panelId;
    if (panelId === 'ai-chat' && meta.detail && typeof meta.detail === 'object') {
        pushAiMediaLaunchContext(meta.detail);
    }
    if (isEditorHostedModule(panelId)) {
        dispatchActiveModule(panelId, meta);
    } else {
        dispatchActiveModule(panelId, meta);
    }
    emit({ ...meta, reason: 'open' });
    return panelId;
}

/**
 * @param {object} [meta]
 */
export function closePanel(meta = {}) {
    activePanelId = null;
    dispatchActiveModule(null, meta);
    emit({ ...meta, reason: 'close' });
}

/**
 * @param {string} rawId
 * @param {object} [meta]
 */
export function togglePanel(rawId, meta = {}) {
    const panelId = normalizeHeavyModuleId(rawId) || String(rawId ?? '').trim().toLowerCase();
    if (panelId && activePanelId === panelId) {
        closePanel(meta);
        return null;
    }
    return openPanel(rawId, meta);
}

/**
 * @param {string} reasonCode
 * @param {object} [meta]
 */
export function focusReason(reasonCode, meta = {}) {
    emit({
        ...meta,
        reason: 'focus_reason',
        reasonCode: String(reasonCode ?? ''),
    });
    if (typeof window !== 'undefined') {
        // Shell badge scroll — boundary event (not internal React bus).
        // fromRuntime prevents shell bridge from calling focusReason again (stack overflow).
        window.dispatchEvent(new CustomEvent('seo-assistant-focus-reason', {
            detail: {
                ...meta,
                code: reasonCode,
                reason: 'focus_reason',
                fromRuntime: true,
            },
        }));
    }
}

/**
 * @param {string} id
 * @param {object} [payload]
 */
export function openInspector(id, payload = {}) {
    inspectorState = { id: String(id ?? ''), payload };
    emit({ reason: 'open_inspector', inspector: inspectorState });
    return inspectorState;
}

export function closeInspector() {
    inspectorState = null;
    emit({ reason: 'close_inspector' });
}

/** Test helper */
export function __resetEditorNavigationForTests() {
    activePanelId = 'seo';
    inspectorState = null;
    listeners.clear();
}
