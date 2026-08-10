/**
 * Built-in module holder — composition root registers modules here.
 * Runtime singleton reads this holder and must NOT import ../modules
 * (avoids runtime ↔ modules ↔ panels ↔ runtime TDZ cycles).
 */

/** @type {ReadonlyArray<object>|null} */
let builtinModules = null;

/**
 * @param {ReadonlyArray<object>} modules
 */
export function setBuiltinArticleEditorModules(modules) {
    builtinModules = Array.isArray(modules) ? modules : null;
}

/**
 * @returns {ReadonlyArray<object>}
 */
export function getBuiltinArticleEditorModules() {
    if (!builtinModules) {
        throw new Error(
            '[article-editor-runtime] Builtin modules not registered. '
            + 'Import editor/modules from the composition root before getDefaultArticleEditorRuntime().',
        );
    }

    return builtinModules;
}

export function __resetBuiltinArticleEditorModulesForTests() {
    builtinModules = null;
}
