import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');
const modal = readFileSync(new URL('./ModelDebugModal.jsx', import.meta.url), 'utf8');
const clipboard = readFileSync(new URL('./clipboard.js', import.meta.url), 'utf8');

test('header uses the canonical Dev mode selector and normal Send carries debug_mode', () => {
    assert.match(widget, /<select[\s\S]*className="agent-dev-select"[\s\S]*value=\{developerMode\}/);
    assert.equal(widget.includes('className="agent-dev-mode-label"'), true);
    assert.equal(widget.includes('agent-model-debug-btn'), false);
    assert.equal(widget.includes('startModelDebug'), false);
    assert.equal(widget.includes('debug_mode: isDebugMode'), true);
});

test('a paused production response opens the current intercepted model call', () => {
    assert.match(widget, /if \(data\.status === 'paused'\) \{[\s\S]*setDebugRunUlid\(data\.run_ulid \|\| ''\);[\s\S]*setDebugCall\(data\.model_call \|\| null\);[\s\S]*setDebugOpen\(true\);/);
    assert.equal(modal.includes("String(modelCall.key || 'model').toUpperCase()"), true);
    assert.equal(modal.includes("value={modelCall.full_prompt || ''}"), true);
});

test('Apply resumes the same run with only run_ulid and the manual completion', () => {
    assert.match(widget, /postJson\(endpoints\.modelDebugApplyUrl, csrf, \{\s*run_ulid: debugRunUlid,\s*manual_result: debugManualResult,\s*\}\)/);
    assert.equal(widget.includes('raw_decision:'), false);
    assert.equal(widget.includes("stage: 'decision'"), false);
});

test('Apply closes while resuming and reopens automatically at another model call', () => {
    const apply = widget.slice(widget.indexOf('async function onApplyDebugResult()'), widget.indexOf('// Scope change / initial mount effect'));
    assert.ok(apply.indexOf('setDebugOpen(false);') < apply.indexOf('postJson('));
    assert.match(apply, /if \(data\.status === 'paused'\) \{[\s\S]*setDebugCall\(data\.model_call \|\| null\);[\s\S]*setDebugOpen\(true\);/);
});

test('completed Apply appends the normal assistant response and resets debug state', () => {
    const apply = widget.slice(widget.indexOf('async function onApplyDebugResult()'), widget.indexOf('// Scope change / initial mount effect'));
    assert.match(apply, /setMessages\(\(current\) => \[\.\.\.current, \{[\s\S]*role: 'assistant',[\s\S]*response: data,/);
    assert.equal(apply.includes('resetDebugState();'), true);
    assert.equal(apply.includes('setDebugOpen(true);'), true);
});

test('new conversation and scope changes clear stale paused state', () => {
    const reset = widget.slice(widget.indexOf('const resetDebugState'), widget.indexOf('// New conversation action'));
    assert.equal(reset.includes("setDebugRunUlid('');"), true);
    assert.equal(reset.includes('setDebugCall(null);'), true);
    assert.equal(reset.includes("setDebugManualResult('');"), true);

    const newConversation = widget.slice(widget.indexOf('const onNewConversation'), widget.indexOf('async function onApplyDebugResult'));
    assert.equal(newConversation.includes('resetDebugState();'), true);
    assert.equal(newConversation.includes('setDebugOpen(false);'), true);

    const scopeEffect = widget.slice(widget.indexOf('// Scope change / initial mount effect'), widget.indexOf('async function copyText'));
    assert.equal(scopeEffect.includes('resetDebugState();'), true);
    assert.equal(scopeEffect.includes('setDebugOpen(false);'), true);
});

test('copy uses the exact full prompt and reports success only after copying', () => {
    assert.equal(modal.includes("await copyPlainText(modelCall.full_prompt || '');"), true);
    const copyStart = modal.indexOf('async function copyPrompt()');
    const copyPrompt = modal.slice(copyStart, modal.indexOf('    return (', copyStart));
    assert.ok(copyPrompt.indexOf('await copyPlainText') < copyPrompt.indexOf('setCopied(true)'));
    assert.match(copyPrompt, /catch \{[\s\S]*setCopyError\(/);
    assert.equal(modal.includes("copied ? 'Copied' : 'Copy full prompt'"), true);
});

test('clipboard helper falls back to an exact temporary textarea copy', () => {
    assert.equal(clipboard.includes('navigator?.clipboard?.writeText'), true);
    assert.equal(clipboard.includes('textarea.value = value;'), true);
    assert.equal(clipboard.includes("document.execCommand('copy')"), true);
    assert.equal(clipboard.includes('textarea.setSelectionRange(0, value.length);'), true);
    assert.equal(clipboard.includes('textarea.remove();'), true);
});

test('retired 0.9.22 simulator UI is absent', () => {
    assert.equal(widget.includes('/model-debug/start'), false);
    assert.equal(modal.includes('Preview Only'), false);
    assert.equal(modal.includes('RetrievalTraceView'), false);
    assert.equal(modal.includes('title="DECISION"'), false);
    assert.equal(modal.includes('title="ANSWER"'), false);
});

test('Send shows ephemeral Thinking and both normal completion and error clear it', () => {
    const send = widget.slice(widget.indexOf('async function onSend()'), widget.indexOf('const shellClass'));
    assert.ok(send.indexOf("setProcessingStatus('Thinking…');") < send.indexOf('postJson(sendUrl'));
    assert.match(send, /setMessages\(\(current\) => \[\.\.\.current, \{[\s\S]*role: 'assistant',[\s\S]*setProcessingStatus\(null\);/);
    assert.match(send, /catch \(caught\) \{[\s\S]*setError\(caught\.message\);\s*setProcessingStatus\(null\);/);
});

test('debug pauses show manual waiting status and Apply restores Thinking while resuming', () => {
    assert.equal(widget.includes('function waitingForManualModel(modelCall)'), true);
    const apply = widget.slice(widget.indexOf('async function onApplyDebugResult()'), widget.indexOf('// Scope change / initial mount effect'));
    assert.ok(apply.indexOf('setDebugOpen(false);') < apply.indexOf("setProcessingStatus('Thinking…');"));
    assert.ok(apply.indexOf("setProcessingStatus('Thinking…');") < apply.indexOf('postJson('));
    assert.match(apply, /if \(data\.status === 'paused'\) \{[\s\S]*setProcessingStatus\(waitingForManualModel\(data\.model_call\)\);[\s\S]*setDebugOpen\(true\);/);
    assert.equal(apply.includes('resetDebugState();'), true);
});

test('processing status is frontend-only and lifecycle resets clear it', () => {
    assert.match(widget, /\{processingStatus \? \([\s\S]*role="status"[\s\S]*\{processingStatus\}/);
    assert.equal(widget.includes("role: 'status'"), false);
    assert.equal(widget.includes("content: processingStatus"), false);

    const reset = widget.slice(widget.indexOf('const resetDebugState'), widget.indexOf('// New conversation action'));
    assert.equal(reset.includes('setProcessingStatus(null);'), true);
    const scopeEffect = widget.slice(widget.indexOf('// Scope change / initial mount effect'), widget.indexOf('async function copyText'));
    assert.equal(scopeEffect.includes('resetDebugState();'), true);
});
