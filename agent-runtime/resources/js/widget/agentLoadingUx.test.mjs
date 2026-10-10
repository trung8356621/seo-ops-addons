import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { rerunHidesTurn, scopeEntryAction, threadLoadIsCurrent } from './agentLoadingState.js';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');
const css = readFileSync(new URL('../app/agent-runtime.css', import.meta.url), 'utf8');

function slice(start, end) {
    return widget.slice(widget.indexOf(start), widget.indexOf(end));
}

test('history click shows a skeleton immediately and ignores stale A/B results', () => {
    const load = slice('const loadThread = useCallback', 'const onNewConversation');
    assert.ok(load.indexOf('setViewingThreadUlid(ulid)') < load.indexOf('await fetch'));
    assert.ok(load.indexOf('setMessages([])') < load.indexOf('await fetch'));
    assert.equal(load.includes('agent-thread-skeleton') || widget.includes('agent-thread-skeleton'), true);
    assert.equal(load.includes('setBusy(true)'), false);
    assert.equal(load.includes('fetchThreads('), false);
    assert.equal(load.includes('signal: controller.signal'), true);
    assert.equal(load.includes('threadLoadCurrent(seq, scopeRef)'), true);
    assert.equal(load.includes('setThreadLoadError'), true);
    assert.equal(widget.includes('Thử lại'), true);
    assert.equal(threadLoadIsCurrent(1, 3, 'site:a', 'site:a'), false);
    assert.equal(threadLoadIsCurrent(3, 3, 'site:a', 'site:b'), false);
    assert.equal(threadLoadIsCurrent(3, 3, 'site:c', 'site:c'), true);
    assert.match(css, /\.agent-thread-skeleton__user[\s\S]*animation: agent-skeleton/);
});

test('rerun hides only that response, keeps versions, and restores them on failure', () => {
    const rerun = slice('async function onRerun', 'const conversationTurns');
    assert.ok(rerun.indexOf('setRerunTargetId(userMessageId)') < rerun.indexOf('postJson(rerunUrl'));
    assert.equal(rerun.includes("setProcessingStatus('Thinking…')"), false);
    assert.equal(rerun.includes('fetchThreads('), false);
    assert.equal(rerun.includes('setActiveThreads'), false);
    assert.equal(rerun.includes("setMessages((current) => [...current,"), true);
    assert.equal(rerun.includes('Number.MAX_SAFE_INTEGER'), true);
    assert.equal(rerun.includes('setRerunTargetId(null)'), true);
    assert.equal(rerun.includes('confirmedReviews'), false);
    assert.equal(widget.includes('data-rerun-placeholder="true"'), true);
    assert.equal(widget.includes('rerunning ?'), true);
    assert.equal(rerunHidesTurn('user-1', 'user-1'), true);
    assert.equal(rerunHidesTurn('user-2', 'user-1'), false);
    assert.equal(widget.includes('container.scrollTop'), true);
    assert.equal(widget.includes('scrollIntoView'), false);
    const paused = rerun.slice(rerun.indexOf("if (data.status === 'paused')"), rerun.indexOf('setMessages((current)'));
    assert.equal(paused.includes('setRerunTargetId(null)'), false);
    assert.equal(paused.includes('setRerunNotice(waitingForManualModel'), true);
});

test('site switch opens Welcome and a late result from the previous site cannot render', () => {
    const effect = slice('// Scope change / initial mount effect', 'async function copyText');
    assert.equal(effect.includes('scopeEntryAction(lastScopeRef.current, currentScopeRef, storedUlid)'), true);
    assert.equal(effect.includes("if (action === 'restore')"), true);
    assert.ok(effect.indexOf("if (action === 'restore')") < effect.indexOf('loadThread(storedUlid, currentScopeRef)'));
    assert.equal(effect.includes('setMessages([])'), true);
    assert.equal(effect.includes('setActiveThreadUlid(null)'), true);
    assert.equal(effect.includes('threadLoadSeq.current += 1'), true);
    assert.equal(scopeEntryAction(null, 'site:1', 'thread-a'), 'restore');
    assert.equal(scopeEntryAction('site:1', 'site:2', 'thread-b'), 'welcome');
    assert.equal(scopeEntryAction('site:1', 'site:1', 'thread-a'), 'ignore');
    assert.equal(scopeEntryAction(null, 'site:1', ''), 'welcome');
    assert.equal(widget.includes('!threadLoadingUlid && !threadLoadError'), true);
    assert.equal(widget.includes('className="agent-welcome agent-empty"'), true);
});
