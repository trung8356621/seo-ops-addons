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
    assert.equal(widget.includes('title="Copy question" aria-label="Copy question"><Copy'), true);
    assert.equal(widget.includes('copyText(responseToPlainText(version.response))'), true);
    assert.equal(widget.includes('title="Copy answer" aria-label="Copy answer"><Copy'), true);
    assert.equal(widget.includes('title="Rerun" aria-label="Rerun"><RotateCcw'), true);
    assert.equal(clipboard.includes("document.execCommand('copy')"), true);
});

test('Debug is a canonical header switch shared by Send and Rerun', () => {
    const composer = widget.slice(widget.indexOf('<form'), widget.indexOf('</form>'));
    assert.equal(composer.includes('agent-debug-switch'), false);
    assert.equal(widget.includes('className="agent-debug-switch"'), true);
    assert.equal(widget.includes('checked={debugMode}'), true);
    assert.equal(widget.includes('disabled={busy || debugBusy || debugOpen}'), true);
    assert.ok((widget.match(/debug_mode: debugMode/g) || []).length >= 2);
    assert.equal(widget.includes('const [diagnostics, setDiagnostics]'), true);
    assert.equal(widget.includes('const [debugMode, setDebugMode]'), true);
});

test('Diag-only rejected Answer disclosure is collapsed and contains error plus raw completion', () => {
    assert.equal(widget.includes('diagnostics && version.response?.answer_diagnostics'), true);
    assert.equal(widget.includes('<details className="agent-answer-diagnostics">'), true);
    assert.equal(widget.includes('<summary>Answer diagnostics</summary>'), true);
    assert.equal(widget.includes('version.response.answer_diagnostics.parser_error'), true);
    assert.equal(widget.includes('version.response.answer_diagnostics.raw_completion'), true);
});

test('drawer width and processing status use wider flat contracts', () => {
    assert.equal(css.includes('width: clamp(460px, 38vw, 560px);'), true);
    assert.match(css, /article\.agent-processing-status \{[\s\S]*border: 0;[\s\S]*background: transparent;[\s\S]*box-shadow: none;/);
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
