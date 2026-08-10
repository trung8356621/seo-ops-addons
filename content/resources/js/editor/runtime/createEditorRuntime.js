/**
 * Phase 6A — internal article editor runtime (built-in modules only).
 * Not a public SDK. No dynamic third-party registration.
 */

import { normalizeRuntimeModule } from './editorRuntimeModule';
import {
    createEmptyRuntimeRegistries,
    registerUnique,
    sortByOrder,
} from './editorRuntimeRegistry';
import { validateRuntimeModules } from './editorRuntimeValidation';
import { buildEditorRuntimeContext } from './editorRuntimeContext';
import { EditorRuntimeError, EditorRuntimeErrorCode } from './editorRuntimeErrors';
import { isKnownEditorRuntimeSlot } from './editorRuntimeSlots';
import {
    selectEnabledModules,
    selectSidebarEntries,
    selectSlotItems,
    selectMutationUiEnabled,
} from './editorRuntimeSelectors';

/**
 * @param {{
 *   modules?: object[],
 *   context?: object,
 *   failFast?: boolean,
 *   mode?: 'development'|'production',
 * }} [options]
 */
export function createEditorRuntime(options = {}) {
    const failFast = options.failFast !== false
        && (options.mode === 'development' || options.mode == null);
    const rawModules = Array.isArray(options.modules) ? options.modules : [];
    const normalized = rawModules.map(normalizeRuntimeModule);
    const validation = validateRuntimeModules(normalized, { failFast });
    const orderedModules = validation.ordered;

    const registries = createEmptyRuntimeRegistries();
    /** @type {Set<string>} */
    const extensionNames = new Set();
    /** @type {Array<() => void>} */
    const cleanups = [];
    let destroyed = false;
    let context = buildEditorRuntimeContext(options.context || {});
    let createGeneration = 0;

    const onDuplicate = (kind, id) => {
        const code = kind === 'command'
            ? EditorRuntimeErrorCode.DUPLICATE_COMMAND
            : kind === 'extension'
                ? EditorRuntimeErrorCode.DUPLICATE_EXTENSION
                : EditorRuntimeErrorCode.DUPLICATE_SLOT_ITEM;
        const err = new EditorRuntimeError(code, `Duplicate ${kind}: ${id}`, { kind, id });
        if (failFast) throw err;
        // eslint-disable-next-line no-console
        console.warn('[article-editor-runtime]', err.message, err.details);
    };

    for (const mod of orderedModules) {
        registries.modules.set(mod.id, mod);

        for (const cmd of mod.commands) {
            const id = String(cmd?.id || cmd?.name || '').trim();
            if (!id) continue;
            registerUnique(
                registries.commands,
                { ...cmd, id, moduleId: mod.id },
                'command',
                () => onDuplicate('command', id),
            );
        }

        for (const ext of mod.documentExtensions) {
            const name = String(ext?.name || ext?.id || '').trim();
            if (!name) continue;
            if (extensionNames.has(name)) {
                onDuplicate('extension', name);
                continue;
            }
            const id = String(ext?.id || name).trim();
            registerUnique(
                registries.documentExtensions,
                {
                    ...ext,
                    id,
                    name,
                    moduleId: mod.id,
                    order: Number(ext?.order ?? mod.order),
                    schemaVersion: Number(ext?.schemaVersion ?? 1) || 1,
                    factory: typeof ext?.factory === 'function' ? ext.factory : () => ext.extension,
                },
                'extension',
                () => onDuplicate('extension', name),
            );
            if (registries.documentExtensions.has(id)) {
                extensionNames.add(name);
            }
        }

        const bagMaps = [
            ['toolbar', mod.toolbar],
            ['sidebar', mod.sidebar],
            ['inspectors', mod.inspectors],
            ['shortcuts', mod.shortcuts],
            ['contextActions', mod.contextActions],
            ['healthProviders', mod.healthProviders],
        ];
        for (const [bag, items] of bagMaps) {
            for (const item of items) {
                const id = String(item?.id ?? '').trim();
                if (!id) continue;
                registerUnique(
                    registries[bag],
                    { ...item, id, moduleId: mod.id, order: Number(item?.order ?? mod.order) },
                    bag,
                    () => onDuplicate(bag, id),
                );
            }
        }
    }

    // TipTap must keep a stable extensions array identity (no remount on context updates).
    /** @type {unknown[]|null} */
    let cachedDocumentExtensions = null;

    const runtime = {
        creationId: `runtime-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        getCreateGeneration() {
            return createGeneration;
        },
        getContext() {
            return context;
        },
        setContext(next) {
            context = buildEditorRuntimeContext(next || {});
            createGeneration += 1;
            // Do NOT invalidate TipTap extension cache on context change.
            for (const mod of orderedModules) {
                try {
                    mod.lifecycle?.onSessionStateChanged?.(runtime, context, {});
                } catch (error) {
                    // eslint-disable-next-line no-console
                    console.warn('[article-editor-runtime] onSessionStateChanged', mod.id, error);
                }
            }
            return context;
        },
        getModules() {
            return [...orderedModules];
        },
        getEnabledModules() {
            return selectEnabledModules(runtime, context);
        },
        listRegistryItems(kind) {
            const map = registries[kind];
            if (!(map instanceof Map)) return [];
            return sortByOrder([...map.values()]);
        },
        getDocumentExtensions() {
            if (cachedDocumentExtensions) {
                return cachedDocumentExtensions;
            }
            // Core extensions always included; optional module extensions filtered once at create.
            cachedDocumentExtensions = sortByOrder(
                [...registries.documentExtensions.values()],
            ).map((ext) => ext.factory()).filter(Boolean);
            return cachedDocumentExtensions;
        },
        getSidebarEntries() {
            return selectSidebarEntries(runtime, context);
        },
        getToolbarItems(group = null) {
            const items = selectSlotItems(runtime, context, 'toolbar');
            if (!group) return items;
            return items.filter((item) => String(item.group || '') === String(group));
        },
        getHealthProviders() {
            return selectSlotItems(runtime, context, 'healthProviders');
        },
        getShortcuts() {
            return selectSlotItems(runtime, context, 'shortcuts');
        },
        getSlotItems(slotName) {
            if (!isKnownEditorRuntimeSlot(slotName) && !['sidebar', 'toolbar', 'healthProviders', 'shortcuts', 'inspectors', 'contextActions'].includes(slotName)) {
                if (failFast) {
                    throw new EditorRuntimeError(
                        EditorRuntimeErrorCode.SLOT_UNKNOWN,
                        `Unknown slot: ${slotName}`,
                        { slotName },
                    );
                }
            }
            // Map dotted slots to registry bags when needed by UI.
            if (slotName.startsWith('sidebar.')) {
                return selectSidebarEntries(runtime, context);
            }
            if (slotName.startsWith('toolbar.')) {
                const group = slotName.split('.')[1];
                return runtime.getToolbarItems(group);
            }
            if (slotName.startsWith('bubble.') || slotName.startsWith('inspector.')) {
                return selectSlotItems(runtime, context, 'inspectors').filter(
                    (item) => String(item.slot || item.group || '') === slotName
                        || String(item.id || '').includes(slotName.split('.')[1] || ''),
                );
            }
            return selectSlotItems(runtime, context, slotName);
        },
        isMutationUiEnabled() {
            return selectMutationUiEnabled(context);
        },
        getDiagnostics() {
            return {
                moduleCount: orderedModules.length,
                commandCount: registries.commands.size,
                extensionCount: registries.documentExtensions.size,
                sidebarCount: registries.sidebar.size,
                toolbarCount: registries.toolbar.size,
                healthCount: registries.healthProviders.size,
                shortcutCount: registries.shortcuts.size,
                validationErrors: validation.errors.map((e) => e.code),
                creationId: runtime.creationId,
                createGeneration,
            };
        },
        notifyDocumentChanged(detail = {}) {
            for (const mod of orderedModules) {
                try {
                    mod.lifecycle?.onDocumentChanged?.(runtime, context, detail);
                } catch (error) {
                    // eslint-disable-next-line no-console
                    console.warn('[article-editor-runtime] onDocumentChanged', mod.id, error);
                }
            }
        },
        notifySnapshotChanged(detail = {}) {
            for (const mod of orderedModules) {
                try {
                    mod.lifecycle?.onSnapshotChanged?.(runtime, context, detail);
                } catch (error) {
                    // eslint-disable-next-line no-console
                    console.warn('[article-editor-runtime] onSnapshotChanged', mod.id, error);
                }
            }
        },
        destroy() {
            if (destroyed) return;
            destroyed = true;
            for (const mod of [...orderedModules].reverse()) {
                try {
                    mod.lifecycle?.onDestroy?.(runtime, context);
                } catch (error) {
                    // eslint-disable-next-line no-console
                    console.warn('[article-editor-runtime] onDestroy', mod.id, error);
                }
            }
            while (cleanups.length) {
                try {
                    cleanups.pop()?.();
                } catch {
                    // ignore cleanup errors
                }
            }
        },
    };

    for (const mod of orderedModules) {
        try {
            const cleanup = mod.lifecycle?.onRuntimeCreate?.(runtime, context);
            if (typeof cleanup === 'function') {
                cleanups.push(cleanup);
            }
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[article-editor-runtime] onRuntimeCreate', mod.id, error);
            if (failFast) throw error;
        }
    }

    return runtime;
}
