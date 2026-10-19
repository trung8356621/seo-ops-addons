/**
 * Outcome of an explicit "Tạo gợi ý liên kết" run.
 * Separate from links already present in the article.
 *
 * @typedef {'idle'|'loading'|'success'|'empty'|'not_ready'|'error'} SuggestionFeedbackPhase
 * @typedef {{ phase: SuggestionFeedbackPhase, count: number, reason: string }} SuggestionFeedback
 */

/**
 * Explicit Generate must call the API when there is no cached internal row.
 * A prior empty/error run must not stick as a silent zero.
 *
 * @param {unknown[]|null|undefined} cachedInternal
 * @param {{ force?: boolean }} [options]
 */
export function shouldRefetchInternalSuggestions(cachedInternal, options = {}) {
    if (options.force === true) {
        return true;
    }
    if (!Array.isArray(cachedInternal)) {
        return true;
    }

    return cachedInternal.length === 0;
}

/**
 * @param {{
 *   httpStatus?: number,
 *   success?: boolean,
 *   status?: string,
 *   reason?: string,
 *   returnedCount?: number,
 *   visibleCount?: number,
 * }} input
 * @returns {SuggestionFeedback}
 */
export function classifyInternalLinkSuggestionResult(input = {}) {
    const httpStatus = Number(input.httpStatus ?? 200) || 0;
    const success = input.success !== false && httpStatus < 400;
    const status = String(input.status ?? '');
    const reason = String(input.reason ?? '');
    const returnedCount = Math.max(0, Number(input.returnedCount) || 0);
    const visibleCount = Math.max(0, Number(input.visibleCount) || 0);

    if (!success) {
        return {
            phase: 'error',
            count: 0,
            reason: 'http_error',
        };
    }

    if (status === 'unavailable' || reason === 'semantic_unavailable' || reason === 'semantic_timeout') {
        return {
            phase: 'error',
            count: 0,
            reason: reason === 'semantic_timeout' ? 'semantic_timeout' : 'semantic_unavailable',
        };
    }

    if (
        reason === 'content_unavailable'
        || reason === 'site_unresolved'
        || reason === 'no_topic_group'
        || reason === 'no_eligible_target'
    ) {
        return {
            phase: 'not_ready',
            count: 0,
            reason,
        };
    }

    if (visibleCount > 0) {
        return {
            phase: 'success',
            count: visibleCount,
            reason: '',
        };
    }

    return {
        phase: 'empty',
        count: 0,
        reason: returnedCount > 0 ? 'not_insertable' : (reason || 'no_match'),
    };
}

/**
 * @param {SuggestionFeedback|null|undefined} result
 */
export function suggestionFeedbackMessageKey(result) {
    const phase = String(result?.phase ?? 'idle');
    const reason = String(result?.reason ?? '');

    if (phase === 'loading') {
        return 'links_suggestions_loading';
    }
    if (phase === 'success') {
        return 'links_suggestions_success';
    }
    if (phase === 'not_ready') {
        if (reason === 'content_unavailable') {
            return 'links_suggestions_not_ready_content';
        }
        if (reason === 'site_unresolved') {
            return 'links_suggestions_not_ready_site';
        }
        if (reason === 'no_eligible_target') {
            return 'links_suggestions_not_ready_target';
        }

        return 'links_suggestions_not_ready_topic';
    }
    if (phase === 'error') {
        if (reason === 'semantic_timeout') {
            return 'links_suggestions_error_timeout';
        }

        return reason === 'semantic_unavailable'
            ? 'links_suggestions_error_semantic'
            : 'links_suggestions_error_http';
    }
    if (reason === 'not_insertable') {
        return 'links_suggestions_filtered';
    }

    return 'links_suggestions_empty_result';
}
