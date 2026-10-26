import assert from 'node:assert/strict';
import {
    __resetLegacyFaqPendingForTests,
    beginLegacyFaqCommit,
    clearLegacyFaqPending,
    endLegacyFaqCommit,
    getLegacyFaqPending,
    isLegacyFaqCommitInFlight,
    isLegacyFaqPending,
    normalizeLegacyFaqItemsForSnapshot,
    resolveFaqsForLegacyCommit,
    resolvePendingFaqsForContentSave,
    setLegacyFaqPending,
    shouldIgnoreEmptyFaqEditorSync,
} from '../../resources/js/utils/legacyFaqTransfer.js';

__resetLegacyFaqPendingForTests();

const sixFaqs = [
    { question: 'Túi canvas dùng được không?', answer: '<p>Có, dùng tốt.</p>' },
    { question: 'Giặt máy được không?', answer: '<p>Giặt nhẹ.</p>' },
    { question: 'Có Song Ngư không?', answer: '<p>Có họa tiết Song Ngư.</p>' },
    { question: 'Size nào?', answer: '<p>Nhiều size.</p>' },
    { question: 'Có màu be không?', answer: '<p>Có.</p>' },
    { question: 'Ship toàn quốc?', answer: '<p>Ship toàn quốc.</p>' },
];

// --- Pending store wins when panel ref is empty ---
{
    __resetLegacyFaqPendingForTests();
    setLegacyFaqPending({ articleId: 9727, faqs: sixFaqs, sectionId: 'section-faq' });
    const resolved = resolveFaqsForLegacyCommit({
        articleId: 9727,
        panelFaqs: [],
        restoreExtractedFaqs: null,
    });
    assert.equal(resolved.source, 'pending', 'pending store owns transfer');
    assert.equal(resolved.faqs.length, 6, 'detect 6 Q/A');
    assert.equal(shouldIgnoreEmptyFaqEditorSync({ rows: [], articleId: 9727 }), true);
}

// --- Restore backup when pending cleared but extract backup remains ---
{
    __resetLegacyFaqPendingForTests();
    const resolved = resolveFaqsForLegacyCommit({
        articleId: 9727,
        panelFaqs: [],
        restoreExtractedFaqs: sixFaqs,
    });
    assert.equal(resolved.source, 'restore');
    assert.equal(resolved.faqs.length, 6);
}

// --- No pending data → none + clear error path ---
{
    __resetLegacyFaqPendingForTests();
    const resolved = resolveFaqsForLegacyCommit({
        articleId: 9727,
        panelFaqs: [],
        restoreExtractedFaqs: null,
    });
    assert.equal(resolved.source, 'none');
    assert.equal(resolved.faqs.length, 0);
    assert.equal(isLegacyFaqPending(9727), false);
    assert.equal(resolvePendingFaqsForContentSave(9727), null);
}

// --- Double-click guard ---
{
    __resetLegacyFaqPendingForTests();
    assert.equal(beginLegacyFaqCommit(), true);
    assert.equal(isLegacyFaqCommitInFlight(), true);
    assert.equal(beginLegacyFaqCommit(), false, 'second click blocked');
    endLegacyFaqCommit();
    assert.equal(isLegacyFaqCommitInFlight(), false);
    assert.equal(beginLegacyFaqCommit(), true);
    endLegacyFaqCommit();
}

// --- Normalize preserves 6 / no duplicate growth ---
{
    const normalized = normalizeLegacyFaqItemsForSnapshot(sixFaqs);
    assert.equal(normalized.length, 6);
    assert.equal(normalizeLegacyFaqItemsForSnapshot(normalized).length, 6);
}

// --- API failure must keep pending (caller contract) ---
{
    __resetLegacyFaqPendingForTests();
    setLegacyFaqPending({ articleId: 9727, faqs: sixFaqs, sectionId: 's1' });
    // Simulate failed commit: pending untouched.
    assert.ok(getLegacyFaqPending());
    assert.equal(isLegacyFaqPending(9727), true);
    clearLegacyFaqPending();
    assert.equal(getLegacyFaqPending(), null);
}

// --- Open FAQ panel + shortcode scroll helpers ---
{
    const mod = await import('../../resources/js/utils/legacyFaqTransfer.js');
    assert.equal(typeof mod.seedFaqEditorWhenReady, 'function');
    assert.equal(typeof mod.commitLegacyFaqToCanonical, 'function');
    assert.equal(typeof mod.legacyFaqTransferDiag, 'function');
    assert.equal(typeof mod.revealFaqShortcodeAfterTransfer, 'function');
    assert.equal(typeof mod.scrollFaqShortcodeIntoView, 'function');
}

// Dynamic import failure / scroll-to-shortcode contract in source.
{
    const src = await import('node:fs/promises').then((fs) => fs.readFile(
        new URL('../../resources/js/utils/legacyFaqTransfer.js', import.meta.url),
        'utf8',
    ));
    assert.match(src, /legacy_faq_import_failed/);
    assert.match(src, /commit\.import_failed/);
    assert.match(src, /faq_snapshot_conflict/);
    assert.match(src, /seed\.dispatched/);
    assert.match(src, /\.seo-faq-shortcode-block/);
    assert.match(src, /seo-faq-scroll-highlight/);
    // Seed must not scroll — shortcode reveal owns the single scroll.
    assert.match(src, /Does not scroll/);
}

console.log('legacyFaqTransfer.selftest: ok');
