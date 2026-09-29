import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { responseToPlainText } from '../response/responseText.js';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');
const css = readFileSync(new URL('../app/agent-runtime.css', import.meta.url), 'utf8');
const clipboard = readFileSync(new URL('./clipboard.js', import.meta.url), 'utf8');

test('assistant conversation surface is flat while semantic blocks remain available', () => {
    assert.match(css, /article\.is-assistant \{[\s\S]*border: 0;[\s\S]*background: transparent;[\s\S]*box-shadow: none;/);
    assert.equal(css.includes('.agent-warning'), true);
    assert.equal(css.includes('.agent-table-wrap'), true);
    assert.equal(css.includes('.agent-chart'), true);
});

test('user and assistant messages expose lightweight Copy and assistant Rerun', () => {
    assert.equal(widget.includes('onClick={() => copyText(turn.content)}>Copy'), true);
    assert.equal(widget.includes('copyText(responseToPlainText(version.response))'), true);
    assert.equal(widget.includes('onClick={() => onRerun(turn.id)}'), true);
    assert.equal(clipboard.includes("document.execCommand('copy')"), true);
});

test('assistant copy includes canonical visible markdown and table content', () => {
    const text = responseToPlainText({
        message: 'Summary',
        blocks: [
            { type: 'markdown', text: '## Details\n- One' },
            { type: 'table', title: 'Plan', columns: [{ key: 'topic', label: 'Topic' }], rows: [{ topic: 'Buying guide' }] },
        ],
    });
    assert.equal(text, 'Summary\n\n## Details\n- One\n\nPlan\n\nTopic\n\nBuying guide');
});

test('version controls appear only for multiple results and latest is default', () => {
    assert.equal(widget.includes('turn.versions.length > 1 ? ('), true);
    assert.equal(widget.includes('requestedIndex ?? turn.versions.length - 1'), true);
    assert.equal(widget.includes('{versionIndex + 1} / {turn.versions.length}'), true);
    assert.equal(widget.includes('1 / 1'), false);
});

test('version navigation changes local selection without network requests', () => {
    const nav = widget.slice(widget.indexOf('className="agent-version-nav"'), widget.indexOf('</span>', widget.indexOf('className="agent-version-nav"')));
    assert.equal(nav.includes('setSelectedVersions'), true);
    assert.equal(nav.includes('fetch('), false);
    assert.equal(nav.includes('postJson('), false);
});

test('Rerun uses the normal endpoint lifecycle and supports existing Debug interception', () => {
    const rerun = widget.slice(widget.indexOf('async function onRerun'), widget.indexOf('const conversationTurns'));
    assert.equal(rerun.includes("setProcessingStatus('Thinking…')"), true);
    assert.equal(rerun.includes('debug_mode: debugMode'), true);
    assert.equal(rerun.includes("if (data.status === 'paused')"), true);
    assert.equal(rerun.includes('setDebugOpen(true)'), true);
    assert.equal(rerun.includes('Number.MAX_SAFE_INTEGER'), true);
    assert.equal(rerun.includes("role: 'user'"), false);
});
