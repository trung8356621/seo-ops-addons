/**
 * Phase 6A — built-in module shape helpers (internal, not public SDK).
 */

/**
 * @typedef {{
 *   id: string,
 *   version?: number,
 *   order?: number,
 *   dependsOn?: string[],
 *   optionalDependsOn?: string[],
 *   isEnabled?: (context: object) => boolean,
 *   commands?: Array<object>,
 *   documentExtensions?: Array<object>,
 *   toolbar?: Array<object>,
 *   sidebar?: Array<object>,
 *   inspectors?: Array<object>,
 *   shortcuts?: Array<object>,
 *   contextActions?: Array<object>,
 *   healthProviders?: Array<object>,
 *   bootstrapConsumers?: Array<object>,
 *   lifecycle?: {
 *     onRuntimeCreate?: (runtime: object, context: object) => void|(() => void),
 *     onEditorReady?: (runtime: object, context: object) => void|(() => void),
 *     onDocumentChanged?: (runtime: object, context: object, detail: object) => void,
 *     onSessionStateChanged?: (runtime: object, context: object, detail: object) => void,
 *     onSnapshotChanged?: (runtime: object, context: object, detail: object) => void,
 *     onDestroy?: (runtime: object, context: object) => void,
 *   },
 * }} ArticleEditorRuntimeModule
 */

/**
 * @param {ArticleEditorRuntimeModule} module
 * @returns {ArticleEditorRuntimeModule}
 */
export function normalizeRuntimeModule(module) {
    const id = String(module?.id ?? '').trim();
    return {
        id,
        version: Number(module?.version ?? 1) || 1,
        order: Number.isFinite(Number(module?.order)) ? Number(module.order) : 100,
        dependsOn: Array.isArray(module?.dependsOn) ? module.dependsOn.map(String) : [],
        optionalDependsOn: Array.isArray(module?.optionalDependsOn)
            ? module.optionalDependsOn.map(String)
            : [],
        isEnabled: typeof module?.isEnabled === 'function'
            ? module.isEnabled
            : () => true,
        commands: Array.isArray(module?.commands) ? module.commands : [],
        documentExtensions: Array.isArray(module?.documentExtensions)
            ? module.documentExtensions
            : [],
        toolbar: Array.isArray(module?.toolbar) ? module.toolbar : [],
        sidebar: Array.isArray(module?.sidebar) ? module.sidebar : [],
        inspectors: Array.isArray(module?.inspectors) ? module.inspectors : [],
        shortcuts: Array.isArray(module?.shortcuts) ? module.shortcuts : [],
        contextActions: Array.isArray(module?.contextActions) ? module.contextActions : [],
        healthProviders: Array.isArray(module?.healthProviders) ? module.healthProviders : [],
        bootstrapConsumers: Array.isArray(module?.bootstrapConsumers)
            ? module.bootstrapConsumers
            : [],
        lifecycle: module?.lifecycle && typeof module.lifecycle === 'object'
            ? module.lifecycle
            : {},
    };
}
