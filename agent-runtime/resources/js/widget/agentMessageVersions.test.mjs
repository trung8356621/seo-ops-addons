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
    assert.equal((widget.match(/aria-label="Copy answer"/g) || []).length, 1);
    assert.equal(widget.includes('title="Rerun" aria-label="Rerun"><RotateCcw'), true);
    assert.equal(clipboard.includes("document.execCommand('copy')"), true);
});

test('internal model pauses remain ephemeral and never become assistant versions', () => {
    const confirmation = widget.slice(widget.indexOf('async function onConfirmationAction'), widget.indexOf('async function onRunTest'));
    assert.equal(confirmation.includes("if (data.status === 'paused')"), true);
    assert.equal(confirmation.includes('return [];'), true);
    assert.equal(confirmation.includes('Đã xác nhận. Đang chờ kết quả Answer.'), false);
});

test('Developer mode is a canonical header segmented tab control shared by Send and Rerun', () => {
    const composer = widget.slice(widget.indexOf('<form'), widget.indexOf('</form>'));
    assert.equal(composer.includes('agent-dev-select'), false);
    assert.equal(composer.includes('agent-diagnostics'), false);
    assert.equal(widget.includes('className="agent-dev-select"'), false);
    assert.equal(widget.includes('className="agent-dev-tabs"'), true);
    assert.equal(widget.includes('role="tablist"'), true);
    assert.equal(widget.includes('role="tab"'), true);
    assert.equal(widget.includes("aria-selected={developerMode === 'normal'}"), true);
    assert.equal(widget.includes("aria-selected={developerMode === 'debug'}"), true);
    assert.equal(widget.includes("aria-selected={developerMode === 'diag'}"), true);
    assert.equal(widget.includes('disabled={isDevModeDisabled}'), true);
    assert.ok((widget.match(/debug_mode: isDebugMode/g) || []).length >= 2);
    assert.ok((widget.match(/diagnostics: isDiagnostics/g) || []).length >= 2);
    assert.equal(widget.includes('getStoredDeveloperMode(hostContext.appKey)'), true);
    assert.equal(widget.includes('setStoredDeveloperMode(hostContext.appKey, newMode)'), true);
});

test('Persisted diagnostics render even if current mode != diag, supporting Decision & Answer stages', () => {
    assert.equal(widget.includes('const modelDiag = version?.response?.model_diagnostics;'), true);
    assert.equal(widget.includes('const hasDiagnostics = Boolean(decisionDiag || finalAnswerDiag);'), true);
    assert.equal(widget.includes('<details className="agent-diagnostics-disclosure">'), true);
    assert.equal(widget.includes('<summary>Diagnostics</summary>'), true);
    assert.equal(widget.includes('<h4>Decision</h4>'), true);
    assert.equal(widget.includes('<h4>Answer</h4>'), true);
});

test('drawer width and processing status use wider flat contracts', () => {
    assert.equal(css.includes('width: 90vw;'), true);
    assert.equal(css.includes('@media (max-width: 768px)'), true);
    assert.match(css, /article\.agent-processing-status \{[\s\S]*border: 0;[\s\S]*background: transparent;[\s\S]*box-shadow: none;/);
});

test('drawer header has no visible close button while existing controls remain', () => {
    assert.equal(widget.includes('agent-drawer-close-btn'), false);
    assert.equal(widget.includes('aria-label="Close Agent drawer"'), false);
    assert.equal(widget.includes('className="agent-dev-tabs"'), true);
    assert.equal(widget.includes('className="agent-dev-select"'), false);
    assert.equal(widget.includes('title="Copy question"'), true);
    assert.equal(widget.includes('title="Copy answer"'), true);
    assert.equal(widget.includes('title="Rerun"'), true);
    assert.equal(widget.includes('agent-version-nav'), true);
});

test('desktop history sidebar is docked on right, lists active chats by default, separates archived, and supports archive/delete', () => {
    assert.equal(widget.includes('className="agent-history-sidebar"'), true);
    assert.equal(widget.includes('className="agent-history-tabs"'), true);
    assert.equal(widget.includes("historyTab === 'chats'"), true);
    assert.equal(widget.includes("historyTab === 'archived'"), true);
    assert.equal(widget.includes('onArchiveThread(t.ulid)'), true);
    assert.equal(widget.includes('onDeleteThread(t.ulid)'), true);
    assert.equal(widget.includes('isViewingArchived'), true);
    assert.equal(widget.includes('This conversation is archived and read-only.'), true);
    assert.equal(widget.includes('No conversations for this site yet.'), true);
    assert.equal(widget.includes('No archived conversations for this site.'), true);
});

test('thread delete removes locally first, rolls back on failure, and does not refetch history', () => {
    const deletion = widget.slice(widget.indexOf('const onDeleteThread'), widget.indexOf('async function onApplyDebugResult'));
    assert.equal(deletion.includes('setActiveThreads((current) => current.filter'), true);
    assert.equal(deletion.includes('setArchivedThreads((current) => current.filter'), true);
    assert.equal(deletion.includes('setActiveThreads(activeSnapshot)'), true);
    assert.equal(deletion.includes('setArchivedThreads(archivedSnapshot)'), true);
    assert.equal(deletion.includes('fetchThreads('), false);
});

test('GSC availability uses muted styling without weakening real warnings', () => {
    const response = readFileSync(new URL('../response/ResponseBlocks.jsx', import.meta.url), 'utf8');
    assert.equal(response.includes('resolveWarningClass'), true);
    assert.match(css, /\.agent-availability-note \{[\s\S]*color: #64748b;[\s\S]*font-size: 12px;/);
    assert.equal(css.includes('.agent-warning'), true);
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
    assert.equal(widget.includes('requestedIndex ?? (turn.versions.length > 0 ? turn.versions.length - 1 : 0)'), true);
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
    assert.equal(rerun.includes('debug_mode: isDebugMode'), true);
    assert.equal(rerun.includes('diagnostics: isDiagnostics'), true);
    assert.equal(rerun.includes("if (data.status === 'paused')"), true);
    assert.equal(rerun.includes('setDebugOpen(true)'), true);
    assert.equal(rerun.includes('Number.MAX_SAFE_INTEGER'), true);
    assert.equal(rerun.includes("role: 'user'"), false);
});
