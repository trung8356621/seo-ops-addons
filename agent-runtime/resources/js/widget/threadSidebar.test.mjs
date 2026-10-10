import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { archiveThreadLocally, prependThread, threadTitleFromMessage } from './threadSidebar.js';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

function slice(start, end) {
    return widget.slice(widget.indexOf(start), widget.indexOf(end));
}

const existing = [
    { ulid: 'older', title: 'Older chat' },
    { ulid: 'newest-before', title: 'Current top' },
];

test('rerun and existing-thread send do not fetch or reorder history', () => {
    const rerun = slice('async function onRerun', 'const conversationTurns');
    const send = slice('async function onSend()', 'async function onConfirmationAction');
    assert.equal(rerun.includes('fetchThreads('), false);
    assert.equal(rerun.includes('setActiveThreads'), false);
    assert.equal(send.includes('fetchThreads('), false);
    assert.equal(prependThread(existing, { ulid: 'older', title: 'touched' }).map((item) => item.ulid).join(','), 'older,newest-before');
});

test('a new conversation is prepended once without moving existing threads', () => {
    const send = slice('async function onSend()', 'async function onConfirmationAction');
    assert.equal(send.includes('prependThread('), true);
    assert.equal(send.includes('threadTitleFromMessage(userMessageText)'), true);
    const next = prependThread(existing, {
        ulid: 'brand-new',
        title: threadTitleFromMessage('  Kho   bài viết  hiện có  '),
        status: 'active',
    });
    assert.deepEqual(next.map((item) => item.ulid), ['brand-new', 'older', 'newest-before']);
    assert.equal(next[0].title, 'Kho bài viết hiện có');
    assert.deepEqual(prependThread(next, next[0]).map((item) => item.ulid), ['brand-new', 'older', 'newest-before']);
});

test('debug, confirmation, and stranded completion do not refetch history', () => {
    assert.equal(slice('async function onApplyDebugResult()', 'async function finishStrandedRun').includes('fetchThreads('), false);
    assert.equal(slice('async function finishStrandedRun', '// Scope change / initial mount effect').includes('fetchThreads('), false);
    assert.equal(slice('async function onConfirmationAction', 'async function onRunTest').includes('fetchThreads('), false);
});

test('archive and delete stay local, while scope changes still fetch once', () => {
    const archive = slice('const onArchiveThread', 'const onDeleteThread');
    const deletion = slice('const onDeleteThread', 'async function onApplyDebugResult');
    const scope = slice('// Scope change / initial mount effect', 'async function copyText');
    const testRun = slice('async function onRunTest', 'function onComposerSubmit');
    assert.equal(archive.includes('fetchThreads('), false);
    assert.equal(archive.includes('archiveThreadLocally('), true);
    assert.equal(deletion.includes('fetchThreads('), false);
    assert.equal(deletion.includes('setActiveThreads(activeSnapshot)'), true);
    assert.equal(scope.includes('fetchThreads(currentScopeRef)'), true);
    assert.equal(testRun.includes('fetchThreads('), false);
    assert.equal(testRun.includes('prependThread('), true);

    const moved = archiveThreadLocally(existing, [{ ulid: 'old-archive', title: 'Stored' }], 'older');
    assert.deepEqual(moved.active.map((item) => item.ulid), ['newest-before']);
    assert.deepEqual(moved.archived.map((item) => item.ulid), ['older', 'old-archive']);
    assert.equal(moved.archived[0].status, 'archived');
});

test('history fetch remains only the scope snapshot', () => {
    const calls = widget.match(/fetchThreads\(/g) || [];
    assert.deepEqual(calls, ['fetchThreads(']);
});
