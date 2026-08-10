/**
 * Singleton runtime factory.
 * Modules come from builtinModulesRegistry (composition root registers them).
 * This file must NOT import ../modules — that edge created production TDZ cycles.
 */

import { createEditorRuntime } from './createEditorRuntime';
import { getBuiltinArticleEditorModules } from './builtinModulesRegistry';

/** @type {ReturnType<typeof createEditorRuntime>|null} */
let defaultRuntime = null;

/**
 * @param {object|null} [context]
 */
export function getDefaultArticleEditorRuntime(context = null) {
    if (!defaultRuntime) {
        defaultRuntime = createEditorRuntime({
            modules: getBuiltinArticleEditorModules(),
            context: context || {},
            failFast: typeof process !== 'undefined' && process.env?.NODE_ENV !== 'production'
                ? true
                : false,
            mode: typeof process !== 'undefined' && process.env?.NODE_ENV === 'production'
                ? 'production'
                : 'development',
        });
    } else if (context) {
        defaultRuntime.setContext(context);
    }
    return defaultRuntime;
}

export function __resetDefaultArticleEditorRuntimeForTests() {
    if (defaultRuntime) {
        defaultRuntime.destroy();
    }
    defaultRuntime = null;
}
