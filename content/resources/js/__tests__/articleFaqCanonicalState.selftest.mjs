/**
 * Regression: FAQ content vs FAQ schema must not be conflated in SEO messaging.
 */
import assert from 'node:assert/strict';
import {
    formatFaqSeoViolationMessage,
    resolveFaqSeoViolationCodes,
    selectCanonicalFaqState,
} from '../../../../seo/resources/js/utils/articleFaqCanonicalState.js';

const fiveQuestions = [
    { question: 'Q1?', answer: 'A1' },
    { question: 'Q2?', answer: 'A2' },
    { question: 'Q3?', answer: 'A3' },
    { question: 'Q4?', answer: 'A4' },
    { question: 'Q5?', answer: 'A5' },
];

{
    // Hydrated valid FAQ rows = schema PASS.
    const state = selectCanonicalFaqState({
        faqs: fiveQuestions,
        faqCountHint: 5,
    });
    assert.equal(state.faq_question_count, 5);
    assert.equal(state.has_faq_content, true);
    assert.equal(state.has_faq_schema, true);
    assert.deepEqual(resolveFaqSeoViolationCodes(state), []);
}

{
    // Bootstrap faqCount comes from DB faqs()->count() — treat as schema present
    // while panel rows are still lazy/unhydrated (fixes false "schema missing").
    const state = selectCanonicalFaqState({
        faqs: null,
        faqCountHint: 5,
        html: '<p>no shortcode</p>',
    });
    assert.equal(state.faq_question_count, 5);
    assert.equal(state.has_faq_content, true);
    assert.equal(state.has_faq_schema, true);
    assert.deepEqual(resolveFaqSeoViolationCodes(state), []);
}

{
    // Shortcode/HTML signal without persisted count = content without schema.
    const state = selectCanonicalFaqState({
        faqs: null,
        faqCountHint: 0,
        html: '<p class="omi-faq-placeholder">[omi_faq]</p>',
    });
    assert.equal(state.has_faq_content, true);
    assert.equal(state.has_faq_schema, false);
    assert.deepEqual(resolveFaqSeoViolationCodes(state), ['faq_schema_missing']);
    const message = formatFaqSeoViolationMessage('faq_schema_missing', {
        faq_question_count: 0,
        locale: 'vi',
    });
    assert.match(message, /Đã có nội dung FAQ nhưng chưa có FAQ schema/);
    assert.doesNotMatch(message, /Thiếu dữ liệu FAQ/);
}

{
    const state = selectCanonicalFaqState({
        faqs: [],
        faqCountHint: 0,
        html: '<p>plain</p>',
    });
    assert.equal(state.has_faq_content, false);
    assert.deepEqual(resolveFaqSeoViolationCodes(state), ['faq_missing']);
}

{
    // Known empty [] must ignore stale shortcode (owner-known empty).
    const state = selectCanonicalFaqState({
        faqs: [],
        faqCountHint: 0,
        html: '<p>[omi_faq]</p>',
    });
    assert.equal(state.has_faq_content, false);
    assert.equal(state.has_faq_schema, false);
    assert.deepEqual(resolveFaqSeoViolationCodes(state), ['faq_missing']);
}

console.log('articleFaqCanonicalState.selftest: ok');
