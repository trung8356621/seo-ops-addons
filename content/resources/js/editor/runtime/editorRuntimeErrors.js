/**
 * Phase 6A — internal editor runtime error codes.
 */

export const EditorRuntimeErrorCode = Object.freeze({
    DUPLICATE_MODULE: 'editor_runtime_duplicate_module',
    MISSING_DEPENDENCY: 'editor_runtime_missing_dependency',
    CIRCULAR_DEPENDENCY: 'editor_runtime_circular_dependency',
    DUPLICATE_COMMAND: 'editor_runtime_duplicate_command',
    DUPLICATE_EXTENSION: 'editor_runtime_duplicate_extension',
    DUPLICATE_SLOT_ITEM: 'editor_runtime_duplicate_slot_item',
    INVALID_MODULE: 'editor_runtime_invalid_module',
    MODULE_DISABLED: 'editor_runtime_module_disabled',
    SLOT_UNKNOWN: 'editor_runtime_slot_unknown',
});

export class EditorRuntimeError extends Error {
    /**
     * @param {string} code
     * @param {string} message
     * @param {Record<string, unknown>} [details]
     */
    constructor(code, message, details = {}) {
        super(message);
        this.name = 'EditorRuntimeError';
        this.code = code;
        this.details = details;
    }
}
