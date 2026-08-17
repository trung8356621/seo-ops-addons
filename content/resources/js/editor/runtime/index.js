/**
 * Phase 6A/6B — Article Editor internal runtime entry.
 */

import { createEditorRuntime } from './createEditorRuntime';
import { getBuiltinArticleEditorModules } from './builtinModulesRegistry';
import { buildEditorRuntimeContext } from './editorRuntimeContext';
import {
    getDefaultArticleEditorRuntime,
    __resetDefaultArticleEditorRuntimeForTests,
} from './defaultArticleEditorRuntime';

export { createEditorRuntime } from './createEditorRuntime';
export { buildEditorRuntimeContext, isRuntimeContextWritable } from './editorRuntimeContext';
export { EditorRuntimeError, EditorRuntimeErrorCode } from './editorRuntimeErrors';
export { EDITOR_RUNTIME_SLOTS, isKnownEditorRuntimeSlot } from './editorRuntimeSlots';
export { EditorRuntimeSlot } from './EditorRuntimeSlot';
export { EditorModuleErrorBoundary } from './EditorModuleErrorBoundary';
export {
    clearStaleEditorAssetReloadFlag,
    isEditorChunkLoadError,
    reloadForStaleEditorAssetsOnce,
} from './staleEditorAssets';
export {
    selectEnabledModules,
    selectSidebarEntries,
    selectSlotItems,
    selectMutationUiEnabled,
} from './editorRuntimeSelectors';
export { validateRuntimeModules, assertUniqueIds } from './editorRuntimeValidation';
export {
    openPanel,
    closePanel,
    togglePanel,
    getActivePanel,
    focusReason,
    openInspector,
    closeInspector,
    subscribeEditorNavigation,
} from './editorRuntimeNavigation';
export {
    composeRuntimeWidgetHealth,
    publishRuntimeWidgetHealth,
    publishPartialRuntimeWidgetHealth,
    listRuntimeHealthBuilderKeys,
} from './composeRuntimeWidgetHealth';
export {
    setRuntimeWidgetHealth,
    patchRuntimeWidgetHealth,
    bindDiagnosticsArticleScope,
    beginDiagnosticsRefresh,
    markDiagnosticsRefreshing,
    getDiagnosticsGeneration,
    subscribeRuntimeWidgetHealth,
    installRuntimeHealthBadgeBridge,
    publishEditorShellHealthSummary,
} from './editorRuntimeHealthStore';
export { SHELL_BOUNDARY_NAV_ITEMS } from './editorShellNavItems';
export {
    installEditorShellCompatibilityBridge,
    SHELL_COMPAT_DEPRECATED_EVENTS,
} from './editorShellCompatibilityBridge';
export {
    getDefaultArticleEditorRuntime,
    __resetDefaultArticleEditorRuntimeForTests,
};

/**
 * @param {{ modules?: object[], context?: object, failFast?: boolean, mode?: string }} [options]
 */
export function createArticleEditorRuntime(options = {}) {
    return createEditorRuntime({
        modules: options.modules ?? getBuiltinArticleEditorModules(),
        context: buildEditorRuntimeContext(options.context || {}),
        failFast: options.failFast,
        mode: options.mode,
    });
}

export {
    registerSaveOwner,
    unregisterSaveOwner,
    clearSaveOwners,
    listSaveOwnerIds,
    flushAllSaveOwners,
} from './saveCoordinator';
