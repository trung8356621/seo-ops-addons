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
 * Allow one automatic full reload per page session for stale assets.
 * Call {@link clearStaleEditorAssetReloadFlag} on fresh editor boot.
 *
 * @returns {boolean} true when a reload was triggered
 */
export function reloadForStaleEditorAssetsOnce() {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        if (window.sessionStorage.getItem(STALE_ASSET_RELOAD_KEY) === '1') {
            return false;
        }
        window.sessionStorage.setItem(STALE_ASSET_RELOAD_KEY, '1');
    } catch {
        // sessionStorage unavailable — still attempt a single reload
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

export default {
    clearStaleEditorAssetReloadFlag,
    isEditorChunkLoadError,
    reloadForStaleEditorAssetsOnce,
};
