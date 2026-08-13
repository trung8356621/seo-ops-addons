/**
 * Tool/workspace state for the AI Media sidebar — not article document state.
 */

/** @type {{ prompt: string, mediaType: 'image'|'video', targetBlockId: string|null, source: string|null }|null} */
let workspace = null;

/** @type {Set<(state: typeof workspace) => void>} */
const listeners = new Set();

function emit() {
    listeners.forEach((listener) => {
        try {
            listener(workspace);
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('[editor-ai-media-workspace] listener failed', error);
        }
    });
}

/**
 * @param {object} [detail]
 */
export function pushAiMediaLaunchContext(detail = {}) {
    const payload = detail != null && typeof detail === 'object' ? detail : {};
    const mediaType = payload.mediaType === 'video' ? 'video' : 'image';
    const prompt = String(payload.prompt ?? payload.prefill ?? payload.userBrief ?? '').trim();
    const targetBlockId = String(payload.targetBlockId ?? payload.blockId ?? payload.activeBlockId ?? '').trim() || null;

    workspace = {
        prompt,
        mediaType,
        targetBlockId,
        source: String(payload.source ?? '').trim() || null,
    };
    emit();
    return workspace;
}

export function getAiMediaLaunchContext() {
    return workspace;
}

/**
 * @param {(state: typeof workspace) => void} listener
 * @returns {() => void}
 */
export function subscribeAiMediaLaunchContext(listener) {
    if (typeof listener !== 'function') {
        return () => {};
    }
    listeners.add(listener);
    return () => listeners.delete(listener);
}

/** @internal test helper */
export function __resetAiMediaWorkspaceForTests() {
    workspace = null;
    listeners.clear();
}
