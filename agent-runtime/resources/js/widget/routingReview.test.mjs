import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');
const css = readFileSync(new URL('../app/agent-runtime.css', import.meta.url), 'utf8');

function slice(start, end) {
    return widget.slice(widget.indexOf(start), widget.indexOf(end));
}

test('routing review is collapsed until the matching Vote button opens it', () => {
    assert.equal(widget.includes('useState(null)'), true);
    assert.equal(widget.includes('const [openReviewRunUlid, setOpenReviewRunUlid] = useState(null)'), true);
    assert.equal(widget.includes('const panelOpen = openReviewRunUlid === versionRunUlid && versionCandidates.length > 0'), true);
    assert.equal(widget.includes('<Vote size={13} />'), true);
    assert.equal(widget.includes('onClick={() => openRoutingReview(version.response)}'), true);
    assert.equal(widget.includes('ThumbsUp'), false);
    assert.equal(widget.includes('ThumbsDown'), false);
    assert.equal(widget.includes('semantic_score'), false);
    const aboveComposer = widget.slice(widget.indexOf('{error ?'), widget.indexOf('className="agent-composer"'));
    assert.equal(aboveComposer.includes('agent-routing-review'), false);
    const assistant = slice('className="agent-message is-assistant"', 'draftIntakeUrl={endpoints.draftIntakeUrl}');
    assert.equal(assistant.includes('className="agent-routing-review"'), true);
    assert.equal(assistant.includes('id={`routing-review-${versionRunUlid}`}'), true);
    assert.match(assistant, /candidate\.question/);
    assert.match(assistant, /Không câu nào đúng ý tôi/);
    assert.equal(assistant.includes('Phương án khác đã được cân nhắc'), false);
    assert.equal(assistant.includes('agentChoice === candidate.id'), true);
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
    assert.equal(widget.includes('[versionRunUlid]: candidate.id'), true);
    assert.equal(widget.includes("[versionRunUlid]: '__none__'"), true);
    const radios = slice('versionCandidates.map', 'className="agent-routing-review__confirm"');
    assert.equal(radios.includes('queueRoutingReview'), false);
});

test('switching conversations closes the open review panel', () => {
    assert.equal(slice('const loadThread', '// New conversation action').includes('setOpenReviewRunUlid(null)'), true);
    assert.equal(slice('const onNewConversation', '// Archive conversation action').includes('setOpenReviewRunUlid(null)'), true);
    assert.equal(slice('// Scope change / initial mount effect', 'async function copyText').includes('setOpenReviewRunUlid(null)'), true);
});

test('review choices stay readable in a compact inline panel', () => {
    assert.match(css, /\.agent-routing-review\s*\{[^}]*background:\s*#ffffff/);
    assert.doesNotMatch(css, /\.agent-routing-review\s*\{[^}]*position:\s*sticky/);
    assert.match(css, /\.agent-routing-review__choice\s*\{[^}]*color:\s*#0f172a/);
    assert.doesNotMatch(css, /\.agent-routing-review__choice\s*\{[^}]*opacity:/);
    assert.match(css, /@media \(max-width: 640px\)\s*\{[\s\S]*\.agent-routing-review\s*\{[^}]*max-height:\s*36vh/);
    assert.match(css, /\.agent-vote-btn\.is-active/);
});
