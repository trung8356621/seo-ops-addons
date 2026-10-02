const IDLE_STATE = Object.freeze({ phase: 'idle', requestId: 0, source: null });

export function createFaqGenerationCommandStore({ observe = () => {} } = {}) {
    let state = IDLE_STATE;
    let executor = null;
    let deliveredRequestId = null;
    const listeners = new Set();

    const publish = (next) => {
        state = Object.freeze(next);
        listeners.forEach((listener) => listener());
    };

    const emit = (event, detail = {}) => observe(event, { ...state, ...detail });

    const deliverPendingRequest = () => {
        if (!executor || state.phase !== 'opening' || deliveredRequestId === state.requestId) return;
        deliveredRequestId = state.requestId;
        emit('request delivered');
        try {
            executor({ requestId: state.requestId, source: state.source });
        } catch (error) {
            emit('executor failed', { error });
            publish({ phase: 'idle', requestId: state.requestId, source: null });
        }
    };

    return {
        getState: () => state,
        subscribe(listener) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
        request(source = 'unknown') {
            if (state.phase !== 'idle') return null;
            const requestId = state.requestId + 1;
            deliveredRequestId = null;
            publish({ phase: 'opening', requestId, source });
            emit('request created');
            deliverPendingRequest();
            return requestId;
        },
        registerExecutor(nextExecutor) {
            if (typeof nextExecutor !== 'function') return false;
            executor = nextExecutor;
            emit('executor registered');
            deliverPendingRequest();
            return true;
        },
        unregisterExecutor(currentExecutor) {
            if (executor !== currentExecutor) return false;
            executor = null;
            emit('executor unregistered');
            if (state.phase === 'generating' || state.phase === 'opening') {
                publish({ phase: 'idle', requestId: state.requestId, source: null });
            }
            return true;
        },
        claim(requestId = null) {
            if (state.phase !== 'opening') return null;
            if (requestId !== null && Number(requestId) !== state.requestId) return null;
            publish({ ...state, phase: 'generating' });
            emit('request claimed');
            return state.requestId;
        },
        markPreviewReady(requestId) {
            if (Number(requestId) !== state.requestId || state.phase !== 'generating') return false;
            publish({ ...state, phase: 'preview' });
            emit('preview succeeded');
            return true;
        },
        beginApply() {
            if (state.phase !== 'preview') return null;
            publish({ ...state, phase: 'applying' });
            emit('apply started');
            return state.requestId;
        },
        restorePreview(requestId) {
            if (Number(requestId) !== state.requestId || state.phase !== 'applying') return false;
            publish({ ...state, phase: 'preview' });
            emit('apply failed');
            return true;
        },
        note(event, detail = {}) {
            emit(event, detail);
        },
        finish(requestId, outcome = 'finished') {
            if (Number(requestId) !== state.requestId) return false;
            emit(outcome);
            publish({ phase: 'idle', requestId: state.requestId, source: null });
            return true;
        },
        reset() {
            executor = null;
            deliveredRequestId = null;
            publish(IDLE_STATE);
            emit('reset');
        },
    };
}

export function isFaqGenerationCreateDisabled({ phase, canGenerateFaq, onCreateFaq }) {
    return phase !== 'idle' || !canGenerateFaq || typeof onCreateFaq !== 'function';
}

export function getFaqGenerationCreateDisabledReason({ phase, canGenerateFaq, onCreateFaq, t = (k) => k }) {
    if (phase === 'opening' || phase === 'generating') {
        return t('faq_generate_ai_loading');
    }
    if (phase === 'applying') {
        return t('faq_saving');
    }
    if (!canGenerateFaq) {
        return t('faq_generate_disabled_reason');
    }
    if (typeof onCreateFaq !== 'function') {
        return t('faq_generate_unavailable');
    }
    return '';
}
