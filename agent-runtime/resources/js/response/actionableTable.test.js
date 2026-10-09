import test from 'node:test';
import assert from 'node:assert/strict';
import { articleRef, displayColumns, formatSeoScore, issueCountLabel, rowReasons } from './actionableTable.js';

test('article column keeps extra fields and folds article_ref into the title cell', () => {
    const columns = displayColumns([
        { key: 'n', label: '#' },
        { key: 'title', label: 'Title' },
        { key: 'article_ref', label: 'Article' },
        { key: 'focus_keyword', label: 'Keyword' },
        { key: 'seo_score', label: 'SEO score' },
        { key: 'clicks', label: 'Clicks' },
    ]);
    assert.deepEqual(columns.map((column) => column.key), ['n', 'title', 'focus_keyword', 'seo_score', 'clicks']);
});

test('article_ref stays a column when there is no title column', () => {
    const columns = displayColumns([
        { key: 'article_ref', label: 'Article' },
        { key: 'clicks', label: 'Clicks' },
    ]);
    assert.deepEqual(columns.map((column) => column.key), ['article_ref', 'clicks']);
});

test('reason count uses existing messages and stays empty without reasons', () => {
    const reasons = rowReasons({
        item: { reasons: [' Thiếu meta ', '', ' Ảnh alt trống '] },
    });
    assert.deepEqual(reasons, ['Thiếu meta', 'Ảnh alt trống']);
    assert.equal(issueCountLabel(reasons.length), '2 vấn đề SEO');
    assert.equal(issueCountLabel(rowReasons({ item: {} }).length), '');
    assert.equal(articleRef({ article_ref: 'article:2361' }), 'article:2361');
});

test('seo score keeps existing bands and does not render null as zero', () => {
    assert.deepEqual(formatSeoScore(null), { text: '', tone: 'is-unknown' });
    assert.deepEqual(formatSeoScore(undefined), { text: '', tone: 'is-unknown' });
    assert.equal(formatSeoScore(0).text, '0');
    assert.equal(formatSeoScore(19).tone, 'is-poor');
    assert.equal(formatSeoScore(50).tone, 'is-fair');
    assert.equal(formatSeoScore(70).tone, 'is-good');
});
