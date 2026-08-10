/**
 * Phase 6A — pure selectors over runtime registries + context.
 */

import { isRuntimeContextWritable } from './editorRuntimeContext';
import { sortByOrder } from './editorRuntimeRegistry';

/**
 * @param {object} runtime
 * @param {object} context
 * @returns {object[]}
 */
export function selectEnabledModules(runtime, context) {
    const modules = runtime?.getModules?.() ?? [];
    return modules.filter((mod) => {
        try {
            return mod.isEnabled(context) !== false;
        } catch {
            return false;
        }
    });
}

/**
 * @param {object} runtime
 * @param {object} context
 * @param {string} slot
 * @returns {object[]}
 */
export function selectSlotItems(runtime, context, slot) {
    const enabledIds = new Set(selectEnabledModules(runtime, context).map((m) => m.id));
    const items = (runtime?.listRegistryItems?.(slot) ?? []).filter(
        (item) => !item.moduleId || enabledIds.has(item.moduleId),
    );
    return sortByOrder(items).filter((item) => {
        if (typeof item.isVisible === 'function') {
            try {
                if (item.isVisible(context) === false) return false;
            } catch {
                return false;
            }
        }
        // Mutation contributions stay listed when locked so UI can disable (not hide).
        // isEnabled === false on non-mutation items still hides them.
        if (typeof item.isEnabled === 'function' && item.mutation !== true && item.requiresWritable !== true) {
            try {
                if (item.isEnabled(context) === false) return false;
            } catch {
                return false;
            }
        }
        return true;
    });
}

/**
 * @param {object} item
 * @param {object} context
 * @returns {boolean}
 */
export function isRegistryMutationEnabled(item, context) {
    if (!selectMutationUiEnabled(context)) {
        if (item?.allowedInReadOnly === true || item?.requiresWritable === false) {
            return true;
        }
        return false;
    }
    if (typeof item?.isEnabled === 'function') {
        try {
            return item.isEnabled(context) !== false;
        } catch {
            return false;
        }
    }
    return true;
}

/**
 * @param {object} runtime
 * @param {object} context
 * @returns {object[]}
 */
export function selectSidebarEntries(runtime, context) {
    return selectSlotItems(runtime, context, 'sidebar').filter((item) => {
        if (typeof item.isEnabled === 'function') {
            try {
                return item.isEnabled(context) !== false;
            } catch {
                return false;
            }
        }
        return true;
    });
}

/**
 * @param {object} context
 * @returns {boolean}
 */
export function selectMutationUiEnabled(context) {
    return isRuntimeContextWritable(context);
}
