import test from 'node:test';
import assert from 'node:assert/strict';
import { readPendingFeedback, queueFeedback } from './feedbackQueue.js';

function storage() {
    const values = new Map();
    return { getItem: (key) => values.get(key) || null, setItem: (key, value) => values.set(key, value) };
}

test('feedback queue replaces a rating and remains bounded', () => {
    const target = storage();
    queueFeedback('run-1', true, target);
    queueFeedback('run-1', false, target);
    for (let index = 2; index <= 105; index += 1) queueFeedback(`run-${index}`, true, target);
    const pending = readPendingFeedback(target);
    assert.equal(pending.length, 100);
    assert.equal(pending.find((item) => item.run_ulid === 'run-1'), undefined);
    assert.equal(pending.at(-1).run_ulid, 'run-105');
});
