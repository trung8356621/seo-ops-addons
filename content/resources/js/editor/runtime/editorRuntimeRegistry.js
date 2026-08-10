/**
 * Phase 6A — immutable registry bags (sorted copies; modules never mutate).
 */

/**
 * @template T
 * @param {T[]} items
 * @param {(item: T) => number} orderOf
 * @returns {T[]}
 */
export function sortByOrder(items, orderOf = (item) => Number(item?.order ?? 100)) {
    return [...items].sort((a, b) => {
        const oa = orderOf(a);
        const ob = orderOf(b);
        if (oa !== ob) return oa - ob;
        const ida = String(a?.id ?? '');
        const idb = String(b?.id ?? '');
        return ida.localeCompare(idb);
    });
}

/**
 * @returns {{
 *   modules: Map<string, object>,
 *   commands: Map<string, object>,
 *   documentExtensions: Map<string, object>,
 *   toolbar: Map<string, object>,
 *   sidebar: Map<string, object>,
 *   inspectors: Map<string, object>,
 *   shortcuts: Map<string, object>,
 *   contextActions: Map<string, object>,
 *   healthProviders: Map<string, object>,
 * }}
 */
export function createEmptyRuntimeRegistries() {
    return {
        modules: new Map(),
        commands: new Map(),
        documentExtensions: new Map(),
        toolbar: new Map(),
        sidebar: new Map(),
        inspectors: new Map(),
        shortcuts: new Map(),
        contextActions: new Map(),
        healthProviders: new Map(),
    };
}

/**
 * @param {Map<string, object>} map
 * @param {object} item
 * @param {string} kind
 * @param {(error: Error) => void} onDuplicate
 */
export function registerUnique(map, item, kind, onDuplicate) {
    const id = String(item?.id ?? '').trim();
    if (!id) return;
    if (map.has(id)) {
        onDuplicate(new Error(`Duplicate ${kind}: ${id}`));
        return;
    }
    map.set(id, item);
}
