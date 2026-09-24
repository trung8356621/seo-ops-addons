/**
 * Robust clipboard write helpers (no Seeding-domain deps).
 * Clipboard API first; execCommand fallback for insecure HTTP (e.g. http://seo-ops.test).
 */

/**
 * Legacy execCommand fallback.
 *
 * @param {string} text
 * @param {Document} [doc]
 * @returns {boolean}
 */
export function copyTextViaExecCommand(text, doc = typeof document !== 'undefined' ? document : null) {
    if (!doc || typeof doc.createElement !== 'function') return false;
    const value = String(text ?? '');
    const ta = doc.createElement('textarea');
    ta.value = value;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '0';
    ta.style.left = '0';
    ta.style.width = '1px';
    ta.style.height = '1px';
    ta.style.padding = '0';
    ta.style.border = 'none';
    ta.style.outline = 'none';
    ta.style.boxShadow = 'none';
    ta.style.background = 'transparent';
    ta.style.opacity = '0';

    const parent = doc.body || doc.documentElement;
    if (!parent) return false;
    parent.appendChild(ta);
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, value.length);

    let ok = false;
    try {
        ok = typeof doc.execCommand === 'function' ? Boolean(doc.execCommand('copy')) : false;
    } catch {
        ok = false;
    }
    try {
        parent.removeChild(ta);
    } catch {
        /* ignore */
    }
    return ok;
}

/**
 * @param {string} text
 * @param {{
 *   clipboard?: { writeText?: (value: string) => Promise<void> }|null,
 *   document?: Document|null,
 * }} [deps]
 * @returns {Promise<{ ok: boolean, method: 'clipboard'|'execCommand'|null, error?: string }>}
 */
export async function writeClipboard(text, deps = {}) {
    const value = String(text ?? '');
    const navClipboard = deps.clipboard !== undefined
        ? deps.clipboard
        : (typeof navigator !== 'undefined' ? navigator.clipboard : null);
    const doc = deps.document !== undefined
        ? deps.document
        : (typeof document !== 'undefined' ? document : null);

    if (navClipboard && typeof navClipboard.writeText === 'function') {
        try {
            await navClipboard.writeText(value);
            return { ok: true, method: 'clipboard' };
        } catch (err) {
            const fallback = copyTextViaExecCommand(value, doc);
            if (fallback) {
                return { ok: true, method: 'execCommand' };
            }
            return {
                ok: false,
                method: null,
                error: err instanceof Error ? err.message : 'Clipboard API rejected',
            };
        }
    }

    if (copyTextViaExecCommand(value, doc)) {
        return { ok: true, method: 'execCommand' };
    }

    return {
        ok: false,
        method: null,
        error: 'Clipboard unavailable',
    };
}
