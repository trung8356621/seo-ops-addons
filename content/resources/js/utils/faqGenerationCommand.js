import { useSyncExternalStore } from 'react';

let state = Object.freeze({ phase: 'idle', requestId: 0, source: null });
const listeners = new Set();

const publish = (next) => {
    state = Object.freeze(next);
    listeners.forEach((listener) => listener());
};

export const getFaqGenerationState = () => state;
export const subscribeFaqGeneration = (listener) => {
    listeners.add(listener);
    return () => listeners.delete(listener);
};

export const useFaqGenerationState = () => useSyncExternalStore(
    subscribeFaqGeneration,
    getFaqGenerationState,
    getFaqGenerationState,
);

export function requestFaqGeneration(source = 'unknown') {
    if (state.phase !== 'idle') return null;
    const requestId = state.requestId + 1;
    publish({ phase: 'opening', requestId, source });
    window.setTimeout(() => {
        window.dispatchEvent(new CustomEvent('article-faq-generation-requested', {
            detail: { requestId, source },
        }));
    }, 0);
    return requestId;
}

export function claimFaqGeneration(requestId = null) {
    if (state.phase !== 'opening') return null;
    if (requestId !== null && Number(requestId) !== state.requestId) return null;
    publish({ ...state, phase: 'generating' });
    return state.requestId;
}

export function markFaqGenerationApplying(requestId) {
    if (Number(requestId) !== state.requestId || state.phase !== 'generating') return false;
    publish({ ...state, phase: 'applying' });
    return true;
}

export function beginFaqApply(source = 'faq-panel-apply') {
    if (state.phase !== 'idle') return null;
    const requestId = state.requestId + 1;
    publish({ phase: 'applying', requestId, source });
    return requestId;
}

export function finishFaqGeneration(requestId) {
    if (Number(requestId) !== state.requestId) return false;
    publish({ phase: 'idle', requestId: state.requestId, source: null });
    return true;
}

export function resetFaqGenerationForTests() {
    publish({ phase: 'idle', requestId: 0, source: null });
}
