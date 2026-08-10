/**
 * Phase 6A — dependency / duplicate validation (fail-fast in development).
 */

import { EditorRuntimeError, EditorRuntimeErrorCode } from './editorRuntimeErrors';

/**
 * @param {Array<{id: string, dependsOn: string[], optionalDependsOn: string[]}>} modules
 * @param {{ failFast?: boolean }} [options]
 * @returns {{ ok: boolean, errors: EditorRuntimeError[], ordered: typeof modules }}
 */
export function validateRuntimeModules(modules, options = {}) {
    const failFast = options.failFast !== false;
    const errors = [];
    const byId = new Map();

    for (const mod of modules) {
        if (!mod?.id) {
            const err = new EditorRuntimeError(
                EditorRuntimeErrorCode.INVALID_MODULE,
                'Module missing id.',
            );
            errors.push(err);
            if (failFast) throw err;
            continue;
        }
        if (byId.has(mod.id)) {
            const err = new EditorRuntimeError(
                EditorRuntimeErrorCode.DUPLICATE_MODULE,
                `Duplicate module id: ${mod.id}`,
                { moduleId: mod.id },
            );
            errors.push(err);
            if (failFast) throw err;
            continue;
        }
        byId.set(mod.id, mod);
    }

    for (const mod of byId.values()) {
        for (const dep of mod.dependsOn || []) {
            if (!byId.has(dep)) {
                const err = new EditorRuntimeError(
                    EditorRuntimeErrorCode.MISSING_DEPENDENCY,
                    `Module ${mod.id} missing dependency ${dep}`,
                    { moduleId: mod.id, dependency: dep },
                );
                errors.push(err);
                if (failFast) throw err;
            }
        }
    }

    const visiting = new Set();
    const visited = new Set();
    const ordered = [];

    const visit = (id, stack = []) => {
        if (visited.has(id)) return;
        if (visiting.has(id)) {
            const err = new EditorRuntimeError(
                EditorRuntimeErrorCode.CIRCULAR_DEPENDENCY,
                `Circular dependency: ${[...stack, id].join(' -> ')}`,
                { cycle: [...stack, id] },
            );
            errors.push(err);
            if (failFast) throw err;
            return;
        }
        visiting.add(id);
        const mod = byId.get(id);
        if (mod) {
            for (const dep of mod.dependsOn || []) {
                if (byId.has(dep)) {
                    visit(dep, [...stack, id]);
                }
            }
            visited.add(id);
            visiting.delete(id);
            ordered.push(mod);
        }
    };

    const sortedIds = [...byId.keys()].sort((a, b) => {
        const oa = Number(byId.get(a)?.order ?? 100);
        const ob = Number(byId.get(b)?.order ?? 100);
        if (oa !== ob) return oa - ob;
        return a.localeCompare(b);
    });

    for (const id of sortedIds) {
        visit(id);
    }

    return { ok: errors.length === 0, errors, ordered };
}

/**
 * @param {Array<{id: string}>} items
 * @param {string} label
 * @param {{ failFast?: boolean }} [options]
 */
export function assertUniqueIds(items, label, options = {}) {
    const failFast = options.failFast !== false;
    const seen = new Set();
    const errors = [];
    for (const item of items) {
        const id = String(item?.id ?? '').trim();
        if (!id) continue;
        if (seen.has(id)) {
            const err = new EditorRuntimeError(
                EditorRuntimeErrorCode.DUPLICATE_SLOT_ITEM,
                `Duplicate ${label} id: ${id}`,
                { id, label },
            );
            errors.push(err);
            if (failFast) throw err;
            continue;
        }
        seen.add(id);
    }
    return errors;
}
