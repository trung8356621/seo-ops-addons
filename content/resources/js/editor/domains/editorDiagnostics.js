/**
 * Dev-only editor diagnostics — detect mutation/poll/listener loops.
 * Enable: window.__SEO_APP_DEBUG__ = true OR localStorage seo_editor_debug=1
 */

const MAX = 400;
/** @type {Array<{t:number,type:string,owner?:string,detail?:unknown}>} */
const ring = [];
/** @type {Set<string>} */
const listeners = new Set();
/** @type {Map<string, number>} */
const polls = new Map();

function enabled() {
    if (typeof window === 'undefined') return false;
    if (window.__SEO_APP_DEBUG__ === true) return true;
    try {
        return localStorage.getItem('seo_editor_debug') === '1';
    } catch {
        return false;
    }
}

/**
 * @param {string} type
 * @param {object} [payload]
 */
export function diag(type, payload = {}) {
    if (!enabled()) return;
    const entry = { t: Date.now(), type, ...payload };
    ring.push(entry);
    if (ring.length > MAX) ring.shift();
    if (typeof console !== 'undefined' && console.debug) {
        console.debug('[editor-diag]', type, payload);
    }
}

export function diagMutationStart(owner, detail) {
    diag('mutation:start', { owner, detail });
}

export function diagMutationEnd(owner, detail) {
    diag('mutation:end', { owner, detail });
}

export function diagApi(owner, endpoint, detail) {
    diag('api', { owner, endpoint, detail });
}

export function diagDirty(owner, dirty) {
    diag('dirty', { owner, dirty });
}

export function diagSnapshotRefresh(source, detail) {
    diag('snapshot:refresh', { owner: source, detail });
}

export function diagListenerRegister(id) {
    listeners.add(id);
    diag('listener:register', { owner: id, detail: { total: listeners.size } });
}

export function diagListenerUnregister(id) {
    listeners.delete(id);
    diag('listener:unregister', { owner: id, detail: { total: listeners.size } });
}

export function diagPollStart(id) {
    polls.set(id, (polls.get(id) || 0) + 1);
    diag('poll:start', { owner: id, detail: { active: polls.size } });
}

export function diagPollStop(id) {
    polls.delete(id);
    diag('poll:stop', { owner: id, detail: { active: polls.size } });
}

export function getDiagnosticsSnapshot() {
    return {
        events: [...ring],
        listeners: [...listeners],
        polls: [...polls.keys()],
    };
}

export function clearDiagnostics() {
    ring.length = 0;
    listeners.clear();
    polls.clear();
}

if (typeof window !== 'undefined') {
    window.__seoEditorDiagnostics = {
        get: getDiagnosticsSnapshot,
        clear: clearDiagnostics,
        diag,
    };
}
