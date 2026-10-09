import assert from 'node:assert/strict';
import test from 'node:test';
import { draftAfterSuggestion, moveQuestion, toggleWelcomeModule, userQuestionsPayload, WELCOME_MODULES } from './welcomeQuestions.js';

test('accordion keeps a single module open', () => {
    assert.equal(toggleWelcomeModule('', 'seo_audit'), 'seo_audit');
    assert.equal(toggleWelcomeModule('seo_audit', 'seo_audit'), '');
    assert.equal(toggleWelcomeModule('seo_audit', 'gsc'), 'gsc');
});

test('questions stay inside their module and system rows are not saved', () => {
    assert.deepEqual(WELCOME_MODULES.map((module) => module.id), [
        'seo_audit', 'keywords', 'content_projects', 'gsc', 'articles',
    ]);
    assert.equal(WELCOME_MODULES.find((module) => module.id === 'seo_audit').tone, 'red');
    assert.equal(WELCOME_MODULES.find((module) => module.id === 'keywords').tone, 'blue');
    assert.equal(WELCOME_MODULES.find((module) => module.id === 'gsc').tone, 'green');
    const payload = userQuestionsPayload([
        { id: 'seo_audit', questions: [{ id: 'seo_audit.worst', text: 'System', system: true }, { id: 'u1', text: 'Mine' }] },
    ]);
    assert.deepEqual(payload.seo_audit, [{ id: 'u1', text: 'Mine' }]);
});

test('suggestion fills an empty composer and leaves an existing draft', () => {
    assert.equal(draftAfterSuggestion('', 'Những bài nào có điểm SEO thấp?'), 'Những bài nào có điểm SEO thấp?');
    assert.equal(draftAfterSuggestion('đang viết', 'Câu gợi ý'), 'đang viết');
    assert.equal(draftAfterSuggestion('', 'Câu gợi ý', true), '');
});

test('reorder does not drop questions', () => {
    const moved = moveQuestion([{ id: 'a' }, { id: 'b' }, { id: 'c' }], 0, 1);
    assert.deepEqual(moved.map((item) => item.id), ['b', 'a', 'c']);
});
