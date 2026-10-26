import assert from 'node:assert/strict';
import {
    __resetLegacyFaqPendingForTests,
    clearLegacyFaqPending,
    getLegacyFaqPending,
    isLegacyFaqPending,
    normalizeLegacyFaqItemsForSnapshot,
    resolvePendingFaqsForContentSave,
    setLegacyFaqPending,
    shouldIgnoreEmptyFaqEditorSync,
} from '../../resources/js/utils/legacyFaqTransfer.js';

__resetLegacyFaqPendingForTests();

// --- Preserve 6 detected Q/A pairs through snapshot normalize (order + keys) ---
{
    const pairs = [
        { question: 'Túi canvas dùng được không?', answer: '<p>Có, dùng tốt.</p>' },
        { question: 'Giặt máy được không?', answer: '<p>Giặt nhẹ.</p>' },
        { question: 'Có Song Ngư không?', answer: '<p>Có họa tiết Song Ngư.</p>' },
        { question: 'Size nào?', answer: '<p>Nhiều size.</p>' },
        { question: 'Có màu be không?', answer: '<p>Có.</p>' },
        { question: 'Ship toàn quốc?', answer: '<p>Ship toàn quốc.</p>' },
    ];
    assert.equal(pairs.length, 6, 'detect 6 Q/A');
    const normalized = normalizeLegacyFaqItemsForSnapshot(pairs);
    assert.equal(normalized.length, 6);
    assert.equal(normalized[0].question, 'Túi canvas dùng được không?');
    assert.equal(normalized[5].question, 'Ship toàn quốc?');
    assert.equal(normalized[5].sort_order, 5);
    assert.ok(normalized[2].client_key.startsWith('legacy-faq-'));
    // Re-normalize same rows must not invent extras (save-many / no duplicate growth).
    assert.equal(normalizeLegacyFaqItemsForSnapshot(normalized).length, 6);
}

// --- Pending vs empty FAQ editor sync race ---
{
    __resetLegacyFaqPendingForTests();
    const faqs = [
        { question: 'A?', answer: '<p>1</p>' },
        { question: 'B?', answer: '<p>2</p>' },
    ];
    setLegacyFaqPending({ articleId: 9727, faqs, sectionId: 'section-9' });
    assert.equal(isLegacyFaqPending(9727), true);
    assert.equal(shouldIgnoreEmptyFaqEditorSync({ rows: [], articleId: 9727 }), true);
    assert.equal(shouldIgnoreEmptyFaqEditorSync({ rows: faqs, articleId: 9727 }), false);
    assert.equal(shouldIgnoreEmptyFaqEditorSync({ rows: [], articleId: 1 }), false);
    assert.deepEqual(resolvePendingFaqsForContentSave(9727), faqs);
    assert.equal(resolvePendingFaqsForContentSave(1), null);

    clearLegacyFaqPending();
    assert.equal(isLegacyFaqPending(9727), false);
    assert.equal(shouldIgnoreEmptyFaqEditorSync({ rows: [], articleId: 9727 }), false);
    assert.equal(resolvePendingFaqsForContentSave(9727), null);
}

// --- Restore-before-commit: clearing pending restores save semantics ---
{
    __resetLegacyFaqPendingForTests();
    setLegacyFaqPending({
        articleId: 9727,
        faqs: [{ question: 'X?', answer: '<p>y</p>' }],
        sectionId: 's1',
    });
    assert.ok(getLegacyFaqPending());
    clearLegacyFaqPending();
    assert.equal(getLegacyFaqPending(), null);
    assert.equal(shouldIgnoreEmptyFaqEditorSync({ rows: [], articleId: 9727 }), false);
}

// --- Normalize drops empty / preserves order ---
{
    const normalized = normalizeLegacyFaqItemsForSnapshot([
        { question: 'First?', answer: '<p>a</p>' },
        { question: '', answer: '<p>skip</p>' },
        { question: 'Third?', answer: '<p>c</p>' },
        { question: 'Empty answer?', answer: '<p></p>' },
    ]);
    assert.equal(normalized.length, 2);
    assert.equal(normalized[0].question, 'First?');
    assert.equal(normalized[1].question, 'Third?');
    assert.equal(normalized[0].sort_order, 0);
    assert.equal(normalized[1].sort_order, 2);
}

// --- No pending → article without FAQ unaffected ---
{
    __resetLegacyFaqPendingForTests();
    assert.equal(isLegacyFaqPending(), false);
    assert.equal(resolvePendingFaqsForContentSave(999), null);
    assert.equal(shouldIgnoreEmptyFaqEditorSync({ rows: [] }), false);
}

console.log('legacyFaqTransfer.selftest: ok');
