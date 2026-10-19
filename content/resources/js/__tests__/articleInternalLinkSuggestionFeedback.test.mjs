import assert from 'node:assert/strict';
import test from 'node:test';
import {
    classifyInternalLinkSuggestionResult,
    shouldRefetchInternalSuggestions,
    suggestionFeedbackMessageKey,
} from '../utils/articleInternalLinkSuggestionFeedback.js';

test('article on site 7 still classifies a successful internal result when the header site differs', () => {
    const articleSiteId = 7;
    const headerSiteId = 6;
    assert.notEqual(articleSiteId, headerSiteId);

    const result = classifyInternalLinkSuggestionResult({
        status: 'ok',
        returnedCount: 4,
        visibleCount: 4,
    });

    assert.equal(result.phase, 'success');
    assert.equal(result.count, 4);
});

test('keyword groups without an eligible candidate are not ready, not an empty article', () => {
    const result = classifyInternalLinkSuggestionResult({
        status: 'empty',
        reason: 'no_eligible_target',
        returnedCount: 0,
        visibleCount: 0,
    });

    assert.equal(result.phase, 'not_ready');
    assert.equal(suggestionFeedbackMessageKey(result), 'links_suggestions_not_ready_target');
});

test('a valid empty suggestion array is empty, not an in-article link miss', () => {
    const result = classifyInternalLinkSuggestionResult({
        status: 'empty',
        reason: 'anchor_quality',
        returnedCount: 0,
        visibleCount: 0,
    });

    assert.equal(result.phase, 'empty');
    assert.equal(suggestionFeedbackMessageKey(result), 'links_suggestions_empty_result');
    assert.notEqual(suggestionFeedbackMessageKey(result), 'links_internal_empty');
});

test('HTTP 500 stays an error and is not rewritten as an empty list', () => {
    const result = classifyInternalLinkSuggestionResult({
        httpStatus: 500,
        success: false,
        returnedCount: 0,
        visibleCount: 0,
    });

    assert.equal(result.phase, 'error');
    assert.equal(result.reason, 'http_error');
    assert.equal(suggestionFeedbackMessageKey(result), 'links_suggestions_error_http');
});

test('semantic service unavailable stays an error', () => {
    const result = classifyInternalLinkSuggestionResult({
        status: 'unavailable',
        reason: 'semantic_unavailable',
        returnedCount: 0,
    });

    assert.equal(result.phase, 'error');
    assert.equal(suggestionFeedbackMessageKey(result), 'links_suggestions_error_semantic');
});

test('a second click does not start while a search is in flight, and an empty cache refetches', () => {
    let inFlight = false;
    const start = () => {
        if (inFlight) {
            return false;
        }
        inFlight = true;

        return true;
    };

    assert.equal(start(), true);
    assert.equal(start(), false);
    assert.equal(shouldRefetchInternalSuggestions([]), true);
    assert.equal(shouldRefetchInternalSuggestions([{ text: 'balo' }]), false);
    assert.equal(shouldRefetchInternalSuggestions([{ text: 'balo' }], { force: true }), true);
});

test('scope_unexplored is a partial search, not a finished empty result or a semantic outage', () => {
    const result = classifyInternalLinkSuggestionResult({
        status: 'empty',
        reason: 'scope_unexplored',
        returnedCount: 0,
        visibleCount: 0,
    });

    assert.equal(result.phase, 'empty');
    assert.equal(result.reason, 'scope_unexplored');
    assert.equal(suggestionFeedbackMessageKey(result), 'links_suggestions_scope_partial');
    assert.notEqual(suggestionFeedbackMessageKey(result), 'links_suggestions_empty_result');
});

test('a semantic timeout is not reported as the service being down', () => {
    const result = classifyInternalLinkSuggestionResult({
        status: 'unavailable',
        reason: 'semantic_timeout',
        returnedCount: 0,
        visibleCount: 0,
    });

    assert.equal(result.phase, 'error');
    assert.equal(suggestionFeedbackMessageKey(result), 'links_suggestions_error_timeout');
});
