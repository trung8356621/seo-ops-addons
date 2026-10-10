import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');
const css = readFileSync(new URL('../app/agent-runtime.css', import.meta.url), 'utf8');

function slice(start, end) {
    return widget.slice(widget.indexOf(start), widget.indexOf(end));
}

test('a completed response reveals its vote above the composer without opening history or paused runs', () => {
    assert.equal(widget.includes('const [openReviewRunUlid, setOpenReviewRunUlid] = useState(null)'), true);
    assert.equal(widget.includes('<Vote size={13} />'), true);
    assert.equal(widget.includes('onClick={() => openRoutingReview(version.response)}'), true);
    assert.equal(widget.includes('semantic_score'), false);
    const send = slice('async function onSend()', 'async function onConfirmationAction');
    const rerun = slice('async function onRerun', 'function stageReviewChoice');
    assert.ok(send.indexOf('setOpenReviewRunUlid(null)') < send.indexOf('postJson(sendUrl'));
    assert.ok(send.indexOf("if (data.status === 'paused')") < send.indexOf('revealCompletedReview(data)'));
    assert.ok(rerun.indexOf('setOpenReviewRunUlid(null)') < rerun.indexOf('postJson(rerunUrl'));
    assert.ok(rerun.indexOf("if (data.status === 'paused')") < rerun.indexOf('revealCompletedReview(data)'));
    assert.equal(slice('async function finishStrandedRun', '// Scope change / initial mount effect').includes('revealCompletedReview'), false);
    assert.equal(slice('const loadThread', '// New conversation action').includes('revealCompletedReview'), false);
    assert.equal(slice('className="agent-version-nav"', 'aria-label="Previous response version"').includes('revealCompletedReview'), false);
    assert.equal(widget.includes('useEffect(() => {\n        const runUlid = completedReviewRun'), false);
    const aboveComposer = widget.slice(widget.indexOf('agent-error-banner'), widget.indexOf('className="agent-composer"'));
    assert.equal(aboveComposer.includes('className="agent-routing-review"'), true);
    assert.equal(aboveComposer.includes('scrollIntoView'), false);
    assert.equal(slice('className="agent-message is-assistant"', 'draftIntakeUrl={endpoints.draftIntakeUrl}').includes('className="agent-routing-review"'), false);
    assert.equal(aboveComposer.includes('openAgentChoice === candidate.id'), true);
    assert.equal(aboveComposer.includes('Phương án khác đã được cân nhắc'), false);
    assert.match(aboveComposer, /candidate\.question/);
});

test('confirm records the vote, closes the panel, and activates only that icon', () => {
    const confirm = slice('function confirmRoutingReview', 'const shellClass');
    assert.equal(confirm.includes('queueRoutingReview(runUlid, preferred)'), true);
    assert.equal(confirm.includes('setConfirmedReviews'), true);
    assert.equal(confirm.includes('setOpenReviewRunUlid((current) => (current === runUlid ? null : current))'), true);
    assert.equal(confirm.includes('setReviewSelections'), false);
    assert.equal(widget.includes("className={`agent-message-action-btn agent-vote-btn${reviewMark ? ' is-active' : ''}${panelOpen ? ' is-open' : ''}`}"), true);
    assert.equal(widget.includes('title={reviewMark?.title || \'Đánh giá cách hiểu câu hỏi\'}'), true);
    assert.equal(widget.includes('confirmedReviews[versionRunUlid]'), true);
});

test('saved and alternative choices stay on their response version', () => {
    assert.equal(widget.includes('const [confirmedReviews, setConfirmedReviews] = useState(storedReviewSelections)'), true);
    assert.equal(widget.includes('[openReviewRunUlid]: candidate.id'), true);
    assert.equal(widget.includes("[openReviewRunUlid]: '__none__'"), true);
    const radios = slice('openReviewCandidates.map', 'className="agent-routing-review__confirm"');
    assert.equal(radios.includes('queueRoutingReview'), false);
});

test('switching conversations closes the open review panel', () => {
    assert.equal(slice('const loadThread', '// New conversation action').includes('setOpenReviewRunUlid(null)'), true);
    assert.equal(slice('const onNewConversation', '// Archive conversation action').includes('setOpenReviewRunUlid(null)'), true);
    assert.equal(slice('// Scope change / initial mount effect', 'async function copyText').includes('setOpenReviewRunUlid(null)'), true);
});

test('review choices stay readable in a compact inline panel', () => {
    assert.match(css, /\.agent-routing-review\s*\{[^}]*position:\s*sticky;\s*bottom:\s*0;[^}]*background:\s*#ffffff/);
    assert.match(css, /\.agent-routing-review__choice\s*\{[^}]*color:\s*#0f172a/);
    assert.doesNotMatch(css, /\.agent-routing-review__choice\s*\{[^}]*opacity:/);
    assert.match(css, /@media \(max-width: 640px\)\s*\{[\s\S]*\.agent-routing-review\s*\{[^}]*max-height:\s*36vh/);
    assert.match(css, /\.agent-vote-btn\.is-active/);
});
