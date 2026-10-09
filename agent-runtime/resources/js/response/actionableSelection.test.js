import test from 'node:test';
import assert from 'node:assert/strict';
import { eligibleRows, intakeItems, isCompleteSuccess, selectAllIds, selectedCount, toggleId } from './actionableSelection.js';

const block = {
    actionable: { action: 'content_project.draft.intake', site_ref: 'site:4' },
    rows: [
        { n: 1, item: { id: 'article:1', type: 'improve', article_ref: 'article:1' } },
        { n: 2, item: { id: 'article:2', type: 'rewrite', article_ref: 'article:2' } },
        { n: 3, title: 'read only' },
    ],
};

test('select all and partial selection stay on stable ids', () => {
    const all = selectAllIds(block.rows);
    assert.deepEqual([...all], ['article:1', 'article:2']);
    const partial = toggleId(all, 'article:2');
    assert.equal(selectedCount(partial, block.rows), 1);
    assert.equal(partial.has('article:1'), true);
    assert.equal(eligibleRows({ type: 'table', rows: block.rows }).length, 0);
});

test('intake sends only selected structured items', () => {
    const items = intakeItems(block.rows, new Set(['article:2']));
    assert.equal(items.length, 1);
    assert.equal(items[0].article_ref, 'article:2');
});

test('partial failure is not complete success', () => {
    assert.equal(isCompleteSuccess({ added: 1, failed: 1, already_in_draft: 0 }), false);
    assert.equal(isCompleteSuccess({ added: 1, failed: 0, already_in_draft: 1 }), true);
});
