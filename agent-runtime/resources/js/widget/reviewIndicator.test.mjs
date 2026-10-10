import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { queueRoutingReview, readReviewSelections } from './feedbackQueue.js';
import { resolveReviewIndicator } from './reviewIndicator.js';

const widget = readFileSync(new URL('./AgentWidget.jsx', import.meta.url), 'utf8');

const keywords = {
    id: 'cand-keywords',
    question: 'Website đang theo dõi những từ khóa nào?',
    selected: true,
};
const articles = {
    id: 'cand-articles',
    question: 'Kho bài viết hiện có những bài nào?',
    selected: false,
};

function response(runUlid, candidates = [keywords, articles]) {
    return {
        run_ulid: runUlid,
        execution: { routing_review: { candidates } },
    };
}

test('confirmed review appears only on the matching response', () => {
    const confirmed = { 'run-a': 'cand-keywords' };
    const markA = resolveReviewIndicator(response('run-a'), confirmed['run-a']);
    const markB = resolveReviewIndicator(response('run-b'), confirmed['run-b']);
    assert.equal(markA.kind, 'agreed');
    assert.equal(markA.title, 'Đã chọn: Website đang theo dõi những từ khóa nào?');
    assert.equal(markB, null);
});

test('an unconfirmed radio choice produces no indicator', () => {
    assert.equal(resolveReviewIndicator(response('run-a'), ''), null);
    assert.equal(resolveReviewIndicator(response('run-a'), undefined), null);
});

test('a different candidate uses the alternative tooltip and hides raw ids', () => {
    const mark = resolveReviewIndicator(response('run-a'), 'cand-articles');
    assert.equal(mark.kind, 'alternative');
    assert.equal(mark.title, 'Đã chọn cách hiểu khác: Kho bài viết hiện có những bài nào?');
    assert.equal(mark.title.includes('cand-articles'), false);
});

test('none of the above uses the explicit status', () => {
    const mark = resolveReviewIndicator(response('run-a'), '__none__');
    assert.equal(mark.kind, 'none');
    assert.equal(mark.title, 'Bạn đã chọn: Không câu nào đúng ý tôi');
});

test('response versions keep independent confirmed indicators', () => {
    const confirmed = { 'run-1': 'cand-keywords', 'run-2': '__none__' };
    const first = resolveReviewIndicator(response('run-1'), confirmed['run-1']);
    const second = resolveReviewIndicator(response('run-2'), confirmed['run-2']);
    assert.equal(first.kind, 'agreed');
    assert.equal(second.kind, 'none');
    assert.equal(resolveReviewIndicator(response('run-3'), confirmed['run-3']), null);
});

test('the response header reads only the confirmed choice for that version', () => {
    assert.equal(widget.includes('confirmedReviews[version.response?.run_ulid]'), true);
    assert.equal(widget.includes('reviewSelections[version.response?.run_ulid]'), false);
    assert.equal(widget.includes('CircleCheck'), true);
    assert.equal(widget.includes('GitCompare'), true);
    assert.equal(widget.includes('CircleQuestionMark'), true);
    assert.equal(widget.includes('agent-review-mark'), true);
    const confirm = widget.slice(widget.indexOf('function confirmRoutingReview'), widget.indexOf('const shellClass'));
    assert.equal(confirm.includes('setConfirmedReviews'), true);
    assert.equal(confirm.includes('setReviewSelections'), false);
});

test('reload restores a saved review without exposing operation ids', () => {
    const storage = new Map();
    const localStorage = {
        getItem: (key) => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
    };
    queueRoutingReview('run-a', 'cand-articles', localStorage);
    const restored = readReviewSelections(localStorage);
    const mark = resolveReviewIndicator(response('run-a'), restored['run-a']);
    assert.equal(restored['run-a'], 'cand-articles');
    assert.equal(mark.title, 'Đã chọn cách hiểu khác: Kho bài viết hiện có những bài nào?');
});
