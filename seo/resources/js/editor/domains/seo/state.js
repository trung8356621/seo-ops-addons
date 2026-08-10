import { setOwnerDirty, isOwnerDirty } from '@client-core/ownerDirtyState.js';
import { diagDirty, diagMutationStart, diagMutationEnd } from '@content-addon/editor/domains/editorDiagnostics.js';

/** @type {{ focusKeyword: string, analysis: object|null, seoScore: number|null }} */
const state = {
    focusKeyword: '',
    analysis: null,
    seoScore: null,
};

/** @type {Set<() => void>} */
const listeners = new Set();

/**
 * @param {() => void} listener
 * @returns {() => void}
 */
export function subscribe(listener) {
    listeners.add(listener);
    return () => {
        listeners.delete(listener);
    };
}

function emit() {
    listeners.forEach((listener) => {
        listener();
    });
}

export function getSeoState() {
    return { ...state };
}

export const seoActions = {
    /**
     * @param {Partial<typeof state>} patch
     */
    patch(patch) {
        diagMutationStart('seo', { keys: Object.keys(patch || {}) });
        Object.assign(state, patch || {});
        setOwnerDirty('seo', true);
        diagDirty('seo', true);
        diagMutationEnd('seo', {});
        emit();
    },

    /**
     * Intentional clear of analysis.
     */
    clearAnalysis() {
        seoActions.patch({ analysis: null, seoScore: null });
    },

    markClean() {
        setOwnerDirty('seo', false);
        diagDirty('seo', false);
    },

    isDirty() {
        return isOwnerDirty('seo');
    },

    flush() {
        if (!seoActions.isDirty()) {
            return {};
        }
        return {
            seo_analysis: state.analysis,
            focus_keyword: state.focusKeyword,
        };
    },
};

export const seoApi = {
    adopt(analysis, focusKeyword) {
        seoActions.patch({
            analysis: analysis ?? null,
            focusKeyword: String(focusKeyword ?? ''),
            seoScore: (() => {
                const raw = analysis?.score ?? analysis?.seo_score ?? analysis?.total_score;
                return raw == null || !Number.isFinite(Number(raw)) ? null : Number(raw);
            })(),
        });
    },
};
