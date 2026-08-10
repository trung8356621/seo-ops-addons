/**
 * Phase 6C.4 — internal host contract marker (NOT public SDK).
 */

export const EDITOR_RUNTIME_HOST_CONTRACT_VERSION = 1;

/** Documented scoped hook surface for built-in modules. */
export const EDITOR_SCOPED_HOST_HOOKS = Object.freeze([
    'useEditorDocument',
    'useEditorCommands',
    'useEditorSession',
    'useEditorInsertionContext',
    'useEditorNavigation',
    'useEditorMedia',
    'useEditorMediaPicker',
    'useEditorAnalysis',
    'useEditorFaq',
    'useEditorContacts',
    'useEditorAi',
    'useEditorPermissions',
    'useEditorNotifications',
    'useEditorShellBoundary',
    'useEditorLinks',
]);
