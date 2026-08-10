/**
 * Diagnostics-only window bridge.
 * Business mutations MUST go through domain actions (seoActions / mediaActions / …).
 * Former mutation methods are deprecated no-ops.
 */
import { getDiagnosticsSnapshot } from './editorDiagnostics.js';
import { getDirtyMap } from '@client-core/ownerDirtyState.js';

function deprecatedWarn(method) {
    if (typeof console !== 'undefined' && typeof console.warn === 'function') {
        console.warn(
            `[seo-editor] window.__seoEditorDomainBridge.${method} is deprecated (diag-only). Use domain actions directly.`,
        );
    }
}

export function getOwnerDirties() {
    return getDirtyMap();
}

export const editorDomainBridge = {
    version: 'diag-only',

    getDiagnosticsSnapshot,
    getDiagnostics: getDiagnosticsSnapshot,
    getOwnerDirties,

    /** @deprecated */
    contentChanged() {
        deprecatedWarn('contentChanged');
    },
    /** @deprecated */
    mediaChanged() {
        deprecatedWarn('mediaChanged');
    },
    /** @deprecated */
    seoChanged() {
        deprecatedWarn('seoChanged');
    },
    /** @deprecated */
    publishingChanged() {
        deprecatedWarn('publishingChanged');
    },
    /** @deprecated */
    wordpressFacts() {
        deprecatedWarn('wordpressFacts');
    },
    /** @deprecated */
    markCleanAll() {
        deprecatedWarn('markCleanAll');
    },
};
