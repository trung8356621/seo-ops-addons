const STALE_ASSET_RELOAD_KEY = 'seo_editor_stale_asset_reload';

/**
 * Vite hashed chunk missing after rebuild while the page still holds an old parent bundle.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
export function isEditorChunkLoadError(error) {
    const message = String(error?.message ?? error ?? '');
    const name = String(error?.name ?? '');

    return name === 'ChunkLoadError'
        || /Failed to fetch dynamically imported module/i.test(message)
        || /Loading chunk [\d]+ failed/i.test(message)
        || /error loading dynamically imported module/i.test(message)
        || /Importing a module script failed/i.test(message);
}

/**
 * @returns {boolean}
 */
export function isEditorDocumentDirty() {
    if (typeof window === 'undefined') {
        return false;
    }

    return Boolean(window.__SEO_EDITOR_DIRTY__ || window.__seoEditorSaveInFlight);
}

/**
 * Auto full-page reload is disabled (reload loops + unsaved-data risk).
 * Kept as a no-op so callers/tests can still reference the historic API.
 *
 * @returns {boolean} always false — never triggers reload
 */
export function reloadForStaleEditorAssetsOnce() {
    return false;
}

/**
 * User-initiated reload only. Honors browser beforeunload when dirty.
 * Does not clear dirty state.
 *
 * @returns {boolean} true when reload was requested
 */
export function requestManualEditorReload() {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        window.sessionStorage.setItem(STALE_ASSET_RELOAD_KEY, '1');
    } catch {
        // ignore
    }

    window.location.reload();

    return true;
}

export function clearStaleEditorAssetReloadFlag() {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.removeItem(STALE_ASSET_RELOAD_KEY);
    } catch {
        // ignore
    }
}

export function hasStaleEditorAssetReloadFlag() {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return window.sessionStorage.getItem(STALE_ASSET_RELOAD_KEY) === '1';
    } catch {
        return false;
    }
}

export default {
    clearStaleEditorAssetReloadFlag,
    hasStaleEditorAssetReloadFlag,
    isEditorChunkLoadError,
    isEditorDocumentDirty,
    reloadForStaleEditorAssetsOnce,
    requestManualEditorReload,
};
