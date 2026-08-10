import React, { createContext, useContext } from 'react';

/**
 * Narrow host API for built-in module panels (Phase 6B).
 * Modules must not read window/Alpine/Livewire SoT — use this context.
 */
export const EditorHostApiContext = createContext(null);

export function EditorHostApiProvider({ value, children }) {
    return (
        <EditorHostApiContext.Provider value={value}>
            {children}
        </EditorHostApiContext.Provider>
    );
}

export function useEditorHostApi() {
    const value = useContext(EditorHostApiContext);
    if (!value) {
        throw new Error('useEditorHostApi requires EditorHostApiProvider');
    }
    return value;
}

export function useEditorHostApiOptional() {
    return useContext(EditorHostApiContext);
}
