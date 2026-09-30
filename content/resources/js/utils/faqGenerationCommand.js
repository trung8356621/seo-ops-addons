import { useSyncExternalStore } from 'react';
import { createFaqGenerationCommandStore } from './faqGenerationCommandStore';

const observe = (event, detail) => {
    if (!globalThis.__SEO_FAQ_GENERATION_DEBUG__) return;
    console.debug('[faq-generation]', event, detail);
};
const store = createFaqGenerationCommandStore({ observe });

export const getFaqGenerationState = store.getState;
export const subscribeFaqGeneration = store.subscribe;

export const useFaqGenerationState = () => useSyncExternalStore(
    subscribeFaqGeneration,
    getFaqGenerationState,
    getFaqGenerationState,
);

export function requestFaqGeneration(source = 'unknown') {
    return store.request(source);
}

export function registerFaqGenerationExecutor(executor) {
    return store.registerExecutor(executor);
}

export function unregisterFaqGenerationExecutor(executor) {
    return store.unregisterExecutor(executor);
}

export function claimFaqGeneration(requestId = null) {
    return store.claim(requestId);
}

export function markFaqGenerationPreviewReady(requestId) {
    return store.markPreviewReady(requestId);
}

export function beginFaqApply() {
    return store.beginApply();
}

export function restoreFaqGenerationPreview(requestId) {
    return store.restorePreview(requestId);
}

export function noteFaqGenerationEvent(event, detail = {}) {
    store.note(event, detail);
}

export function finishFaqGeneration(requestId, outcome = 'finished') {
    return store.finish(requestId, outcome);
}

export function resetFaqGenerationForTests() {
    store.reset();
}
