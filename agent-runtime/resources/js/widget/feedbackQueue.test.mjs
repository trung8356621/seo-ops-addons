import test from 'node:test';
import assert from 'node:assert/strict';
import { flushFeedback, readPendingFeedback, readReviewSelections, queueRoutingReview } from './feedbackQueue.js';

function storage() {
    const values = new Map();
    return { getItem: (key) => values.get(key) || null, setItem: (key, value) => values.set(key, value) };
}

test('candidate review replaces a choice for the same response without duplicating', () => {
    const target = storage();
    queueRoutingReview('run-1', 'candidate-b', target);
    queueRoutingReview('run-1', 'candidate-a', target);
    assert.deepEqual(readPendingFeedback(target).map(({ run_ulid, preferred_candidate_id, none_of_above }) => ({
        run_ulid, preferred_candidate_id, none_of_above,
    })), [{ run_ulid: 'run-1', preferred_candidate_id: 'candidate-a', none_of_above: false }]);
    assert.equal(readReviewSelections(target)['run-1'], 'candidate-a');
});

test('none of the above is explicit and different responses remain independent', () => {
    const target = storage();
    queueRoutingReview('run-1', null, target);
    queueRoutingReview('run-2', 'candidate-a', target);
    const pending = readPendingFeedback(target);
    assert.equal(pending[0].none_of_above, true);
    assert.equal(pending[0].preferred_candidate_id, null);
    assert.equal(pending[1].run_ulid, 'run-2');
    assert.deepEqual(readReviewSelections(target), { 'run-1': '__none__', 'run-2': 'candidate-a' });
});

test('failed delivery leaves candidate review queued for the next session', async () => {
    const target = storage();
    queueRoutingReview('run-1', 'candidate-a', target);
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => { throw new Error('offline'); };
    try {
        await assert.rejects(() => flushFeedback('/feedback', 'csrf', target));
        assert.equal(readPendingFeedback(target).length, 1);
    } finally {
        globalThis.fetch = originalFetch;
    }
});
