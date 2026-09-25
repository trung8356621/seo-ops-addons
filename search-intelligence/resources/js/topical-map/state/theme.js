export const TOPICAL_MAP_THEME_KEY = 'seo-ops.topical-map.theme';

function systemTheme() {
    if (typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }

    return 'light';
}

function readStorage(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

/**
 * First manual Topical Map choice, then Filament's canonical theme signal,
 * then the resolved html.dark class, with OS preference as the final fallback.
 */
export function resolveInitialTopicalMapTheme() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return 'light';
    }

    const saved = readStorage(TOPICAL_MAP_THEME_KEY);
    if (saved === 'light' || saved === 'dark') {
        return saved;
    }

    const filamentTheme = String(window.theme || readStorage('theme') || '').trim();
    if (filamentTheme === 'light' || filamentTheme === 'dark') {
        return filamentTheme;
    }
    if (filamentTheme === 'system') {
        return systemTheme();
    }

    if (document.documentElement.classList.contains('dark')) {
        return 'dark';
    }

    return systemTheme();
}

export function persistTopicalMapTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') {
        return;
    }

    try {
        window.localStorage.setItem(TOPICAL_MAP_THEME_KEY, theme);
    } catch {
        // Storage can be unavailable in privacy-restricted browsing contexts.
    }
}
